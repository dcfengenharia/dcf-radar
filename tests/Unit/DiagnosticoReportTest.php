<?php

namespace Tests\Unit;

use App\Enums\GranularidadePeriodo;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusReport;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\CausaNaoCumprimento;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\ImpactoRestricoesGerador;
use App\Support\Report\DiagnosticoReport;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 1 da Fase 1 (Diagnóstico Colaborativo) — testa
 * App\Support\Report\DiagnosticoReport diretamente, SEM Livewire, sobre
 * um Report com carregamento MÍNIMO (as 9 suítes de diagnóstico da Fase
 * 5 continuam sendo a garantia de paridade com o componente Livewire;
 * este arquivo prova que o serviço extraído funciona standalone e é
 * autossuficiente em eager loading).
 */
class DiagnosticoReportTest extends TestCase
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

    private function criarReport(CronogramaImportacao $importacao, string $periodoReferencia, string $dataStatus): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'periodo_referencia' => $periodoReferencia,
            'data_status' => $dataStatus,
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

    private function criarAtividade(PacoteTrabalho $pacote, array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'is_marco' => false,
            'caminho_critico' => false,
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarCurva(Report $report, ?PacoteTrabalho $pacote, array $overrides = []): ReportCurva
    {
        return ReportCurva::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacote?->id,
        ], $overrides));
    }

    private function criarDatapoint(ReportCurva $curva, SerieAvanco $serie, string $periodoInicio, float $horas, float $percentualAcumulado): void
    {
        $curva->datapoints()->create([
            'tenant_id' => $this->tenant->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => $serie->value,
            'periodo_inicio' => $periodoInicio,
            'horas' => $horas,
            'percentual_acumulado' => $percentualAcumulado,
        ]);
    }

    private function criarRestricao(Atividade $atividade, array $overrides = []): Restricao
    {
        return Restricao::factory()->create(array_merge([
            'tenant_id' => $atividade->tenant_id,
            'atividade_id' => $atividade->id,
        ], $overrides));
    }

    /** Mesmo padrão já usado em ReportTopRiscosTest/ReportDecisoesPrioritariasTest. */
    private function gerarImpacto(Report $report): void
    {
        TenantContext::actingAs($this->tenant, function () use ($report) {
            app(ImpactoRestricoesGerador::class)->gerar($report->fresh(['curvas.desvios', 'curvas.pacoteTrabalho']));
        });
    }

    private function criarCausa(Atividade $atividade, string $descricao, ?string $registradaEm = null): CausaNaoCumprimento
    {
        $causa = CausaNaoCumprimento::factory()->create([
            'tenant_id' => $atividade->tenant_id,
            'atividade_id' => $atividade->id,
            'descricao' => $descricao,
        ]);

        if ($registradaEm !== null) {
            $causa->forceFill(['created_at' => $registradaEm])->save();
        }

        return $causa;
    }

    private function criarSnapshot(Atividade $atividade, CronogramaImportacao $importacao, array $overrides = []): AtividadeSnapshot
    {
        return AtividadeSnapshot::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => null,
            'data_termino' => null,
            'baseline_inicio' => null,
            'baseline_termino' => null,
        ], $overrides));
    }

    /**
     * Cenário representativo único, cobrindo curvas assimétricas (uma com
     * impacto de restrição, outra sem nenhum), causas com filtro temporal,
     * próximos eventos e datapoints — reaproveitado por vários testes
     * abaixo pra não duplicar o setup.
     */
    private function montarCenarioCompleto(): array
    {
        $importacao = $this->criarImportacao();
        $periodoReferencia = '2026-06-15';
        $report = $this->criarReport($importacao, $periodoReferencia, '2026-06-15');

        // Curva A: pacote com desvio filho (impacto negativo) + restrição
        // aberta associada — gera snapshot via ImpactoRestricoesGerador.
        // Deliberadamente NÃO aninhado (pacoteFilhoA não é descendente de
        // pacotePaiA) — o escopo do desvio "nível pai" é o pacote da
        // CURVA + descendentes (mesmo padrão de causasDoDesvio()), então
        // aninhar faria a restrição vazar pro escopo do pai também,
        // duplicando a mesma restrição nos dois níveis (comportamento
        // real e correto do sistema, só indesejado neste cenário de
        // teste específico, que quer isolar a restrição só no filho).
        $pacotePaiA = $this->criarPacote();
        $pacoteFilhoA = $this->criarPacote();
        $atividadeA = $this->criarAtividade($pacoteFilhoA);
        $this->criarRestricao($atividadeA, [
            'descricao' => 'Restrição vencida do pacote A',
            'probabilidade' => 9,
            'impacto' => 9,
            'prazo_limite' => now()->subDays(3)->toDateString(),
        ]);
        $curvaA = $this->criarCurva($report, $pacotePaiA, [
            'total_atividades' => 10,
            'atividades_atrasadas' => 4,
            'total_hh_previsto' => 500,
        ]);
        $curvaA->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacotePaiA->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => 'Pacote A (nível pai)',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 40.0,
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
            'ordem' => 0,
        ]);
        $curvaA->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacoteFilhoA->id,
            'eh_nivel_pai' => false,
            'titulo_exibicao' => 'Pacote A - Filho',
            'peso' => 0.5,
            'percentual_previsto' => 60.0,
            'percentual_real' => 30.0,
            'percentual_desvio' => -30.0,
            'percentual_impacto' => -15.0,
            'ordem' => 1,
        ]);

        $this->criarDatapoint($curvaA, SerieAvanco::Previsto, '2026-05-25', 100, 20.0);
        $this->criarDatapoint($curvaA, SerieAvanco::Realizado, '2026-05-25', 80, 16.0);
        $this->criarDatapoint($curvaA, SerieAvanco::Previsto, '2026-06-01', 100, 40.0);
        $this->criarDatapoint($curvaA, SerieAvanco::Realizado, '2026-06-01', 90, 34.0);
        $this->criarDatapoint($curvaA, SerieAvanco::Previsto, '2026-06-08', 100, 60.0);
        $this->criarDatapoint($curvaA, SerieAvanco::Realizado, '2026-06-08', 100, 44.0);

        // Curva B: pacote SEM nenhuma restrição — deliberadamente
        // assimétrica em relação à curva A (mesma condição já usada nos
        // testes de hidratação do Arc 1: uma curva "cheia", outra "vazia"
        // de restricaoImpacto).
        $pacoteB = $this->criarPacote();
        $curvaB = $this->criarCurva($report, $pacoteB, [
            'total_atividades' => 0,
            'atividades_atrasadas' => 0,
            'total_hh_previsto' => 0,
        ]);
        $curvaB->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacoteB->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => 'Pacote B (nível pai)',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 50.0,
            'percentual_desvio' => 0.0,
            'percentual_impacto' => 0.0,
            'ordem' => 0,
        ]);

        $this->gerarImpacto($report);

        // Causas: uma registrada ANTES da data de referência (deve
        // aparecer), outra DEPOIS (deve ser excluída pelo filtro
        // temporal — preservado fielmente da extração original).
        $this->criarCausa($atividadeA, 'Atraso na entrega de material', '2026-06-10');
        $this->criarCausa($atividadeA, 'Causa registrada depois da fotografia', '2026-06-20');

        // Próximo evento: atividade com término dentro da janela
        // periodo_referencia+1 semana (22/06 a 28/06).
        $atividadeEvento = $this->criarAtividade($pacoteFilhoA, ['codigo_cronograma' => '1.1']);
        $this->criarSnapshot($atividadeEvento, $importacao, [
            'data_termino' => '2026-06-24',
        ]);

        return compact('report', 'importacao', 'curvaA', 'curvaB', 'atividadeA', 'pacotePaiA', 'pacoteFilhoA', 'pacoteB');
    }

    // =========================================================================
    // 1. Cenário completo — todos os 9 diagnósticos + dadosGraficos
    // =========================================================================

    public function test_calcula_todos_os_diagnosticos_com_carregamento_minimo_do_report(): void
    {
        ['report' => $report] = $this->montarCenarioCompleto();

        // Carregamento MÍNIMO deliberado — nenhuma relação eager-carregada
        // antes de chamar o serviço, provando que ele é autossuficiente.
        $reportMinimo = Report::find($report->id);
        $this->assertFalse($reportMinimo->relationLoaded('curvas'));

        $diagnostico = (new DiagnosticoReport)->calcular($reportMinimo);

        $this->assertEqualsCanonicalizing([
            'confiabilidadeCronograma',
            'principaisDesvios',
            'causasDoDesvio',
            'hhExpostaPorAtraso',
            'impactoRestricoes',
            'topRiscos',
            'proximosEventosRelevantes',
            'aderenciaPlanejamento',
            'decisoesPrioritarias',
            'dadosGraficos',
        ], array_keys($diagnostico));

        // Confiabilidade do Cronograma — sem Health Check nesta importação.
        $this->assertSame(['estado' => 'indisponivel'], $diagnostico['confiabilidadeCronograma']);

        // Principais Desvios — só a linha FILHA com impacto negativo entra
        // (nível pai nunca compete, mesma regra da extração original).
        $this->assertCount(1, $diagnostico['principaisDesvios']);
        $this->assertSame('Pacote A - Filho', $diagnostico['principaisDesvios'][0]['titulo_exibicao']);
        $this->assertSame(-15.0, $diagnostico['principaisDesvios'][0]['percentual_impacto']);

        // Causas do Desvio — filtro temporal preservado: só a causa
        // registrada ATÉ a data de referência aparece.
        $linhaCausas = collect($diagnostico['causasDoDesvio'])->firstWhere('atividades_com_causa', '>', 0);
        $this->assertNotNull($linhaCausas);
        $this->assertCount(1, $linhaCausas['causas']);
        $this->assertSame('Atraso na entrega de material', $linhaCausas['causas'][0]['descricao']);

        // HH Exposta por Atraso — curva A com 4/10 atrasadas, curva B sem
        // atividades (estado sem_dado).
        $hhPorCurva = $diagnostico['hhExpostaPorAtraso'];
        $estados = array_column($hhPorCurva, 'estado');
        $this->assertContains('ok', $estados);
        $this->assertContains('sem_dado', $estados);

        // Impacto de Restrições — ImpactoRestricoesGerador cria um
        // snapshot pra CADA desvio (mesmo com zero restrições no escopo),
        // nunca null nesse cenário — null só ocorre pra reports antigos
        // onde o gerador nunca rodou (achado confirmado ao rodar este
        // teste, corrigindo a suposição inicial do cenário). A assimetria
        // real está no CONTEÚDO: só o desvio filho da curva A tem
        // restrição aberta no escopo.
        $this->assertCount(3, $diagnostico['impactoRestricoes']);
        $comRestricaoAberta = collect($diagnostico['impactoRestricoes'])
            ->filter(fn (?array $s) => $s && $s['total_abertas'] > 0)
            ->values();
        $this->assertCount(1, $comRestricaoAberta);
        $this->assertSame(1, $comRestricaoAberta[0]['total_abertas']);
        $this->assertSame(1, $comRestricaoAberta[0]['total_vencidas']);

        // Top Riscos — a restrição vencida do pacote A aparece.
        $this->assertCount(1, $diagnostico['topRiscos']);
        $this->assertSame('Restrição vencida do pacote A', $diagnostico['topRiscos'][0]['descricao']);
        $this->assertTrue($diagnostico['topRiscos'][0]['vencida']);

        // Próximos Eventos Relevantes — término da atividade dentro da janela.
        $this->assertCount(1, $diagnostico['proximosEventosRelevantes']);
        $this->assertSame('termino', $diagnostico['proximosEventosRelevantes'][0]['tipo']);
        $this->assertSame('2026-06-24', $diagnostico['proximosEventosRelevantes'][0]['data']);

        // Decisões Prioritárias — mesma restrição vencida, mesma origem
        // de dado que topRiscos (variável local $impactoRestricoes).
        $this->assertCount(1, $diagnostico['decisoesPrioritarias']);
        $this->assertSame('Restrição vencida do pacote A', $diagnostico['decisoesPrioritarias'][0]['descricao']);

        // Aderência ao Planejamento — estrutura válida (não força um
        // valor específico, só garante que não quebrou).
        $this->assertArrayHasKey('tem_dado', $diagnostico['aderenciaPlanejamento']);

        // dadosGraficos — 1 entrada por curva, com as 4 chaves esperadas.
        $this->assertCount(2, $diagnostico['dadosGraficos']);
        foreach ($diagnostico['dadosGraficos'] as $entrada) {
            $this->assertEqualsCanonicalizing(['id', 'mensal', 'semanal', 'aderencia_atual'], array_keys($entrada));
        }
    }

    // =========================================================================
    // 2. Autossuficiência de eager loading
    // =========================================================================

    public function test_garante_suas_proprias_relacoes_mesmo_sem_eager_load_previo(): void
    {
        ['report' => $report] = $this->montarCenarioCompleto();

        $reportMinimo = Report::find($report->id);
        $this->assertFalse($reportMinimo->relationLoaded('curvas'));
        $this->assertFalse($reportMinimo->relationLoaded('cronogramaImportacao'));

        (new DiagnosticoReport)->calcular($reportMinimo);

        $this->assertTrue($reportMinimo->relationLoaded('curvas'));
        $this->assertTrue($reportMinimo->relationLoaded('cronogramaImportacao'));
        $this->assertTrue($reportMinimo->curvas->first()->relationLoaded('desvios'));
    }

    public function test_nao_gera_query_extra_quando_relacoes_ja_estao_carregadas(): void
    {
        ['report' => $report] = $this->montarCenarioCompleto();

        $reportCompleto = Report::with([
            'cronogramaImportacao.healthCheck',
            'curvas.datapoints',
            'curvas.desvios.pacoteTrabalho',
            'curvas.desvios.restricaoImpacto',
            'curvas.pacoteTrabalho',
        ])->find($report->id);

        DB::enableQueryLog();
        (new DiagnosticoReport)->calcular($reportCompleto);
        $queriesDoLoadMissing = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'report_curvas') || str_contains($q['query'], 'report_desvios') || str_contains($q['query'], 'cronograma_importacao_health_checks'))
            ->count();
        DB::disableQueryLog();

        // loadMissing() é idempotente — com tudo já carregado, nenhuma
        // query nova nessas tabelas deveria disparar.
        $this->assertSame(0, $queriesDoLoadMissing);
    }

    // =========================================================================
    // 3. Reports antigos / sem dado — nunca inventa, nunca quebra
    // =========================================================================

    public function test_report_sem_curvas_nao_quebra_e_retorna_estados_vazios(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15', '2026-06-15');

        $diagnostico = (new DiagnosticoReport)->calcular($report);

        $this->assertSame([], $diagnostico['principaisDesvios']);
        $this->assertSame([], $diagnostico['impactoRestricoes']);
        $this->assertSame([], $diagnostico['topRiscos']);
        $this->assertSame([], $diagnostico['decisoesPrioritarias']);
        $this->assertSame([], $diagnostico['hhExpostaPorAtraso']);
        $this->assertSame([], $diagnostico['dadosGraficos']);
        $this->assertFalse($diagnostico['aderenciaPlanejamento']['tem_dado']);
    }
}
