<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa C1 — HH Expostas por Atraso: estimativa proporcional de HH
 * previsto associado a atividades atrasadas, usando EXCLUSIVAMENTE campos
 * já congelados em ReportCurva (total_atividades/atividades_atrasadas/
 * total_hh_previsto, gravados por App\Services\ReportGerador). Nenhuma
 * consulta a Atividade/Restricao ao vivo, nenhuma query nova.
 */
class ReportHhExpostaPorAtrasoTest extends TestCase
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

    private function criarCurva(
        Report $report,
        int $totalAtividades,
        int $atividadesAtrasadas,
        float $totalHhPrevisto,
    ): ReportCurva {
        return ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'total_atividades' => $totalAtividades,
            'atividades_concluidas' => 0,
            'atividades_atrasadas' => $atividadesAtrasadas,
            'total_hh_previsto' => $totalHhPrevisto,
        ]);
    }

    public function test_curva_com_atividades_atrasadas_calcula_hh_exposta_proporcionalmente(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        // 3 de 10 atividades atrasadas (30%), HH previsto total 1000 ->
        // HH exposta estimada = 1000 * 3/10 = 300.
        $curva = $this->criarCurva($report, totalAtividades: 10, atividadesAtrasadas: 3, totalHhPrevisto: 1000.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $linha = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->hhExpostaPorAtraso[$curva->id];

        $this->assertSame('ok', $linha['estado']);
        $this->assertSame(10, $linha['total_atividades']);
        $this->assertSame(3, $linha['atividades_atrasadas']);
        $this->assertEqualsWithDelta(30.0, $linha['percentual_atividades_atrasadas'], 0.01);
        $this->assertEqualsWithDelta(300.0, $linha['hh_exposta_estimada'], 0.01);
    }

    public function test_curva_sem_atividades_atrasadas_retorna_hh_exposta_zero(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $curva = $this->criarCurva($report, totalAtividades: 10, atividadesAtrasadas: 0, totalHhPrevisto: 500.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $linha = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->hhExpostaPorAtraso[$curva->id];

        $this->assertSame('ok', $linha['estado']);
        $this->assertEqualsWithDelta(0.0, $linha['percentual_atividades_atrasadas'], 0.01);
        $this->assertEqualsWithDelta(0.0, $linha['hh_exposta_estimada'], 0.01);
    }

    public function test_curva_sem_atividades_no_escopo_retorna_estado_sem_dado(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $curva = $this->criarCurva($report, totalAtividades: 0, atividadesAtrasadas: 0, totalHhPrevisto: 0.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $linha = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->hhExpostaPorAtraso[$curva->id];

        $this->assertSame('sem_dado', $linha['estado']);
    }

    /**
     * Atualizado na Fase 5 — Consolidação: HH Expostas por Atraso deixou de
     * ser card/heading próprio ("HH Expostas por Atraso", "3 de 10",
     * "30,0%") e virou linha secundária compacta dentro do KPI "Atrasadas"
     * ("≈ 300 HH expostas", tooltip com o texto de estimativa proporcional).
     * O cálculo em si (hhExpostaPorAtraso()) não mudou — só a apresentação.
     */
    public function test_tela_exibe_hh_expostas_por_atraso_com_estimativa_e_percentual(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $this->criarCurva($report, totalAtividades: 10, atividadesAtrasadas: 3, totalHhPrevisto: 1000.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('≈ 300 HH expostas', false)
            ->assertSee('Estimativa proporcional');
    }

    /**
     * Atualizado na Fase 5 — Consolidação: sem atividades atrasadas (ou sem
     * dado), a linha secundária de HH expostas simplesmente não aparece
     * dentro do KPI "Atrasadas" (nunca mais uma frase própria de "sem
     * dado") — comportamento coerente com virar um detalhe compacto, não
     * mais um card com estado vazio dedicado.
     */
    public function test_tela_nao_exibe_hh_expostas_quando_curva_sem_atividades(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $this->criarCurva($report, totalAtividades: 0, atividadesAtrasadas: 0, totalHhPrevisto: 0.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertDontSee('HH expostas');
    }

    // =========================================================================
    // Regressão das Etapas A e B (mesmo componente)
    // =========================================================================

    public function test_confiabilidade_e_causas_do_desvio_continuam_funcionando(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $this->criarCurva($report, totalAtividades: 5, atividadesAtrasadas: 1, totalHhPrevisto: 200.0);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame('indisponivel', $component->instance()->confiabilidadeCronograma['estado']);
        $this->assertIsArray($component->instance()->causasDoDesvio);
    }
}
