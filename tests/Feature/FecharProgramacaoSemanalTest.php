<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\StatusProgramacaoSemanal;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanal;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FecharProgramacaoSemanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_fecha_uma_programacao_aberta_com_itens(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );

        $fechada = (new FecharProgramacaoSemanal)->execute($header);

        $this->assertSame(StatusProgramacaoSemanal::Fechada, $fechada->status);
        $this->assertNotNull($fechada->fechada_em);
        $this->assertTrue($fechada->estaFechada());
    }

    public function test_nao_permite_fechar_programacao_ja_fechada(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        (new FecharProgramacaoSemanal)->execute($header);

        $this->expectException(RuntimeException::class);
        (new FecharProgramacaoSemanal)->execute($header->fresh());
    }

    public function test_nao_permite_fechar_programacao_sem_nenhum_item(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $header = ProgramacaoSemanal::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'semana_inicio' => Carbon::now()->startOfWeek()->toDateString(),
            'semana_fim' => Carbon::now()->endOfWeek()->toDateString(),
            'congelada_em' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        (new FecharProgramacaoSemanal)->execute($header);
    }
}
