<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportDesvio;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\ImpactoRestricoesGerador;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa F — Decisões Prioritárias: MESMA fonte de dado de
 * topRiscos()/impactoRestricoes() (o snapshot já congelado pela Etapa C2,
 * App\Models\ReportDesvioRestricao), agregada em tempo de leitura com um
 * filtro e uma ordenação diferentes de topRiscos() — nunca lê Restricao
 * ao vivo, nenhuma persistência nova. Nunca afirma qual decisão deve ser
 * tomada, só lista situações que exigem atenção gerencial.
 */
class ReportDecisoesPrioritariasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    /** Âncora fixa pra "próxima semana" — periodo_referencia = início desta semana. */
    private Carbon $periodoReferencia;
    private Carbon $inicioProximaSemana;
    private Carbon $fimProximaSemana;
    private Carbon $dentroDaJanela;
    private Carbon $foraDaJanela;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->periodoReferencia = Carbon::today()->startOfWeek();
        $this->inicioProximaSemana = $this->periodoReferencia->copy()->addWeek();
        $this->fimProximaSemana = $this->inicioProximaSemana->copy()->endOfWeek();
        $this->dentroDaJanela = $this->inicioProximaSemana->copy()->addDays(2);
        $this->foraDaJanela = $this->fimProximaSemana->copy()->addDays(30);
    }

    private function usuarioComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    private function criarImportacao(): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function criarReport(CronogramaImportacao $importacao): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'periodo_referencia' => $this->periodoReferencia,
            'data_status' => '2026-06-15',
            'status' => StatusReport::Rascunho->value,
        ]);
    }

    private function criarPacote(): PacoteTrabalho
    {
        return PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
    }

    private function criarAtividade(PacoteTrabalho $pacote): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
        ]);
    }

    private function criarCurva(Report $report, ?PacoteTrabalho $pacote = null): ReportCurva
    {
        return ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacote?->id,
        ]);
    }

    private function criarDesvio(ReportCurva $curva, ?PacoteTrabalho $pacote, string $titulo): ReportDesvio
    {
        return $curva->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacote?->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => $titulo,
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 40.0,
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
            'ordem' => 0,
        ]);
    }

    /**
     * Default deliberadamente "neutro" (não vencida, não bloqueante, não
     * risco alto, prazo fora da janela) — cada teste sobrescreve só o(s)
     * campo(s) relevante(s) pro cenário, pra nunca depender dos defaults
     * aleatórios da factory (bloqueante=true por padrão, P×I randômico).
     */
    private function criarRestricao(Atividade $atividade, array $overrides = []): Restricao
    {
        return Restricao::factory()->create(array_merge([
            'tenant_id' => $atividade->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => false,
            'probabilidade' => 1,
            'impacto' => 1,
            'prazo_limite' => $this->foraDaJanela->toDateString(),
        ], $overrides));
    }

    /** Mesmo padrão da C2/D — roda o gerador dentro de TenantContext::actingAs(). */
    private function gerarImpacto(Report $report): void
    {
        TenantContext::actingAs($this->tenant, function () use ($report) {
            app(ImpactoRestricoesGerador::class)->gerar($report->fresh(['curvas.desvios', 'curvas.pacoteTrabalho']));
        });
    }

    // =========================================================================
    // 1. Vencida fora da janela entra
    // =========================================================================

    public function test_vencida_fora_da_janela_entra(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, [
            'descricao' => 'Restrição vencida fora da janela',
            'prazo_limite' => now()->subDays(5)->toDateString(),
        ]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertCount(1, $decisoes);
        $this->assertSame('Restrição vencida fora da janela', $decisoes[0]['descricao']);
    }

    // =========================================================================
    // 2. Bloqueante fora da janela entra
    // =========================================================================

    public function test_bloqueante_fora_da_janela_entra(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, [
            'descricao' => 'Restrição bloqueante fora da janela',
            'bloqueante' => true,
        ]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertCount(1, $decisoes);
        $this->assertSame('Restrição bloqueante fora da janela', $decisoes[0]['descricao']);
    }

    // =========================================================================
    // 3. Risco alto entra
    // =========================================================================

    public function test_risco_alto_entra(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, [
            'descricao' => 'Restrição de risco alto',
            'probabilidade' => 9,
            'impacto' => 9,
        ]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertCount(1, $decisoes);
        $this->assertSame('Restrição de risco alto', $decisoes[0]['descricao']);
    }

    // =========================================================================
    // 4. Item dentro da próxima semana entra
    // =========================================================================

    public function test_item_dentro_da_proxima_semana_entra(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, [
            'descricao' => 'Prazo dentro da próxima semana',
            'prazo_limite' => $this->dentroDaJanela->toDateString(),
        ]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertCount(1, $decisoes);
        $this->assertSame('Prazo dentro da próxima semana', $decisoes[0]['descricao']);
    }

    // =========================================================================
    // 5. Item fora da janela, não vencido, não bloqueante, não crítico NÃO entra
    // =========================================================================

    public function test_item_neutro_fora_da_janela_nao_entra(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, [
            'descricao' => 'Restrição neutra fora da janela',
        ]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertSame([], $decisoes);
    }

    // =========================================================================
    // 6. Ordenação: vencida -> bloqueante -> risco alto -> prazo
    // =========================================================================

    public function test_ordenacao_respeita_vencida_bloqueante_risco_prazo(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);

        $this->criarRestricao($atividade, [
            'descricao' => 'Item risco alto',
            'probabilidade' => 9, 'impacto' => 9,
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Item bloqueante',
            'bloqueante' => true,
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Item vencido',
            'prazo_limite' => now()->subDays(3)->toDateString(),
        ]);

        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertSame([
            'Item vencido',
            'Item bloqueante',
            'Item risco alto',
        ], array_column($decisoes, 'descricao'));
    }

    // =========================================================================
    // 7. Prazo mais próximo vence empate (mesmo tier: bloqueante)
    // =========================================================================

    public function test_prazo_mais_proximo_vence_empate_no_mesmo_tier(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);

        $this->criarRestricao($atividade, [
            'descricao' => 'Bloqueante prazo distante',
            'bloqueante' => true,
            'prazo_limite' => now()->addDays(40)->toDateString(),
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Bloqueante prazo próximo',
            'bloqueante' => true,
            'prazo_limite' => now()->addDays(5)->toDateString(),
        ]);

        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertSame([
            'Bloqueante prazo próximo',
            'Bloqueante prazo distante',
        ], array_column($decisoes, 'descricao'));
    }

    // =========================================================================
    // 8. Prazo nulo fica por último (mesmo tier: bloqueante)
    // =========================================================================

    public function test_prazo_nulo_fica_por_ultimo_no_mesmo_tier(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);

        $this->criarRestricao($atividade, [
            'descricao' => 'Bloqueante sem prazo',
            'bloqueante' => true,
            'prazo_limite' => null,
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Bloqueante com prazo',
            'bloqueante' => true,
            'prazo_limite' => now()->addDays(10)->toDateString(),
        ]);

        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertSame([
            'Bloqueante com prazo',
            'Bloqueante sem prazo',
        ], array_column($decisoes, 'descricao'));
    }

    // =========================================================================
    // 9. Top 3
    // =========================================================================

    public function test_limita_a_top_3(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);

        for ($i = 1; $i <= 4; $i++) {
            $this->criarRestricao($atividade, [
                'descricao' => "Vencida {$i}",
                'prazo_limite' => now()->subDays($i)->toDateString(),
            ]);
        }

        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertCount(3, $decisoes);
    }

    // =========================================================================
    // 10. Menos de 3 itens — mostra só os existentes
    // =========================================================================

    public function test_menos_de_3_itens_mostra_apenas_os_existentes(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);

        $this->criarRestricao($atividade, [
            'descricao' => 'Única situação elegível',
            'bloqueante' => true,
        ]);

        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertCount(1, $decisoes);
    }

    // =========================================================================
    // 11. Ausência de itens elegíveis não renderiza a seção
    // =========================================================================

    public function test_ausencia_de_itens_elegiveis_nao_renderiza_secao(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, ['descricao' => 'Neutra, nunca aparece']);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame([], $component->instance()->decisoesPrioritarias);
        $component->assertDontSee('Decisões Prioritárias');
    }

    // =========================================================================
    // 12. Report anterior à C2 (sem snapshot) não quebra
    // =========================================================================

    public function test_report_sem_snapshot_nao_quebra_e_nao_renderiza(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, ['bloqueante' => true]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        // NUNCA chama o gerador — simula report criado antes da Etapa C2.
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame([], $component->instance()->decisoesPrioritarias);
        $component->assertDontSee('Decisões Prioritárias');
    }

    // =========================================================================
    // 13. Múltiplos itens do mesmo pacote
    // =========================================================================

    public function test_multiplos_itens_do_mesmo_pacote(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, [
            'descricao' => 'Situação 1 do mesmo pacote',
            'bloqueante' => true,
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Situação 2 do mesmo pacote',
            'probabilidade' => 9, 'impacto' => 9,
        ]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->decisoesPrioritarias;

        $this->assertCount(2, $decisoes);
        $this->assertSame('Pacote Único', $decisoes[0]['pacote_titulo']);
        $this->assertSame('Pacote Único', $decisoes[1]['pacote_titulo']);
    }

    // =========================================================================
    // 14. Linguagem nunca causal nem prescritiva
    // =========================================================================

    public function test_interface_nunca_e_causal_nem_prescritiva(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, [
            'descricao' => 'Atraso na liberação de projeto executivo',
            'bloqueante' => true,
        ]);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Decisões Prioritárias')
            ->assertSee('Situações que exigem atenção gerencial')
            ->assertSee('Atraso na liberação de projeto executivo')
            ->assertDontSee('causado por')
            ->assertDontSee('devido a')
            ->assertDontSee('consequência de')
            ->assertDontSee('causa raiz')
            ->assertDontSee('decisão necessária')
            ->assertDontSee('ação recomendada')
            ->assertDontSee('resolve o problema');
    }

    // =========================================================================
    // 15. Regressão — topRiscos/impactoRestricoes/resumoExecutivo/causasDoDesvio/hhExpostaPorAtraso
    // =========================================================================

    public function test_top_riscos_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, ['descricao' => 'Restrição de regressão de topRiscos']);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $topRiscos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->topRiscos;

        $this->assertCount(1, $topRiscos);
        $this->assertSame('Restrição de regressão de topRiscos', $topRiscos[0]['descricao']);
    }

    public function test_impacto_restricoes_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, ['descricao' => 'Restrição de regressão']);
        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $impacto = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->impactoRestricoes;

        $this->assertSame(1, $impacto[$desvio->id]['total_abertas']);
    }

    public function test_resumo_executivo_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resumo = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->resumoExecutivo;

        $this->assertTrue($resumo['tem_dado']);
    }

    public function test_causas_do_desvio_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $causas = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->causasDoDesvio;

        $this->assertIsArray($causas);
    }

    public function test_hh_exposta_por_atraso_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, 'Pacote Único');

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $hhExposta = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->hhExpostaPorAtraso;

        $this->assertIsArray($hhExposta);
    }
}
