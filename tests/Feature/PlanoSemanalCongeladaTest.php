<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanal;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Uma semana só vira "congelada" (conjunto comprometido travado, ao invés
 * da consulta ao vivo por sobreposição de datas) quando: (1) já existe uma
 * ProgramacaoSemanal pra ela E (2) ela já é uma semana PASSADA — a semana
 * corrente nunca é tratada como congelada mesmo já tendo comprometimentos,
 * pra permitir acompanhamento ao vivo durante a semana em curso.
 */
class PlanoSemanalCongeladaTest extends TestCase
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
        return Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra]);
    }

    public function test_semana_passada_sem_header_usa_consulta_ao_vivo(): void
    {
        $semanaPassada = Carbon::now()->startOfWeek()->subWeeks(2);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Da Semana Passada',
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(2),
        ]);

        $componente = $this->componente()->set('semanaInicio', $semanaPassada->toDateString());

        $this->assertFalse($componente->instance()->semanaEstaCongelada);
        $componente->assertSee('Atividade Da Semana Passada');
        $componente->assertDontSee('congelada');
    }

    public function test_semana_passada_com_header_mostra_conjunto_congelado_mesmo_apos_mudanca_de_datas(): void
    {
        $semanaPassada = Carbon::now()->startOfWeek()->subWeeks(2);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fundacao Bloco A',
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(2),
        ]);

        // Comprometido enquanto a semana ainda estava "corrente" (sem
        // header prévio, então o guard de semana-congelada não bloqueia).
        $this->componente()
            ->set('semanaInicio', $semanaPassada->toDateString())
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        // Simula uma reimportação de cronograma que empurrou a atividade
        // pra outra janela — o conjunto congelado não deve se importar.
        $atividade->update([
            'inicio_planejado' => $semanaPassada->copy()->addWeeks(4),
            'data_termino' => $semanaPassada->copy()->addWeeks(4)->addDays(2),
        ]);

        $componente = $this->componente()->set('semanaInicio', $semanaPassada->toDateString());

        $this->assertTrue($componente->instance()->semanaEstaCongelada);
        $componente->assertSee('Fundacao Bloco A');
        $componente->assertSee('congelada');
    }

    public function test_ppc_de_semana_congelada_permanece_estavel_apos_reimportacao_simulada(): void
    {
        $semanaPassada = Carbon::now()->startOfWeek()->subWeeks(2);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(2),
        ]);

        $this->componente()
            ->set('semanaInicio', $semanaPassada->toDateString())
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        // "Realizado": a atividade foi de fato concluída (estado ao vivo,
        // não congelado — é exatamente o lado sendo comparado).
        $atividade->update(['status' => StatusAtividade::Concluido->value]);

        // Simula reimportação trazendo uma atividade NOVA que também cai
        // dentro da mesma janela — não deve inflar o denominador do PPC
        // de uma semana já fechada.
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(1),
        ]);

        $ppc = $this->componente()
            ->set('semanaInicio', $semanaPassada->toDateString())
            ->instance()->ppc;

        $this->assertSame(1, $ppc['total']);
        $this->assertSame(1, $ppc['concluidas']);
    }

    public function test_semana_atual_com_header_nao_e_tratada_como_congelada(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $componente = $this->componente()
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        $this->assertFalse($componente->instance()->semanaEstaCongelada);
    }

    public function test_semana_futura_nunca_e_tratada_como_congelada(): void
    {
        $semanaFutura = Carbon::now()->startOfWeek()->addWeeks(3);

        ProgramacaoSemanal::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'semana_inicio' => $semanaFutura->toDateString(),
            'semana_fim' => $semanaFutura->copy()->endOfWeek()->toDateString(),
            'congelada_em' => now(),
            'criado_por' => $this->user->id,
        ]);

        $componente = $this->componente()->set('semanaInicio', $semanaFutura->toDateString());

        $this->assertFalse($componente->instance()->semanaEstaCongelada);
    }

    public function test_acoes_de_edicao_ficam_bloqueadas_numa_semana_congelada(): void
    {
        $semanaPassada = Carbon::now()->startOfWeek()->subWeeks(2);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(2),
        ]);

        (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, $semanaPassada->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        $atividade->update(['status' => StatusAtividade::Comprometido->value]);

        $outraPlanejada = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(1),
        ]);

        $componente = $this->componente()->set('semanaInicio', $semanaPassada->toDateString());
        $this->assertTrue($componente->instance()->semanaEstaCongelada);

        $componente->set('selecionadas', [$outraPlanejada->id])
            ->call('comprometerSelecionadas')
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });
        $this->assertSame(StatusAtividade::Planejado, $outraPlanejada->fresh()->status);

        $componente->call('marcarConcluida', $atividade->id)
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });
        $this->assertSame(StatusAtividade::Comprometido, $atividade->fresh()->status);

        $componente->set('naoConcluindoId', $atividade->id)
            ->set('descricaoCausa', 'Falta de material')
            ->call('confirmarNaoConcluido')
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });
        $this->assertSame(StatusAtividade::Comprometido, $atividade->fresh()->status);
    }

    /**
     * Decisão do usuário: fechar explicitamente ("Gerar Programação") abre
     * a EXCEÇÃO de marcar concluída/não concluído mesmo numa semana
     * passada travada por `semanaEstaCongelada` — diferente da trava total
     * de uma semana nunca fechada (regressão coberta acima).
     */
    public function test_semana_fechada_permite_marcar_concluida_mesmo_sendo_semana_passada(): void
    {
        $semanaPassada = Carbon::now()->startOfWeek()->subWeeks(2);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(2),
        ]);

        $header = (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, $semanaPassada->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        $atividade->update(['status' => StatusAtividade::Comprometido->value]);
        (new FecharProgramacaoSemanal)->execute($header);

        $componente = $this->componente()->set('semanaInicio', $semanaPassada->toDateString());
        $this->assertTrue($componente->instance()->semanaEstaCongelada);
        $this->assertTrue($componente->instance()->semanaEstaFechada);

        $componente->call('marcarConcluida', $atividade->id);

        $this->assertSame(StatusAtividade::Concluido, $atividade->fresh()->status);
    }
}
