<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\StatusProgramacaoSemanal;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CriarRevisaoProgramacaoSemanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_revisao_com_mesmas_atividades_recapturadas_ao_vivo(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $original = (new RegistrarComprometimentoSemanal)->execute(
            $obra, $inicioSemana->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        (new FecharProgramacaoSemanal)->execute($original);

        // Cronograma mudou depois do fechamento — a revisão deve capturar
        // a data NOVA, não repetir a congelada da v1.
        $atividade->update(['data_termino' => $inicioSemana->copy()->addDays(4)]);

        $revisao = (new CriarRevisaoProgramacaoSemanal)->execute($original->fresh());

        $this->assertSame(2, $revisao->versao);
        $this->assertSame($original->id, $revisao->revisao_de_id);
        $this->assertSame(StatusProgramacaoSemanal::Aberta, $revisao->status);

        $item = ProgramacaoSemanalItem::where('programacao_semanal_id', $revisao->id)
            ->where('atividade_id', $atividade->id)
            ->firstOrFail();

        $this->assertSame(OrigemProgramacaoSemanalItem::Revisao, $item->origem);
        $this->assertTrue($inicioSemana->copy()->addDays(4)->isSameDay($item->data_termino_congelado));

        // A original permanece intocada no histórico.
        $itemOriginal = ProgramacaoSemanalItem::where('programacao_semanal_id', $original->id)->firstOrFail();
        $this->assertTrue($inicioSemana->copy()->addDays(2)->isSameDay($itemOriginal->data_termino_congelado));
    }

    public function test_nao_permite_revisar_programacao_ainda_aberta(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );

        $this->expectException(RuntimeException::class);
        (new CriarRevisaoProgramacaoSemanal)->execute($header);
    }

    public function test_nao_permite_revisar_versao_ja_superada(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $v1 = (new RegistrarComprometimentoSemanal)->execute(
            $obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        (new FecharProgramacaoSemanal)->execute($v1);
        (new CriarRevisaoProgramacaoSemanal)->execute($v1->fresh());

        $this->expectException(RuntimeException::class);
        (new CriarRevisaoProgramacaoSemanal)->execute($v1->fresh());
    }
}
