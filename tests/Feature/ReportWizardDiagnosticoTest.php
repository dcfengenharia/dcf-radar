<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusReport;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportPontoAtencao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 3 da Fase 1 (Diagnóstico Colaborativo) — ciclo de vida do Report
 * dentro do wizard (⚡relatorio-novo.blade.php).
 *
 * Parte 1 (criação/reaproveitamento/descarte do Report + hidratação): A-D
 * abaixo. Parte 2 (UI de diagnóstico somente leitura no Passo 3 + novo
 * caminho de salvar() reaproveitando o Report já existente): E-G abaixo.
 * Fallback antigo de salvar() (chamada direta sem passar pelo passo 2)
 * continua coberto por ReportWizardTest (arquivo intocado nos dois
 * ciclos).
 */
class ReportWizardDiagnosticoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $gerente;
    private PacoteTrabalho $pacote;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->gerente, Papel::GerentePlanejamento->value);

        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        $this->pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'CIVIL',
            'codigo' => '1',
        ]);
    }

    // =========================================================================
    // A) Sair do passo 2 cria exatamente 1 Report
    // =========================================================================

    public function test_sair_do_passo_2_cria_exatamente_um_report(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar') // 1 -> 2
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar'); // 2 -> 3, cria o rascunho

        $componente->assertSet('etapa', '3');

        $this->assertSame(1, Report::count());
        $report = Report::first();
        $this->assertTrue($report->estaRascunho());
        $this->assertSame($this->pacote->id, $report->curvas->first()->pacote_trabalho_id);

        $this->assertNotNull($componente->instance()->report);
        $this->assertSame($report->id, $componente->instance()->report->id);
    }

    public function test_avancar_sem_selecionar_curva_nao_cria_report(): void
    {
        // Regressão: a validação de ordemCurvas vazio (já existente) deve
        // continuar bloqueando ANTES de qualquer tentativa de criar o
        // rascunho.
        $this->actingAs($this->gerente);

        Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar') // 1 -> 2
            ->call('avancar') // permanece em 2, ordemCurvas vazio
            ->assertHasErrors('ordemCurvas')
            ->assertSet('etapa', '2');

        $this->assertSame(0, Report::count());
    }

    // =========================================================================
    // B) Avançar de novo sem mudar a assinatura reutiliza o mesmo Report
    // =========================================================================

    public function test_avancar_novamente_sem_mudar_assinatura_reutiliza_o_mesmo_report(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $reportId = $componente->instance()->report->id;
        $this->assertSame(1, Report::count());

        // Volta pro passo 2 e avança de novo SEM alterar nada.
        $componente->call('voltar')
            ->assertSet('etapa', '2')
            ->call('avancar');

        $componente->assertSet('etapa', '3');
        $this->assertSame(1, Report::count());
        $this->assertSame($reportId, $componente->instance()->report->id);
    }

    // =========================================================================
    // C) Mudar período/ordemCurvas descarta o rascunho anterior (forceDelete)
    //    e cria outro
    // =========================================================================

    public function test_mudar_curvas_descarta_rascunho_anterior_via_forcedelete_e_cria_outro(): void
    {
        $this->actingAs($this->gerente);

        $outroPacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'ESTRUTURA',
            'codigo' => '2',
        ]);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $reportIdAntigo = $componente->instance()->report->id;
        $curvaIdAntiga = ReportCurva::where('report_id', $reportIdAntigo)->value('id');
        $this->assertSame(1, Report::count());

        // Volta e muda a seleção de curvas (assinatura muda).
        $componente->call('voltar')
            ->assertSet('etapa', '2')
            ->call('togglePacote', $outroPacote->id)
            ->call('avancar');

        $componente->assertSet('etapa', '3');

        $novoReportId = $componente->instance()->report->id;
        $this->assertNotSame($reportIdAntigo, $novoReportId);

        // Nunca 2 reports simultâneos — o antigo foi realmente removido
        // (forceDelete, não soft-delete: withTrashed() também não encontra).
        $this->assertSame(1, Report::count());
        $this->assertNull(Report::withTrashed()->find($reportIdAntigo));

        // Cascata real disparada pelo forceDelete — a curva antiga também
        // sumiu (ReportCurva não usa SoftDeletes, então find() já basta).
        $this->assertNull(ReportCurva::find($curvaIdAntiga));

        // O novo report reflete as 2 curvas selecionadas agora.
        $novoReport = Report::find($novoReportId);
        $this->assertCount(2, $novoReport->curvas);
    }

    public function test_mudar_periodo_descarta_rascunho_anterior_e_cria_outro(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $reportIdAntigo = $componente->instance()->report->id;
        $this->assertSame(1, Report::count());

        // Volta até o passo 1 (2 chamadas de voltar()), muda o período,
        // avança de novo com a MESMA seleção de curvas.
        $componente->call('voltar')->call('voltar')
            ->assertSet('etapa', '1')
            ->set('periodoReferencia', '2026-06-22')
            ->call('avancar')
            ->call('avancar');

        $componente->assertSet('etapa', '3');

        $novoReportId = $componente->instance()->report->id;
        $this->assertNotSame($reportIdAntigo, $novoReportId);
        $this->assertSame(1, Report::count());
        $this->assertNull(Report::withTrashed()->find($reportIdAntigo));
    }

    // =========================================================================
    // D) Hidratação com 2+ curvas assimétricas não dispara
    //    LazyLoadingViolationException
    // =========================================================================

    public function test_hidratacao_com_curvas_assimetricas_nao_dispara_lazy_load(): void
    {
        // Mesma condição já usada nos 3 testes de hidratação do Arc 1
        // (ReportEmitirRefreshHidratacaoTest/ReportImpactoRestricoesHidratacaoTest/
        // ReportPontosAtencaoRefreshHidratacaoTest) — 2 curvas assimétricas,
        // uma com desvio+restricaoImpacto, outra sem nenhum desvio.
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'periodo_referencia' => '2026-06-15',
            'status' => StatusReport::Rascunho->value,
        ]);

        $pacoteComDesvio = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        $curvaComDesvio = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacoteComDesvio->id,
        ]);
        $desvio = $curvaComDesvio->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacoteComDesvio->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => 'Pacote com desvio',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 40.0,
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
            'ordem' => 0,
        ]);
        $desvio->restricaoImpacto()->create([
            'tenant_id' => $this->tenant->id,
            'total_abertas' => 1,
            'total_vencidas' => 0,
            'total_criticas' => 0,
            'detalhes' => [],
        ]);

        // Segunda curva, propositalmente SEM nenhum desvio.
        $pacoteSemDesvio = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacoteSemDesvio->id,
        ]);

        $this->actingAs($this->gerente);

        // Simula o cenário real: o wizard já tem esse Report em memória
        // (como aconteceria depois de sair do passo 2) e uma NOVA
        // requisição chega (aqui, avancar() do passo 1 pro 2 — ação que
        // não recria nem toca o Report, só serve pra forçar o ciclo
        // hydrate() do Livewire entre requests).
        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('report', $report)
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar'); // 1 -> 2, dispara hydrate() antes da ação

        $componente->assertSet('etapa', '2');

        // diagnostico() consegue avaliar todos os blocos sem LazyLoadingViolationException.
        $diagnostico = $componente->instance()->diagnostico;
        $this->assertIsArray($diagnostico);
        $this->assertArrayHasKey('topRiscos', $diagnostico);
        $this->assertArrayHasKey('impactoRestricoes', $diagnostico);
        $this->assertArrayHasKey('dadosGraficos', $diagnostico);
        $this->assertCount(2, $diagnostico['dadosGraficos']);
    }

    public function test_diagnostico_sem_report_ainda_devolve_array_vazio(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra]);

        $this->assertSame([], $componente->instance()->diagnostico);
    }

    // =========================================================================
    // Helpers de cenário (Parte 2) — reaproveitados pelos testes E/F abaixo
    // =========================================================================

    /**
     * Monta um pacote-pai (o que será marcado em ordemCurvas) com 1 pacote
     * filho contendo 1 atividade com HH previsto/realizado reais — via
     * AvancoPeriodo mensal (denominador do escopo) + semanal (usado por
     * ReportGerador::hhAcumuladoAteData(), que exige data_status setada na
     * importação). %previsto=100%, %real=40% ⇒ percentual_impacto=-60 na
     * linha FILHA (eh_nivel_pai=false) — garante que principaisDesvios()
     * tenha conteúdo real através do pipeline de verdade (ReportGerador),
     * nunca inventado no teste.
     */
    private function montarPacoteComDesvioNegativo(string $codigoPai): array
    {
        $this->importacao->forceFill(['data_status' => '2026-06-05'])->save();

        $pacotePai = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => $codigoPai,
        ]);
        $pacoteFilho = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pacotePai->id,
            'codigo' => "{$codigoPai}.1",
        ]);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteFilho->id,
        ]);

        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => '2026-06-01',
            'horas' => 100,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => '2026-06-01',
            'horas' => 100,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Realizado->value,
            'periodo_inicio' => '2026-06-01',
            'horas' => 40,
        ]);

        return compact('pacotePai', 'pacoteFilho', 'atividade');
    }

    /** Restrição vencida na atividade — elegível pra topRiscos()/decisoesPrioritarias(). */
    private function criarRestricaoVencida(Atividade $atividade): Restricao
    {
        return Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'descricao' => 'Restrição vencida de teste',
            'probabilidade' => 9,
            'impacto' => 9,
            'prazo_limite' => now()->subDays(3)->toDateString(),
        ]);
    }

    // =========================================================================
    // E) UI do Passo 3 — Diagnóstico (somente leitura)
    // =========================================================================

    public function test_passo_3_mostra_diagnostico_real_vindo_de_diagnosticoreport(): void
    {
        ['pacotePai' => $pacotePai, 'atividade' => $atividade] = $this->montarPacoteComDesvioNegativo('9');
        $this->criarRestricaoVencida($atividade);

        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $pacotePai->id)
            ->call('avancar');

        $componente->assertSet('etapa', '3');

        // Paridade: o que a UI usa ($this->diagnostico) é EXATAMENTE o que
        // DiagnosticoReport::calcular() produziria pro mesmo Report — sem
        // recalcular nada no Blade.
        $report = $componente->instance()->report;
        $esperado = app(\App\Support\Report\DiagnosticoReport::class)->calcular($report);
        $this->assertSame($esperado, $componente->instance()->diagnostico);

        $componente->assertSee('O Radar analisou sua semana')
            ->assertSee('ponto'); // "1 ponto que merece atenção" ou "N pontos..."
    }

    public function test_diagnostico_critico_e_relevante_aparecem_somente_quando_ha_dados(): void
    {
        ['pacotePai' => $pacotePai, 'atividade' => $atividade] = $this->montarPacoteComDesvioNegativo('9');
        $this->criarRestricaoVencida($atividade);

        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $pacotePai->id)
            ->call('avancar');

        // 🔴 Crítico (restrição vencida ⇒ decisoesPrioritarias) e 🟠
        // Relevante (desvio -60 ⇒ principaisDesvios, restrição ⇒ topRiscos)
        // devem aparecer, cada um com o dado real, nunca "Nenhum dado".
        $componente->assertSee('🔴 Crítico')
            ->assertSee('🟠 Relevante')
            ->assertSee('Restrição vencida de teste')
            ->assertDontSee('Nenhum dado encontrado');

        $this->assertNotEmpty($componente->instance()->diagnostico['decisoesPrioritarias']);
        $this->assertNotEmpty($componente->instance()->diagnostico['principaisDesvios']);
        $this->assertNotEmpty($componente->instance()->diagnostico['topRiscos']);
    }

    public function test_banner_tudo_sob_controle_quando_nao_ha_critico_nem_relevante(): void
    {
        // Cenário mínimo (mesmo do teste A) — pacote sem atividades, sem
        // AvancoPeriodo, sem Restricao: nenhum desvio negativo, nenhum
        // risco, nenhuma decisão prioritária.
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $diag = $componente->instance()->diagnostico;
        $this->assertEmpty($diag['decisoesPrioritarias']);
        $this->assertEmpty($diag['principaisDesvios']);
        $this->assertEmpty($diag['topRiscos']);

        $componente->assertSee('Tudo sob controle nesta semana')
            ->assertDontSee('🔴 Crítico')
            ->assertDontSee('🟠 Relevante');
    }

    public function test_contexto_continua_aparecendo_quando_ha_proximos_eventos(): void
    {
        $atividadeEvento = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $this->pacote->id,
            'is_marco' => false,
            'caminho_critico' => false,
            'fora_do_cronograma' => false,
            'codigo_cronograma' => '1.1',
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividadeEvento->id,
            'inicio_planejado' => null,
            'data_termino' => '2026-06-24', // periodo_referencia (15/06) + 1 semana
            'baseline_inicio' => null,
            'baseline_termino' => null,
        ]);

        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $this->assertNotEmpty($componente->instance()->diagnostico['proximosEventosRelevantes']);
        $componente->assertSee('🔵 Contexto')
            ->assertSee('Próximos Eventos');
    }

    public function test_pontos_de_atencao_continuam_funcionando_abaixo_do_diagnostico(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $componente->assertSet('etapa', '3')
            ->assertSee('Pontos de atenção por curva')
            ->call('adicionarPontoAtencao', $this->pacote->id)
            ->set("pontosPorCurva.{$this->pacote->id}.0.categoria", 'SUPRIMENTOS')
            ->set("pontosPorCurva.{$this->pacote->id}.0.texto", 'Atraso na entrega de aço.');

        $this->assertSame(
            'Atraso na entrega de aço.',
            $componente->get('pontosPorCurva')[$this->pacote->id][0]['texto']
        );

        // Ordem no HTML: o banner "O Radar analisou..." vem ANTES do
        // cabeçalho "Pontos de atenção por curva".
        $html = $componente->html();
        $posDiagnostico = strpos($html, 'O Radar analisou sua semana');
        $posPontos = strpos($html, 'Pontos de atenção por curva');
        $this->assertNotFalse($posDiagnostico);
        $this->assertNotFalse($posPontos);
        $this->assertLessThan($posPontos, $posDiagnostico);
    }

    // =========================================================================
    // F) salvar() — caminho novo (Report já existe)
    // =========================================================================

    public function test_fluxo_completo_ate_salvar_usa_o_report_ja_criado_com_duas_curvas(): void
    {
        Storage::fake('public');

        $outroPacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'ESTRUTURA',
            'codigo' => '2',
        ]);

        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar') // 1 -> 2
            ->call('togglePacote', $this->pacote->id)
            ->call('togglePacote', $outroPacote->id)
            ->call('avancar'); // 2 -> 3, cria o Report com 2 curvas

        $reportId = $componente->instance()->report->id;
        $curvaIds = ReportCurva::where('report_id', $reportId)->pluck('id');
        $this->assertSame(1, Report::count());
        $this->assertCount(2, $curvaIds);

        // Pontos de atenção em CADA curva, textos diferentes.
        $componente->call('adicionarPontoAtencao', $this->pacote->id)
            ->set("pontosPorCurva.{$this->pacote->id}.0.categoria", 'SUPRIMENTOS')
            ->set("pontosPorCurva.{$this->pacote->id}.0.texto", 'Atraso na entrega de aço.')
            ->call('adicionarPontoAtencao', $outroPacote->id)
            ->set("pontosPorCurva.{$outroPacote->id}.0.categoria", 'MAO_DE_OBRA')
            ->set("pontosPorCurva.{$outroPacote->id}.0.texto", 'Falta de encarregado na frente 2.')
            ->call('avancar'); // 3 -> 4

        $foto = UploadedFile::fake()->image('obra.jpg', 300, 300);
        $componente->set('novasFotos', [$foto])
            ->set('legendasFotos.0', 'Fundação concretada')
            ->call('avancar') // 4 -> 5
            ->call('salvar');

        // B/7 — nenhum segundo Report foi criado.
        $this->assertSame(1, Report::count());
        $reportFinal = Report::first();
        $this->assertSame($reportId, $reportFinal->id);

        // Curvas/desvios preservados — as MESMAS 2 curvas do passo 2, nunca
        // recriadas por salvar().
        $this->assertEqualsCanonicalizing($curvaIds->all(), ReportCurva::where('report_id', $reportId)->pluck('id')->all());

        // 8 — pontos de atenção persistidos na curva CERTA.
        $curvaPacote = ReportCurva::where('report_id', $reportId)->where('pacote_trabalho_id', $this->pacote->id)->first();
        $curvaOutro = ReportCurva::where('report_id', $reportId)->where('pacote_trabalho_id', $outroPacote->id)->first();
        $this->assertSame('Atraso na entrega de aço.', ReportPontoAtencao::where('report_curva_id', $curvaPacote->id)->value('texto'));
        $this->assertSame('Falta de encarregado na frente 2.', ReportPontoAtencao::where('report_curva_id', $curvaOutro->id)->value('texto'));

        // 9 — foto associada ao MESMO Report.
        $this->assertCount(1, $reportFinal->fotos);
        $this->assertSame($reportId, $reportFinal->fotos->first()->report_id);
        $this->assertSame('Fundação concretada', $reportFinal->fotos->first()->legenda);
        Storage::disk('public')->assertExists($reportFinal->fotos->first()->caminho_arquivo);

        $componente->assertRedirect(route('radar.relatorios.show', $reportFinal));
    }

    public function test_pontos_de_atencao_com_texto_vazio_nao_sao_persistidos_no_caminho_novo(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $reportId = $componente->instance()->report->id;

        $componente->call('adicionarPontoAtencao', $this->pacote->id) // texto vazio, nunca preenchido
            ->call('avancar')
            ->call('avancar')
            ->call('salvar');

        $curva = ReportCurva::where('report_id', $reportId)->first();
        $this->assertSame(0, ReportPontoAtencao::where('report_curva_id', $curva->id)->count());
    }

    // =========================================================================
    // Bug real relatado pelo usuário: "o botão salvar rascunho não está
    // funcionando" — causa raiz confirmada: $this->validate() em salvar()
    // pode falhar em 'novasFotos.*' (a foto anexada no Passo 4 não é uma
    // imagem válida, ou excede 5MB), mas o usuário está sempre no Passo 5
    // quando clica em "Salvar rascunho" — só o Passo 4 tem o bloco
    // @error('novasFotos.*') na tela. Sem o fix, a ValidationException
    // interrompe salvar() e o Livewire simplesmente re-renderiza o Passo 5
    // sem NENHUM indício visual do erro — clicar no botão parecia "não
    // fazer nada". O fix leva o usuário de volta pro Passo 4 (onde o erro
    // já tem UI própria) antes de deixar a exceção subir do jeito normal.
    // =========================================================================

    public function test_salvar_com_foto_invalida_no_passo_5_volta_pro_passo_4_com_erro_visivel(): void
    {
        Storage::fake('public');
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar') // 1 -> 2
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar'); // 2 -> 3, cria o Report

        $reportId = $componente->instance()->report->id;
        $this->assertSame(1, Report::count());

        $arquivoInvalido = UploadedFile::fake()->create('documento.pdf', 100);
        $componente->call('avancar') // 3 -> 4
            ->set('novasFotos', [$arquivoInvalido])
            ->call('avancar') // 4 -> 5
            ->call('salvar');

        // O erro de validação aparece (Livewire indexa 'novasFotos.*' pra
        // 'novasFotos.0' — MessageBag::has() com wildcard casa os dois).
        $componente->assertHasErrors(['novasFotos.0']);

        // O usuário é levado de volta pro Passo 4, onde o @error('novasFotos.*')
        // já existe na tela — nunca fica preso no Passo 5 sem feedback nenhum.
        $this->assertSame('4', $componente->get('etapa'));

        // Nada foi perdido: o Report criado no Passo 2 continua intacto,
        // nenhum segundo Report foi criado, e não houve redirecionamento.
        $this->assertSame(1, Report::count());
        $this->assertSame($reportId, $componente->instance()->report->id);
        $componente->assertNoRedirect();
    }

    public function test_salvar_com_foto_valida_apos_corrigir_o_erro_funciona_normalmente(): void
    {
        Storage::fake('public');
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-06-15')
            ->call('avancar')
            ->call('togglePacote', $this->pacote->id)
            ->call('avancar');

        $reportId = $componente->instance()->report->id;

        // Primeiro tenta com foto inválida (reproduz o bug) ...
        $componente->call('avancar') // 3 -> 4
            ->set('novasFotos', [UploadedFile::fake()->create('documento.pdf', 100)])
            ->call('avancar') // 4 -> 5
            ->call('salvar');

        $this->assertSame('4', $componente->get('etapa'));

        // ... corrige a foto (remove a inválida, anexa uma válida) e salva
        // de novo, sem sair do zero.
        $foto = UploadedFile::fake()->image('obra.jpg', 200, 200);
        $componente->call('removerFoto', 0)
            ->set('novasFotos', [$foto])
            ->call('avancar') // 4 -> 5
            ->call('salvar');

        $componente->assertHasNoErrors();
        $this->assertSame(1, Report::count());
        $reportFinal = Report::find($reportId);
        $this->assertCount(1, $reportFinal->fotos);
        Storage::disk('public')->assertExists($reportFinal->fotos->first()->caminho_arquivo);
        $componente->assertRedirect(route('radar.relatorios.show', $reportFinal));
    }
}
