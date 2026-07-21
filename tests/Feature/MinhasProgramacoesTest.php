<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MinhasProgramacoesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::radar.programacoes', ['obra' => $this->obra]);
    }

    public function test_lista_a_versao_vigente_de_cada_semana_e_calcula_aderencia(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividadeConcluida = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
        ]);
        $atividadeAberta = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
        ]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, $inicioSemana->toDateString(),
            collect([$atividadeConcluida, $atividadeAberta]), OrigemProgramacaoSemanalItem::Manual
        );
        $atividadeConcluida->update(['status' => StatusAtividade::Concluido->value]);

        $lista = $this->componente()->instance()->programacoes;

        $this->assertCount(1, $lista);
        $prog = $lista->first();
        $this->assertSame($header->id, $prog->id);
        $this->assertSame(50.0, $prog->aderencia());
    }

    public function test_apos_revisao_lista_mostra_a_versao_mais_recente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $v1 = (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        (new FecharProgramacaoSemanal)->execute($v1);
        $v2 = (new CriarRevisaoProgramacaoSemanal)->execute($v1->fresh());

        $lista = $this->componente()->instance()->programacoes;

        $this->assertCount(1, $lista);
        $this->assertSame($v2->id, $lista->first()->id);
        $this->assertSame(2, $lista->first()->versao);
    }

    public function test_fechar_programacao_pela_tela_de_listagem(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );

        $this->componente()->call('fecharProgramacao', $header->id);

        $this->assertTrue($header->fresh()->estaFechada());
    }

    public function test_criar_revisao_pela_tela_de_listagem(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        (new FecharProgramacaoSemanal)->execute($header);

        $this->componente()->call('criarRevisao', $header->id);

        $this->assertSame(1, $header->fresh()->revisoes()->count());
    }

    public function test_usuario_de_outro_tenant_nao_ve_programacoes_desta_obra(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::Engenheiro->value);

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, Carbon::now()->startOfWeek()->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );

        $this->actingAs($outroUser);

        $lista = Livewire::test('pages::radar.programacoes', ['obra' => $outraObra])->instance()->programacoes;

        $this->assertCount(0, $lista);
    }
}
