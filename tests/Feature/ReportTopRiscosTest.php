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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa D — Top Riscos da Obra: agrega, em tempo de leitura, os
 * snapshots já congelados pela Etapa C2 (App\Models\ReportDesvioRestricao)
 * em TODAS as curvas/linhas do Report, e seleciona os 3 riscos mais
 * urgentes. Nenhuma persistência nova — leitura pura sobre dado já
 * imutável desde a C2. Nunca lê Restricao ao vivo.
 */
class ReportTopRiscosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
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
            'data_status' => '2026-06-15',
            'status' => StatusReport::Rascunho->value,
        ]);
    }

    private function criarPacote(?PacoteTrabalho $parent = null): PacoteTrabalho
    {
        return PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $parent?->id,
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

    private function criarDesvio(ReportCurva $curva, ?PacoteTrabalho $pacote, bool $ehNivelPai, string $titulo): ReportDesvio
    {
        return $curva->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacote?->id,
            'eh_nivel_pai' => $ehNivelPai,
            'titulo_exibicao' => $titulo,
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 40.0,
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
            'ordem' => $ehNivelPai ? 0 : 1,
        ]);
    }

    private function criarRestricao(Atividade $atividade, array $overrides = []): Restricao
    {
        return Restricao::factory()->create(array_merge([
            'tenant_id' => $atividade->tenant_id,
            'atividade_id' => $atividade->id,
        ], $overrides));
    }

    /** Mesmo padrão da C2 — roda o gerador dentro de TenantContext::actingAs(). */
    private function gerarImpacto(Report $report): void
    {
        TenantContext::actingAs($this->tenant, function () use ($report) {
            app(ImpactoRestricoesGerador::class)->gerar($report->fresh(['curvas.desvios', 'curvas.pacoteTrabalho']));
        });
    }

    // =========================================================================
    // 1. Agregação entre todas as curvas do Report
    // =========================================================================

    public function test_agregacao_cruza_todas_as_curvas_do_report(): void
    {
        $report = $this->criarReport($this->criarImportacao());

        $pacoteA = $this->criarPacote();
        $atividadeA = $this->criarAtividade($pacoteA);
        $this->criarRestricao($atividadeA, ['descricao' => 'Risco do pacote A']);
        $curvaA = $this->criarCurva($report, $pacoteA);
        $this->criarDesvio($curvaA, $pacoteA, true, 'Pacote A');

        $pacoteB = $this->criarPacote();
        $atividadeB = $this->criarAtividade($pacoteB);
        $this->criarRestricao($atividadeB, ['descricao' => 'Risco do pacote B']);
        $curvaB = $this->criarCurva($report, $pacoteB);
        $this->criarDesvio($curvaB, $pacoteB, true, 'Pacote B');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $topRiscos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->topRiscos;

        $this->assertCount(2, $topRiscos);
        $descricoes = array_column($topRiscos, 'descricao');
        $this->assertContains('Risco do pacote A', $descricoes);
        $this->assertContains('Risco do pacote B', $descricoes);

        $pacotes = array_column($topRiscos, 'pacote_titulo');
        $this->assertContains('Pacote A', $pacotes);
        $this->assertContains('Pacote B', $pacotes);
    }

    // =========================================================================
    // 2. Top 3 respeitando ordenação vencida -> P×I -> prazo
    // =========================================================================

    public function test_top3_respeita_ordenacao_vencida_risco_prazo_entre_multiplos_desvios(): void
    {
        $report = $this->criarReport($this->criarImportacao());

        $pacoteA = $this->criarPacote();
        $atividadeA = $this->criarAtividade($pacoteA);
        $curvaA = $this->criarCurva($report, $pacoteA);
        $this->criarDesvio($curvaA, $pacoteA, true, 'Pacote A');

        $pacoteB = $this->criarPacote();
        $atividadeB = $this->criarAtividade($pacoteB);
        $curvaB = $this->criarCurva($report, $pacoteB);
        $this->criarDesvio($curvaB, $pacoteB, true, 'Pacote B');

        // 4 restrições no total (> 3, testa que só as 3 mais urgentes sobrevivem).
        $this->criarRestricao($atividadeA, [
            'descricao' => 'Vencida no pacote A',
            'probabilidade' => 1, 'impacto' => 1,
            'prazo_limite' => now()->subDays(5)->toDateString(),
        ]);
        $this->criarRestricao($atividadeB, [
            'descricao' => 'Critica sem vencer no pacote B',
            'probabilidade' => 9, 'impacto' => 9,
            'prazo_limite' => now()->addDays(20)->toDateString(),
        ]);
        $this->criarRestricao($atividadeA, [
            'descricao' => 'Normal prazo proximo no pacote A',
            'probabilidade' => 1, 'impacto' => 1,
            'prazo_limite' => now()->addDays(2)->toDateString(),
        ]);
        $this->criarRestricao($atividadeB, [
            'descricao' => 'Normal prazo distante no pacote B',
            'probabilidade' => 1, 'impacto' => 1,
            'prazo_limite' => now()->addDays(30)->toDateString(),
        ]);

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $topRiscos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->topRiscos;

        $this->assertCount(3, $topRiscos);
        $this->assertSame([
            'Vencida no pacote A',
            'Critica sem vencer no pacote B',
            'Normal prazo proximo no pacote A',
        ], array_column($topRiscos, 'descricao'));
    }

    // =========================================================================
    // 3. Report sem ReportDesvioRestricao (pré-C2) não quebra e não renderiza
    // =========================================================================

    public function test_report_sem_snapshot_nao_quebra_e_nao_renderiza_bloco(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        // NUNCA chama o gerador — simula report criado antes da Etapa C2.
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame([], $component->instance()->topRiscos);
        $component->assertDontSee('Top Riscos da Obra');
    }

    // =========================================================================
    // 4. Snapshot existente mas sem restrições abertas não renderiza o bloco
    // =========================================================================

    public function test_snapshot_sem_restricoes_abertas_nao_renderiza_bloco(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote); // sem nenhuma restrição
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame([], $component->instance()->topRiscos);
        $component->assertDontSee('Top Riscos da Obra');
    }

    // =========================================================================
    // 5. Múltiplos riscos do mesmo pacote são tratados corretamente
    // =========================================================================

    public function test_multiplos_riscos_do_mesmo_pacote_sao_tratados_corretamente(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, ['descricao' => 'Risco 1 do mesmo pacote']);
        $this->criarRestricao($atividade, ['descricao' => 'Risco 2 do mesmo pacote']);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, true, 'Pacote Único');

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $topRiscos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->topRiscos;

        $this->assertCount(2, $topRiscos);
        $this->assertSame('Pacote Único', $topRiscos[0]['pacote_titulo']);
        $this->assertSame('Pacote Único', $topRiscos[1]['pacote_titulo']);
        $descricoes = array_column($topRiscos, 'descricao');
        $this->assertContains('Risco 1 do mesmo pacote', $descricoes);
        $this->assertContains('Risco 2 do mesmo pacote', $descricoes);
    }

    // =========================================================================
    // 6. Linguagem nunca sugere causalidade
    // =========================================================================

    public function test_interface_nunca_sugere_causalidade(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, ['descricao' => 'Atraso na liberação de projeto executivo']);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Top Riscos da Obra')
            ->assertSee('Atraso na liberação de projeto executivo')
            ->assertDontSee('causado por')
            ->assertDontSee('devido a')
            ->assertDontSee('risco responsável pelo atraso')
            ->assertDontSee('consequência de')
            ->assertDontSee('causa raiz');
    }

    // =========================================================================
    // 7/8/9/10. Regressão — resumoExecutivo/causasDoDesvio/hhExpostaPorAtraso/impactoRestricoes
    // =========================================================================

    public function test_resumo_executivo_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote);
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, true, $pacote->nome);

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
        $this->criarDesvio($curva, $pacote, true, $pacote->nome);

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
        $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $hhExposta = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->hhExpostaPorAtraso;

        $this->assertIsArray($hhExposta);
    }

    public function test_impacto_restricoes_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarRestricao($atividade, ['descricao' => 'Restrição de regressão']);
        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $impacto = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->impactoRestricoes;

        $this->assertSame(1, $impacto[$desvio->id]['total_abertas']);
    }
}
