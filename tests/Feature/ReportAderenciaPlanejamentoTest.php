<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportCurvaDatapoint;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa G do roadmap original ("Quão previsível é?"/"Quão
 * aderente é o planejamento?") — aderenciaPlanejamento() lê EXCLUSIVAMENTE
 * a série já congelada em App\Models\ReportCurvaDatapoint (via o mesmo
 * App\Support\ReportCurvaSerializer já usado por dadosGraficos()), nunca
 * ppcPorSemana()/ProgramacaoSemanal/Atividade ao vivo.
 */
class ReportAderenciaPlanejamentoTest extends TestCase
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

    /** Curva "obra inteira" (pacote_trabalho_id null) — sempre a curva de referência, sem precisar de fallback de pior aderência. */
    private function criarCurva(Report $report): ReportCurva
    {
        return ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => null,
            'total_hh_previsto' => 100.0,
        ]);
    }

    /**
     * Grava 1 semana com previsto=100h fixo, pra que
     * aderencia_periodo = realizado_horas / 100 * 100 = $aderenciaDesejada
     * diretamente — nenhuma matemática escondida no teste.
     */
    private function criarSemana(ReportCurva $curva, Carbon $periodoInicio, ?float $aderenciaDesejada): void
    {
        ReportCurvaDatapoint::create([
            'tenant_id' => $this->tenant->id,
            'report_curva_id' => $curva->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $periodoInicio->toDateString(),
            'horas' => 100.0,
            'percentual_acumulado' => 0,
        ]);

        if ($aderenciaDesejada !== null) {
            ReportCurvaDatapoint::create([
                'tenant_id' => $this->tenant->id,
                'report_curva_id' => $curva->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Realizado->value,
                'periodo_inicio' => $periodoInicio->toDateString(),
                'horas' => $aderenciaDesejada,
                'percentual_acumulado' => 0,
            ]);
        }
    }

    private function primeiraSemana(): Carbon
    {
        return Carbon::parse('2026-01-05')->startOfWeek(); // segunda-feira
    }

    // =========================================================================
    // 1. Menos de 3 semanas -> bloco não aparece
    // =========================================================================

    public function test_menos_de_3_semanas_nao_aparece(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 80.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 82.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertFalse($component->instance()->aderenciaPlanejamento['tem_dado']);
        $component->assertDontSee('Aderência ao Planejamento');
    }

    // =========================================================================
    // 2. Exatamente 3 semanas -> bloco aparece
    // =========================================================================

    public function test_exatamente_3_semanas_aparece(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 86.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 81.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 84.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $resultado = $component->instance()->aderenciaPlanejamento;
        $this->assertTrue($resultado['tem_dado']);
        $this->assertCount(3, $resultado['semanas']);
        $component->assertSee('Aderência ao Planejamento');
    }

    // =========================================================================
    // 3. 4 semanas -> média das 4
    // =========================================================================

    public function test_4_semanas_usa_media_das_4(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $valores = [86.0, 81.0, 84.0, 81.0];
        foreach ($valores as $i => $v) {
            $this->criarSemana($curva, $semana->copy()->addWeeks($i), $v);
        }

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertCount(4, $resultado['semanas']);
        $this->assertEqualsWithDelta(array_sum($valores) / 4, $resultado['media'], 0.01);
    }

    // =========================================================================
    // 4. Mais de 4 semanas -> só as 4 mais recentes
    // =========================================================================

    public function test_mais_de_4_semanas_usa_apenas_as_4_mais_recentes(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        // 6 semanas — as 2 primeiras (90 e 95) NUNCA devem entrar no resultado.
        $valores = [90.0, 95.0, 86.0, 81.0, 84.0, 81.0];
        foreach ($valores as $i => $v) {
            $this->criarSemana($curva, $semana->copy()->addWeeks($i), $v);
        }

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertCount(4, $resultado['semanas']);
        $aderencias = array_column($resultado['semanas'], 'aderencia');
        $this->assertEqualsWithDelta([86.0, 81.0, 84.0, 81.0], $aderencias, 0.01);
        $this->assertEqualsWithDelta((86.0 + 81.0 + 84.0 + 81.0) / 4, $resultado['media'], 0.01);
    }

    // =========================================================================
    // 5. Média calculada corretamente (caso simples, 3 semanas)
    // =========================================================================

    public function test_media_calculada_corretamente(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 90.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 80.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 70.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertEqualsWithDelta(80.0, $resultado['media'], 0.01);
    }

    // =========================================================================
    // 6. Semanas apresentadas em ordem cronológica
    // =========================================================================

    public function test_semanas_em_ordem_cronologica(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 70.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 80.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 90.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $aderencias = array_column($resultado['semanas'], 'aderencia');
        $this->assertEqualsWithDelta([70.0, 80.0, 90.0], $aderencias, 0.01);
    }

    // =========================================================================
    // 7. Lacunas não preenchidas com zero
    // =========================================================================

    public function test_lacunas_nao_preenchidas_com_zero(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 90.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), null); // sem realizado — lacuna
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 80.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(3), 70.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        // Só 3 semanas ELEGÍVEIS (a lacuna nunca conta como uma 4ª semana
        // nem como 0%) — tem_dado continua true pois 3 >= mínimo.
        $this->assertCount(3, $resultado['semanas']);
        $aderencias = array_column($resultado['semanas'], 'aderencia');
        $this->assertEqualsWithDelta([90.0, 80.0, 70.0], $aderencias, 0.01);
    }

    // =========================================================================
    // 8. Semana ausente não entra na média
    // =========================================================================

    public function test_semana_ausente_nao_entra_na_media(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 90.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), null);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 90.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(3), 90.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        // Se a lacuna contasse como 0, a média cairia pra 67,5. Como não
        // conta, a média das 3 semanas reais (90/90/90) é exatamente 90.
        $this->assertEqualsWithDelta(90.0, $resultado['media'], 0.01);
    }

    // =========================================================================
    // 9/10/11. Classificação do semáforo
    // =========================================================================

    public function test_classificacao_boa_para_media_maior_ou_igual_90(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 90.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 92.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 94.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertSame('Boa', $resultado['faixa_label']);
        $this->assertSame('🟢', $resultado['faixa_emoji']);
    }

    public function test_classificacao_atencao_para_media_entre_75_e_90(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 80.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 78.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 82.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertSame('Atenção', $resultado['faixa_label']);
        $this->assertSame('🟠', $resultado['faixa_emoji']);
    }

    public function test_classificacao_critica_para_media_menor_que_75(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 60.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 65.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 70.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertSame('Crítica', $resultado['faixa_label']);
        $this->assertSame('🔴', $resultado['faixa_emoji']);
    }

    // =========================================================================
    // 12. Report sem datapoints elegíveis -> bloco não aparece
    // =========================================================================

    public function test_report_sem_datapoints_elegiveis_nao_aparece(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $this->criarCurva($report); // curva sem nenhum datapoint

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertFalse($component->instance()->aderenciaPlanejamento['tem_dado']);
        $component->assertDontSee('Aderência ao Planejamento');
    }

    // =========================================================================
    // 13. Isolamento de tenant
    // =========================================================================

    public function test_isolamento_de_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraImportacao = CronogramaImportacao::create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'importado_em' => now(),
        ]);
        $outroReport = Report::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'cronograma_importacao_id' => $outraImportacao->id,
            'status' => StatusReport::Rascunho->value,
        ]);
        $outraCurva = ReportCurva::factory()->create([
            'tenant_id' => $outroTenant->id,
            'report_id' => $outroReport->id,
            'pacote_trabalho_id' => null,
            'total_hh_previsto' => 100.0,
        ]);
        $semana = $this->primeiraSemana();
        foreach ([50.0, 55.0, 60.0] as $i => $v) {
            ReportCurvaDatapoint::create([
                'tenant_id' => $outroTenant->id,
                'report_curva_id' => $outraCurva->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $semana->copy()->addWeeks($i)->toDateString(),
                'horas' => 100.0,
                'percentual_acumulado' => 0,
            ]);
            ReportCurvaDatapoint::create([
                'tenant_id' => $outroTenant->id,
                'report_curva_id' => $outraCurva->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Realizado->value,
                'periodo_inicio' => $semana->copy()->addWeeks($i)->toDateString(),
                'horas' => $v,
                'percentual_acumulado' => 0,
            ]);
        }

        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $this->criarSemana($curva, $semana, 90.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 92.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 94.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertEqualsWithDelta((90.0 + 92.0 + 94.0) / 3, $resultado['media'], 0.01);
    }

    // =========================================================================
    // 14. Report histórico continua igual mesmo com outro Report/dado mais novo
    // =========================================================================

    public function test_report_historico_nao_e_afetado_por_dado_de_outro_report_mais_novo(): void
    {
        $report1 = $this->criarReport($this->criarImportacao());
        $curva1 = $this->criarCurva($report1);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva1, $semana, 90.0);
        $this->criarSemana($curva1, $semana->copy()->addWeek(), 92.0);
        $this->criarSemana($curva1, $semana->copy()->addWeeks(2), 94.0);

        // "Dado mais novo" da mesma obra, em outro Report — nunca deve
        // contaminar o resultado do Report histórico já emitido.
        $report2 = $this->criarReport($this->criarImportacao());
        $curva2 = $this->criarCurva($report2);
        $this->criarSemana($curva2, $semana->copy()->addWeeks(10), 10.0);
        $this->criarSemana($curva2, $semana->copy()->addWeeks(11), 10.0);
        $this->criarSemana($curva2, $semana->copy()->addWeeks(12), 10.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resultado = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report1])
            ->instance()
            ->aderenciaPlanejamento;

        $this->assertEqualsWithDelta((90.0 + 92.0 + 94.0) / 3, $resultado['media'], 0.01);
        $this->assertSame('Boa', $resultado['faixa_label']);
    }

    // =========================================================================
    // 15. Regressão das Etapas A-F
    // =========================================================================

    public function test_regressao_etapas_a_f(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $semana = $this->primeiraSemana();
        $this->criarSemana($curva, $semana, 90.0);
        $this->criarSemana($curva, $semana->copy()->addWeek(), 85.0);
        $this->criarSemana($curva, $semana->copy()->addWeeks(2), 88.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $instancia = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance();

        $this->assertIsArray($instancia->confiabilidadeCronograma);
        $this->assertIsArray($instancia->principaisDesvios);
        $this->assertIsArray($instancia->causasDoDesvio);
        $this->assertIsArray($instancia->hhExpostaPorAtraso);
        $this->assertIsArray($instancia->impactoRestricoes);
        $this->assertIsArray($instancia->topRiscos);
        $this->assertIsArray($instancia->decisoesPrioritarias);
        $this->assertIsArray($instancia->proximosEventosRelevantes);
        $this->assertTrue($instancia->resumoExecutivo['tem_dado']);
    }
}
