<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportDesvio;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa B do roadmap original ("Onde está o problema?") —
 * principaisDesvios() agrega, em tempo de leitura, as linhas filhas de
 * App\Models\ReportDesvio de TODAS as curvas do Report, seleciona as 3
 * com pior percentual_impacto (mais negativo primeiro). Nenhuma
 * persistência nova, nenhuma consulta a Restricao.
 */
class ReportPrincipaisDesviosTest extends TestCase
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

    private function criarPacote(): PacoteTrabalho
    {
        return PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
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

    /**
     * Default deliberadamente neutro (percentual_impacto = 0, nunca
     * elegível) — cada teste sobrescreve explicitamente o que precisa,
     * nunca depende de um valor "mágico" pré-definido.
     */
    private function criarDesvio(ReportCurva $curva, array $overrides = []): ReportDesvio
    {
        return $curva->desvios()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $this->criarPacote()->id,
            'eh_nivel_pai' => false,
            'titulo_exibicao' => 'Pacote',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 50.0,
            'percentual_desvio' => 0.0,
            'percentual_impacto' => 0.0,
            'ordem' => 0,
        ], $overrides));
    }

    // =========================================================================
    // 1. Agrega desvios de todas as curvas do report
    // =========================================================================

    public function test_agrega_desvios_de_todas_as_curvas_do_report(): void
    {
        $report = $this->criarReport($this->criarImportacao());

        $curvaA = $this->criarCurva($report);
        $this->criarDesvio($curvaA, [
            'titulo_exibicao' => 'Pacote A',
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
        ]);

        $curvaB = $this->criarCurva($report);
        $this->criarDesvio($curvaB, [
            'titulo_exibicao' => 'Pacote B',
            'percentual_desvio' => -5.0,
            'percentual_impacto' => -5.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertCount(2, $principaisDesvios);
        $titulos = array_column($principaisDesvios, 'titulo_exibicao');
        $this->assertContains('Pacote A', $titulos);
        $this->assertContains('Pacote B', $titulos);
    }

    // =========================================================================
    // 2. Seleciona corretamente os 3 maiores desvios (de mais de 3 existentes)
    // =========================================================================

    public function test_seleciona_os_3_maiores_desvios_entre_mais_de_3(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);

        for ($i = 1; $i <= 5; $i++) {
            $this->criarDesvio($curva, [
                'titulo_exibicao' => "Pacote {$i}",
                'percentual_desvio' => -1.0 * $i,
                'percentual_impacto' => -1.0 * $i,
                'ordem' => $i,
            ]);
        }

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertCount(3, $principaisDesvios);
    }

    // =========================================================================
    // 3. Respeita o ranking pelo impacto negativo (mais negativo primeiro)
    // =========================================================================

    public function test_ranking_respeita_impacto_mais_negativo_primeiro(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);

        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Impacto leve',
            'percentual_desvio' => -2.0,
            'percentual_impacto' => -2.0,
        ]);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Impacto grave',
            'percentual_desvio' => -20.0,
            'percentual_impacto' => -20.0,
        ]);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Impacto moderado',
            'percentual_desvio' => -8.0,
            'percentual_impacto' => -8.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertSame([
            'Impacto grave',
            'Impacto moderado',
            'Impacto leve',
        ], array_column($principaisDesvios, 'titulo_exibicao'));
    }

    // =========================================================================
    // 4. Empate de impacto usa módulo do desvio
    // =========================================================================

    public function test_empate_de_impacto_usa_maior_modulo_do_desvio(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);

        // Mesmo percentual_impacto (-10.0), mas percentual_desvio diferente
        // (simula pesos diferentes produzindo o mesmo impacto final).
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Desvio menor',
            'peso' => 0.5,
            'percentual_desvio' => -20.0,
            'percentual_impacto' => -10.0,
        ]);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Desvio maior',
            'peso' => 1.0,
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertSame([
            'Desvio menor',
            'Desvio maior',
        ], array_column($principaisDesvios, 'titulo_exibicao'));
    }

    // =========================================================================
    // 5. Empate completo produz ordenação determinística
    // =========================================================================

    public function test_empate_completo_produz_ordenacao_deterministica(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);

        $d1 = $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Empate 1',
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
        ]);
        $d2 = $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Empate 2',
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $primeiraExecucao = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $segundaExecucao = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertSame(
            array_column($primeiraExecucao, 'id'),
            array_column($segundaExecucao, 'id')
        );

        $idsEsperados = collect([$d1->id, $d2->id])->sort()->values()->all();
        $this->assertSame($idsEsperados, array_column($primeiraExecucao, 'id'));
    }

    // =========================================================================
    // 6. Desvios positivos/neutros não aparecem
    // =========================================================================

    public function test_desvios_positivos_e_neutros_nao_aparecem(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);

        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Neutro',
            'percentual_desvio' => 0.0,
            'percentual_impacto' => 0.0,
        ]);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Adiantado',
            'percentual_desvio' => 15.0,
            'percentual_impacto' => 15.0,
        ]);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Atrasado',
            'percentual_desvio' => -5.0,
            'percentual_impacto' => -5.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertSame(['Atrasado'], array_column($principaisDesvios, 'titulo_exibicao'));
    }

    // =========================================================================
    // 7. Report sem desvios elegíveis não renderiza o bloco
    // =========================================================================

    public function test_report_sem_desvios_elegiveis_nao_renderiza_bloco(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Tudo bem',
            'percentual_desvio' => 5.0,
            'percentual_impacto' => 5.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame([], $component->instance()->principaisDesvios);
        $component->assertDontSee('Principais Desvios');
    }

    // =========================================================================
    // 8. Pacote/frente é preservado corretamente
    // =========================================================================

    public function test_pacote_e_preservado_corretamente(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, [
            'pacote_trabalho_id' => $pacote->id,
            'titulo_exibicao' => '1.1 - Fundações',
            'percentual_previsto' => 60.0,
            'percentual_real' => 40.0,
            'percentual_desvio' => -20.0,
            'percentual_impacto' => -20.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertCount(1, $principaisDesvios);
        $this->assertSame('1.1 - Fundações', $principaisDesvios[0]['titulo_exibicao']);
        $this->assertSame(60.0, $principaisDesvios[0]['percentual_previsto']);
        $this->assertSame(40.0, $principaisDesvios[0]['percentual_real']);
        $this->assertSame(-20.0, $principaisDesvios[0]['percentual_desvio']);
    }

    // =========================================================================
    // 9. Não mistura dados de outro report
    // =========================================================================

    public function test_nao_mistura_dados_de_outro_report(): void
    {
        $report1 = $this->criarReport($this->criarImportacao());
        $curva1 = $this->criarCurva($report1);
        $this->criarDesvio($curva1, [
            'titulo_exibicao' => 'Do Report 1',
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
        ]);

        $report2 = $this->criarReport($this->criarImportacao());
        $curva2 = $this->criarCurva($report2);
        $this->criarDesvio($curva2, [
            'titulo_exibicao' => 'Do Report 2',
            'percentual_desvio' => -30.0,
            'percentual_impacto' => -30.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report1])
            ->instance()
            ->principaisDesvios;

        $this->assertSame(['Do Report 1'], array_column($principaisDesvios, 'titulo_exibicao'));
    }

    // =========================================================================
    // 10. Isolamento de tenant
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
        ]);
        $outroPacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
        ]);
        $outraCurva->desvios()->create([
            'tenant_id' => $outroTenant->id,
            'pacote_trabalho_id' => $outroPacote->id,
            'eh_nivel_pai' => false,
            'titulo_exibicao' => 'De outro tenant',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 10.0,
            'percentual_desvio' => -40.0,
            'percentual_impacto' => -40.0,
            'ordem' => 0,
        ]);

        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Do meu tenant',
            'percentual_desvio' => -5.0,
            'percentual_impacto' => -5.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->principaisDesvios;

        $this->assertSame(['Do meu tenant'], array_column($principaisDesvios, 'titulo_exibicao'));
    }

    // =========================================================================
    // 11. Linguagem nunca afirma causalidade
    // =========================================================================

    public function test_interface_nunca_afirma_causalidade(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Estrutura Bloco B',
            'percentual_desvio' => -18.0,
            'percentual_impacto' => -18.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Principais Desvios')
            ->assertSee('Estrutura Bloco B')
            ->assertDontSee('causado por')
            ->assertDontSee('devido a')
            ->assertDontSee('consequência de')
            ->assertDontSee('causa raiz')
            ->assertDontSee('responsável pelo atraso');
    }

    // =========================================================================
    // 12/13/14/15. Regressão — resumoExecutivo/topRiscos/decisoesPrioritarias/impactoRestricoes
    // =========================================================================

    public function test_resumo_executivo_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Pacote X',
            'percentual_desvio' => -12.0,
            'percentual_impacto' => -12.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resumo = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->resumoExecutivo;

        $this->assertTrue($resumo['tem_dado']);
        $this->assertSame('Pacote X', $resumo['maior_desvio']['titulo']);
        $this->assertSame(-12.0, $resumo['maior_desvio']['percentual_impacto']);
    }

    public function test_top_riscos_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Pacote Y',
            'percentual_desvio' => -5.0,
            'percentual_impacto' => -5.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $topRiscos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->topRiscos;

        $this->assertIsArray($topRiscos);
        $this->assertSame([], $topRiscos);
    }

    public function test_decisoes_prioritarias_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Pacote Z',
            'percentual_desvio' => -5.0,
            'percentual_impacto' => -5.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->decisoesPrioritarias;

        $this->assertIsArray($decisoes);
        $this->assertSame([], $decisoes);
    }

    public function test_impacto_restricoes_continua_funcionando(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $curva = $this->criarCurva($report);
        $desvio = $this->criarDesvio($curva, [
            'titulo_exibicao' => 'Pacote W',
            'percentual_desvio' => -5.0,
            'percentual_impacto' => -5.0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $impacto = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->impactoRestricoes;

        $this->assertIsArray($impacto);
        $this->assertArrayHasKey($desvio->id, $impacto);
        $this->assertNull($impacto[$desvio->id]);
    }
}
