<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Actions\ProgramacaoSemanal\SalvarRealizadoProgramacaoSemanalItem;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ProgramacaoSemanalHhRealizadoTest extends TestCase
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

    private function criarItem(float $workHoras = 100.0): ProgramacaoSemanalItem
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'work_horas' => $workHoras,
        ]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, Carbon::now()->startOfWeek()->toDateString(),
            collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );

        return $header->itens()->first();
    }

    // ---- Model: percentualRealizado() ----

    public function test_percentual_realizado_e_null_sem_hh_lancado(): void
    {
        $item = $this->criarItem();

        $this->assertNull($item->percentualRealizado());
    }

    public function test_percentual_realizado_calcula_sobre_work_horas(): void
    {
        $item = $this->criarItem(workHoras: 100.0);
        $item->update(['hh_realizado' => 25.0]);

        $this->assertSame(25.0, $item->fresh()->percentualRealizado());
    }

    public function test_percentual_realizado_e_null_sem_work_horas_cadastrado(): void
    {
        $item = $this->criarItem(workHoras: 0);
        $item->update(['hh_realizado' => 10.0]);

        $this->assertNull($item->fresh()->percentualRealizado());
    }

    // ---- Action: SalvarRealizadoProgramacaoSemanalItem ----

    public function test_action_salva_hh_realizado_com_autor_e_data(): void
    {
        $item = $this->criarItem();

        $item = (new SalvarRealizadoProgramacaoSemanalItem)->execute($item, 40.0, $this->user->id);

        $this->assertSame('40.00', $item->fresh()->hh_realizado);
        $this->assertSame($this->user->id, $item->fresh()->realizado_por);
        $this->assertNotNull($item->fresh()->realizado_em);
    }

    public function test_action_limpar_hh_realizado_reseta_autor_e_data(): void
    {
        $item = $this->criarItem();
        (new SalvarRealizadoProgramacaoSemanalItem)->execute($item, 40.0, $this->user->id);

        $item = (new SalvarRealizadoProgramacaoSemanalItem)->execute($item->fresh(), null, $this->user->id);

        $this->assertNull($item->fresh()->hh_realizado);
        $this->assertNull($item->fresh()->realizado_por);
        $this->assertNull($item->fresh()->realizado_em);
    }

    public function test_action_rejeita_hh_negativo(): void
    {
        $item = $this->criarItem();

        $this->expectException(InvalidArgumentException::class);
        (new SalvarRealizadoProgramacaoSemanalItem)->execute($item, -5.0, $this->user->id);
    }

    public function test_action_rejeita_hh_acima_do_total(): void
    {
        $item = $this->criarItem(workHoras: 100.0);

        $this->expectException(InvalidArgumentException::class);
        (new SalvarRealizadoProgramacaoSemanalItem)->execute($item, 150.0, $this->user->id);
    }

    public function test_action_rejeita_lancamento_em_programacao_fechada(): void
    {
        $item = $this->criarItem();
        (new FecharProgramacaoSemanal)->execute($item->programacaoSemanal);

        $this->expectException(RuntimeException::class);
        (new SalvarRealizadoProgramacaoSemanalItem)->execute($item->fresh(), 10.0, $this->user->id);
    }

    public function test_action_nao_altera_horas_previstas_congeladas(): void
    {
        $item = $this->criarItem();
        $previstoOriginal = $item->horas_previstas_congeladas;

        (new SalvarRealizadoProgramacaoSemanalItem)->execute($item, 10.0, $this->user->id);

        $this->assertEquals($previstoOriginal, $item->fresh()->horas_previstas_congeladas);
    }

    // ---- Componente Livewire: pages::radar.programacoes ----

    private function componente()
    {
        return Livewire::test('pages::radar.programacoes', ['obra' => $this->obra]);
    }

    public function test_abrir_detalhe_carrega_itens_e_popula_formulario(): void
    {
        $item = $this->criarItem();
        $item->update(['hh_realizado' => 12.5]);

        $componente = $this->componente()->call('abrirDetalhe', $item->programacao_semanal_id);

        $componente->assertSet('programacaoDetalheId', $item->programacao_semanal_id);
        $this->assertSame('12.50', $componente->instance()->hhRealizadoForm[$item->id]);
        $componente->assertDispatched('abrir-modal-itens-programacao');
    }

    public function test_fechar_detalhe_limpa_estado(): void
    {
        $item = $this->criarItem();

        $componente = $this->componente()
            ->call('abrirDetalhe', $item->programacao_semanal_id)
            ->call('fecharDetalhe');

        $componente->assertSet('programacaoDetalheId', null);
        $this->assertSame([], $componente->instance()->hhRealizadoForm);
    }

    public function test_salvar_realizado_pelo_componente_persiste_valor(): void
    {
        $item = $this->criarItem(workHoras: 100.0);

        $componente = $this->componente()->call('abrirDetalhe', $item->programacao_semanal_id);
        $componente->set("hhRealizadoForm.{$item->id}", '30,5');
        $componente->call('salvarRealizado', $item->id);

        $this->assertSame('30.50', $item->fresh()->hh_realizado);
        $this->assertSame($this->user->id, $item->fresh()->realizado_por);
    }

    public function test_salvar_realizado_acima_do_total_mostra_erro_e_nao_salva(): void
    {
        $item = $this->criarItem(workHoras: 100.0);

        $componente = $this->componente()->call('abrirDetalhe', $item->programacao_semanal_id);
        $componente->set("hhRealizadoForm.{$item->id}", '999');
        $componente->call('salvarRealizado', $item->id);

        $this->assertNotEmpty($componente->instance()->erroRealizado[$item->id] ?? null);
        $this->assertNull($item->fresh()->hh_realizado);
    }

    public function test_usuario_de_outro_tenant_nao_acessa_itens_via_componente(): void
    {
        $item = $this->criarItem();

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::Engenheiro->value);

        $this->actingAs($outroUser);

        $componente = Livewire::test('pages::radar.programacoes', ['obra' => $outraObra])
            ->call('abrirDetalhe', $item->programacao_semanal_id);

        $this->assertCount(0, $componente->instance()->itensDaProgramacaoDetalhe);
    }
}
