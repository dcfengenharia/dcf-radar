<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReportWizardTest extends TestCase
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

    public function test_usuario_sem_papel_minimo_nao_acessa_o_assistente(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);

        $this->actingAs($encarregado);

        // mount() sem acesso é tratado como navegação de página cheia —
        // App\Exceptions\Handler::render() redireciona com flash.popup em
        // vez do 403 cru (diferente de uma ação ->call() num componente já
        // montado, que continua retornando 403 puro).
        Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_pagina_do_assistente_carrega_chartjs(): void
    {
        // Bug real: a prévia da curva no passo 3 nunca aparecia porque
        // resources/views/app/radar/relatorio-novo.blade.php (o wrapper da
        // página) nunca incluía @section('vendor-script') com o chartjs.js
        // — diferente de relatorio-detalhe.blade.php, que já inclui. Sem
        // isso, window.Chart é undefined na página do assistente e a
        // promise de renderizarPreview() falha em silêncio (sem erro
        // visível), então nenhuma curva aparecia nunca.
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id]);
        \App\Support\ObraContext::set($this->obra);
        $this->actingAs($this->gerente);

        $this->get(route('radar.relatorios.novo'))
            ->assertOk()
            ->assertSee('assets/vendor/libs/chartjs/chartjs.js', false);
    }

    public function test_avancar_exige_periodo_valido_na_etapa_1(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '')
            ->call('avancar')
            ->assertHasErrors('periodoReferencia')
            ->assertSet('etapa', '1');
    }

    public function test_avancar_exige_ao_menos_uma_curva_na_etapa_2(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('etapa', '2')
            ->call('avancar')
            ->assertHasErrors('ordemCurvas')
            ->assertSet('etapa', '2');
    }

    public function test_selecionar_obra_inteira_e_pacote_monta_ordem_curvas(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->call('toggleObraInteira')
            ->call('togglePacote', $this->pacote->id);

        $this->assertEquals(['obra', $this->pacote->id], $componente->get('ordemCurvas'));
    }

    public function test_mover_curva_troca_a_ordem(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->call('toggleObraInteira')
            ->call('togglePacote', $this->pacote->id)
            ->call('moverCurva', $this->pacote->id, -1);

        $this->assertEquals([$this->pacote->id, 'obra'], $componente->get('ordemCurvas'));
    }

    public function test_desmarcar_pacote_remove_seus_pontos_de_atencao(): void
    {
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->call('togglePacote', $this->pacote->id)
            ->call('adicionarPontoAtencao', $this->pacote->id);

        $this->assertCount(1, $componente->get('pontosPorCurva')[$this->pacote->id]);

        $componente->call('togglePacote', $this->pacote->id);

        $this->assertArrayNotHasKey($this->pacote->id, $componente->get('pontosPorCurva'));
    }

    public function test_salvar_cria_report_com_curva_pontos_de_atencao_e_fotos(): void
    {
        Storage::fake('public');
        $this->actingAs($this->gerente);

        $foto = UploadedFile::fake()->image('obra.jpg', 300, 300);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->set('periodoReferencia', '2026-01-19')
            ->call('togglePacote', $this->pacote->id)
            ->call('adicionarPontoAtencao', $this->pacote->id)
            ->set("pontosPorCurva.{$this->pacote->id}.0.categoria", 'SUPRIMENTOS')
            ->set("pontosPorCurva.{$this->pacote->id}.0.texto", 'Atraso na entrega de aço.')
            ->set('novasFotos', [$foto])
            ->set('legendasFotos.0', 'Fundação concretada')
            ->call('salvar');

        $report = Report::first();
        $this->assertNotNull($report);
        $this->assertTrue($report->estaRascunho());
        $this->assertCount(1, $report->curvas);

        $curva = $report->curvas->first();
        $this->assertSame($this->pacote->id, $curva->pacote_trabalho_id);
        $this->assertCount(1, $curva->pontosAtencao);
        $this->assertSame('SUPRIMENTOS', $curva->pontosAtencao->first()->categoria);

        $this->assertCount(1, $report->fotos);
        $foto = $report->fotos->first();
        $this->assertSame('Fundação concretada', $foto->legenda);
        Storage::disk('public')->assertExists($foto->caminho_arquivo);

        $componente->assertRedirect(route('radar.relatorios.show', $report));
    }

    public function test_previa_do_grafico_usa_total_previsto_como_denominador_do_realizado(): void
    {
        // Baseline: 100 (jan) + 100 (fev) = 200 total. Realizado: só 50.
        // Se o bug existisse, %real acumulado seria 50/50*100=100%.
        // Correto: 50/200*100=25%.
        foreach ([['2026-01-01', 100], ['2026-02-01', 100]] as [$periodo, $horas]) {
            $atividade = Atividade::factory()->create([
                'tenant_id' => $this->tenant->id,
                'obra_id' => $this->obra->id,
                'pacote_trabalho_id' => $this->pacote->id,
            ]);
            AvancoPeriodo::create([
                'tenant_id' => $this->tenant->id,
                'cronograma_importacao_id' => $this->importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Mensal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $periodo,
                'horas' => $horas,
            ]);
        }

        $atividadeReal = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $this->pacote->id,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividadeReal->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Realizado->value,
            'periodo_inicio' => '2026-01-01',
            'horas' => 50,
        ]);

        $this->actingAs($this->gerente);

        $preview = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->call('togglePacote', $this->pacote->id)
            ->instance()
            ->dadosGraficosPreview;

        // Realizado só tem dado em janeiro (o único período com AvancoPeriodo
        // de Realizado); fevereiro fica null nesta série.
        $realizado = $preview[0]['mensal']['linhas']['realizado']['data'];

        $this->assertEquals(25.0, $realizado[0]);
    }

    public function test_previa_inclui_semanal_e_aderencia_da_ultima_semana_atualizada(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $this->pacote->id,
        ]);

        // Mensal Previsto = 400 HH — usado por totalHhBaseline() como o
        // total da curva (denominador dos %).
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => now()->startOfMonth()->toDateString(),
            'horas' => 400,
        ]);

        // 4 semanas de Previsto (100 HH cada, 25% do período cada) dentro da
        // janela de 4 semanas que termina na semana de referência (hoje).
        $semanaRef = now()->startOfWeek();
        $semanas = [
            $semanaRef->copy()->subWeeks(3)->toDateString(),
            $semanaRef->copy()->subWeeks(2)->toDateString(),
            $semanaRef->copy()->subWeeks(1)->toDateString(),
            $semanaRef->copy()->toDateString(),
        ];
        foreach ($semanas as $semana) {
            AvancoPeriodo::create([
                'tenant_id' => $this->tenant->id,
                'cronograma_importacao_id' => $this->importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $semana,
                'horas' => 100,
            ]);
        }

        // Realizado só nas 3 primeiras semanas (80 HH cada, 20% do período)
        // — a semana mais recente ainda não foi atualizada.
        foreach (array_slice($semanas, 0, 3) as $semana) {
            AvancoPeriodo::create([
                'tenant_id' => $this->tenant->id,
                'cronograma_importacao_id' => $this->importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Realizado->value,
                'periodo_inicio' => $semana,
                'horas' => 80,
            ]);
        }

        $this->actingAs($this->gerente);

        $preview = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->call('togglePacote', $this->pacote->id)
            ->instance()
            ->dadosGraficosPreview;

        $semanal = $preview[0]['semanal'];
        $this->assertCount(4, $semanal['labels']);

        // Aderência = %realizado do período ÷ %previsto do período da
        // última semana COM realizado = (80/400*100) ÷ (100/400*100)
        // = 20% ÷ 25% * 100 = 80% — a 4ª semana (sem realizado) é ignorada.
        $this->assertEqualsWithDelta(80.0, $preview[0]['aderencia_atual'], 0.01);
    }

    public function test_dados_graficos_preview_para_js_e_chamavel_como_acao_e_reflete_a_curva_escolhida(): void
    {
        // Bug corrigido: o @script do passo 3 embutia a prévia via
        // @json($this->dadosGraficosPreview) — um método #[Computed] só
        // pode ser lido como propriedade DENTRO do componente/Blade, e o
        // @script roda uma única vez (ainda no passo 1, com nenhuma curva
        // selecionada), então a prévia nunca aparecia. A correção busca os
        // dados ao vivo via uma ação Livewire comum
        // (dadosGraficosPreviewParaJs()), chamável via $wire.metodo() do
        // JS — este teste confere que ela é de fato uma AÇÃO (não um
        // #[Computed], que lançaria CannotCallComputedDirectlyException se
        // invocada via ->call()) e que reflete a curva escolhida em tempo
        // real, não um estado vazio congelado.
        $this->actingAs($this->gerente);

        $componente = Livewire::test('pages::radar.relatorio-novo', ['obra' => $this->obra])
            ->call('togglePacote', $this->pacote->id);

        // Confere que é uma ação Livewire comum, não um #[Computed] — se
        // tivesse o atributo, chamá-la via $wire.metodo() no JS lançaria
        // CannotCallComputedDirectlyException (a razão de precisar deste
        // método wrapper em primeiro lugar).
        $reflection = new \ReflectionMethod($componente->instance(), 'dadosGraficosPreviewParaJs');
        $this->assertEmpty($reflection->getAttributes(\Livewire\Attributes\Computed::class));

        $preview = $componente->instance()->dadosGraficosPreviewParaJs();

        $this->assertCount(1, $preview);
        $this->assertSame($this->pacote->id, $preview[0]['chave']);
    }
}
