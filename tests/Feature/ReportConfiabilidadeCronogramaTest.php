<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\Score\FaixaScore;
use App\Support\HealthCheck\Score\ScoreCalculator;
use App\Support\HealthCheck\Score\ScoreResultado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa A — "Confiabilidade do Cronograma" no Report: leitura do
 * Health Check/Score já persistidos da importação de origem
 * (Report->cronogramaImportacao->healthCheck->scoreResultado()), todas
 * relações já existentes antes desta etapa — nada é recalculado aqui.
 */
class ReportConfiabilidadeCronogramaTest extends TestCase
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

    private function criarReport(CronogramaImportacao $importacao, StatusReport $status = StatusReport::Rascunho): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'status' => $status->value,
        ]);
    }

    private function criarHealthCheckComScore(
        CronogramaImportacao $importacao,
        int $score,
        int $totalCriticos,
        int $totalAltos,
        int $totalOcorrencias,
    ): CronogramaImportacaoHealthCheck {
        $scoreResultado = new ScoreResultado(
            score: $score,
            faixa: FaixaScore::paraScore($score),
            cobertura: 100,
            porDimensao: [],
            mapaAcoes: [],
            potencialRecuperavel: 100 - $score,
            versaoFormula: ScoreCalculator::VERSAO_FORMULA,
        );

        $campos = CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []]);
        $campos['total_criticos'] = $totalCriticos;
        $campos['total_altos'] = $totalAltos;
        $campos['total_ocorrencias'] = $totalOcorrencias;

        return CronogramaImportacaoHealthCheck::create(
            ['tenant_id' => $importacao->tenant_id, 'cronograma_importacao_id' => $importacao->id]
            + $campos
            + CronogramaImportacaoHealthCheck::camposDeScoreParaPersistir($scoreResultado)
        );
    }

    private function criarHealthCheckSemScore(CronogramaImportacao $importacao): CronogramaImportacaoHealthCheck
    {
        // Mesmo padrão já usado em CronogramaImportacaoScoreTest pra simular
        // um registro anterior à Fase 3 (Score): só camposParaPersistir(),
        // nunca camposDeScoreParaPersistir().
        //
        // tenant_id explícito porque estes fixtures são criados ANTES de
        // qualquer actingAs() (nenhum usuário autenticado ainda nesse ponto
        // do teste) — App\Models\Concerns\BelongsToTenant só carimba
        // automaticamente via TenantContext::currentId() quando há usuário
        // autenticado; sem isso, o valor explícito é o que vale.
        return CronogramaImportacaoHealthCheck::create(
            ['tenant_id' => $importacao->tenant_id, 'cronograma_importacao_id' => $importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
        );
    }

    // =========================================================================
    // Teste 1 — Report com Health Check + Score
    // =========================================================================

    public function test_report_com_health_check_e_score_retorna_score_faixa_e_severidades_corretos(): void
    {
        $importacao = $this->criarImportacao();
        $this->criarHealthCheckComScore($importacao, score: 87, totalCriticos: 1, totalAltos: 3, totalOcorrencias: 12);
        $report = $this->criarReport($importacao);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $confiabilidade = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->confiabilidadeCronograma;

        $this->assertSame('ok', $confiabilidade['estado']);
        $this->assertSame(87, $confiabilidade['score']);
        $this->assertSame('Bom', $confiabilidade['faixa_label']);
        $this->assertSame('success', $confiabilidade['faixa_cor']);
        $this->assertSame(12, $confiabilidade['total_ocorrencias']);
        $this->assertSame(1, $confiabilidade['total_criticos']);
        $this->assertSame(3, $confiabilidade['total_altos']);
    }

    public function test_tela_de_detalhe_exibe_confiabilidade_do_cronograma_com_score_e_achados(): void
    {
        $importacao = $this->criarImportacao();
        $this->criarHealthCheckComScore($importacao, score: 87, totalCriticos: 1, totalAltos: 3, totalOcorrencias: 12);
        $report = $this->criarReport($importacao);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Confiabilidade do Cronograma')
            ->assertSee('87/100', false)
            ->assertSee('Bom')
            ->assertSee('12 achado', false)
            ->assertSee('1 crítico', false)
            ->assertSee('3 alto', false);
    }

    public function test_texto_nunca_usa_saude_da_obra_ou_saude_do_projeto(): void
    {
        // Regra de negócio explícita da Etapa A: Confiabilidade do
        // Cronograma é linguagem deliberadamente diferente de "Saúde da
        // Obra"/"Saúde do Projeto" (que sugeririam avanço físico).
        $importacao = $this->criarImportacao();
        $this->criarHealthCheckComScore($importacao, score: 87, totalCriticos: 1, totalAltos: 3, totalOcorrencias: 12);
        $report = $this->criarReport($importacao);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertDontSee('Saúde da Obra')
            ->assertDontSee('Saúde do Projeto');
    }

    // =========================================================================
    // Teste 2 — Report sem Health Check
    // =========================================================================

    public function test_report_sem_health_check_nao_quebra_e_retorna_estado_indisponivel(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->assertSee('Confiabilidade do Cronograma')
            ->assertSee('Não disponível para esta importação');

        $this->assertSame('indisponivel', $component->instance()->confiabilidadeCronograma['estado']);
    }

    public function test_health_check_existe_mas_score_nao_mostra_apenas_achados_sem_inventar_score(): void
    {
        $importacao = $this->criarImportacao();
        $this->criarHealthCheckSemScore($importacao);
        $report = $this->criarReport($importacao);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Score não disponível');

        $confiabilidade = $component->instance()->confiabilidadeCronograma;
        $this->assertSame('sem_score', $confiabilidade['estado']);
        $this->assertArrayNotHasKey('score', $confiabilidade);
        $this->assertArrayNotHasKey('faixa_label', $confiabilidade);
    }

    public function test_score_sem_achados_criticos_ou_altos_nao_cria_alerta_artificial(): void
    {
        $importacao = $this->criarImportacao();
        // Score alto, com ocorrências só informativas — nenhum crítico/alto.
        $this->criarHealthCheckComScore($importacao, score: 95, totalCriticos: 0, totalAltos: 0, totalOcorrencias: 2);
        $report = $this->criarReport($importacao);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('95/100', false)
            // Faixa real do sistema para 95 (FaixaScore::paraScore(95) = Excelente)
            // — reaproveitada tal como já classificada, nunca uma faixa nova.
            ->assertSee('Excelente')
            ->assertSee('Nenhuma inconsistência crítica ou alta identificada');
    }

    // =========================================================================
    // Teste 3 — Report antigo
    // =========================================================================

    public function test_report_antigo_sem_health_check_continua_carregando_normalmente(): void
    {
        $importacaoAntiga = $this->criarImportacao();
        // Nenhum CronogramaImportacaoHealthCheck criado — simula uma
        // importação anterior à Fase 1 do Health Check.
        $reportAntigo = $this->criarReport($importacaoAntiga, StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $reportAntigo])
            ->assertOk()
            ->assertSee($reportAntigo->periodo_referencia->format('d/m/Y'));
    }

    // =========================================================================
    // Teste 4 — Report emitido continua readonly e mostra a importação vinculada
    // =========================================================================

    public function test_report_emitido_mostra_a_confiabilidade_da_sua_propria_importacao_de_origem(): void
    {
        $importacaoDoReport = $this->criarImportacao();
        $this->criarHealthCheckComScore($importacaoDoReport, score: 95, totalCriticos: 0, totalAltos: 0, totalOcorrencias: 0);

        // Uma segunda importação MAIS RECENTE da mesma obra, com Score bem
        // pior — o Report emitido deve continuar mostrando a confiabilidade
        // da SUA PRÓPRIA importação de origem, nunca a mais recente da obra.
        $importacaoMaisRecente = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now()->addDay(),
        ]);
        $this->criarHealthCheckComScore($importacaoMaisRecente, score: 40, totalCriticos: 5, totalAltos: 5, totalOcorrencias: 20);

        $report = $this->criarReport($importacaoDoReport, StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $confiabilidade = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->confiabilidadeCronograma;

        $this->assertSame(95, $confiabilidade['score']);
        $this->assertSame(0, $confiabilidade['total_criticos']);
        $this->assertSame(0, $confiabilidade['total_altos']);
    }

    public function test_report_emitido_continua_readonly(): void
    {
        // Confirma que esta etapa não afrouxou a dupla trava rascunho/
        // emitido já existente (ReportPolicy::update) — não é escopo desta
        // etapa alterar isso, só confirma que continua valendo.
        $importacao = $this->criarImportacao();
        $this->criarHealthCheckComScore($importacao, score: 87, totalCriticos: 1, totalAltos: 3, totalOcorrencias: 12);
        $report = $this->criarReport($importacao, StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->set('tituloEdit', 'Tentativa após emissão')
            ->call('salvarTitulo')
            ->assertForbidden();
    }

    // =========================================================================
    // Isolamento de tenant (defesa em profundidade — BelongsToTenant já
    // deveria garantir isso sozinho; confirmado aqui pra esta feature nova)
    // =========================================================================

    public function test_confiabilidade_nao_vaza_health_check_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraImportacao = CronogramaImportacao::create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'importado_em' => now(),
        ]);
        $this->criarHealthCheckComScore($outraImportacao, score: 10, totalCriticos: 9, totalAltos: 9, totalOcorrencias: 30);

        // Importação do tenant do teste, sem Health Check próprio.
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $confiabilidade = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->confiabilidadeCronograma;

        $this->assertSame('indisponivel', $confiabilidade['estado']);
    }
}
