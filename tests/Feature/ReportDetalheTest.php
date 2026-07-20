<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportFoto;
use App\Models\ReportPontoAtencao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReportDetalheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function usuarioComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    private function criarReport(StatusReport $status): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'status' => $status->value,
        ]);
    }

    public function test_encarregado_nao_acessa_detalhe_de_rascunho(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $encarregado = $this->usuarioComPapel(Papel::Encarregado);

        // mount() sem acesso é tratado como navegação de página cheia —
        // App\Exceptions\Handler::render() redireciona com flash.popup em
        // vez do 403 cru (diferente de uma ação ->call() num componente já
        // montado, que continua retornando 403 puro).
        Livewire::actingAs($encarregado)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_gerente_pode_emitir_rascunho(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->call('emitir')
            ->assertDispatched('show-toast');

        $report->refresh();
        $this->assertTrue($report->estaEmitido());
        $this->assertSame($gerente->id, $report->emitido_por);
    }

    public function test_engenheiro_nao_alcanca_um_rascunho_para_emitir(): void
    {
        // Emitir exige o mesmo papel mínimo (GerentePlanejamento) que ver um
        // rascunho — um Engenheiro já é barrado no próprio acesso ao report,
        // então nem chega perto da ação de emitir.
        $rascunho = $this->criarReport(StatusReport::Rascunho);
        $engenheiro = $this->usuarioComPapel(Papel::Engenheiro);

        // mount() sem acesso é tratado como navegação de página cheia — ver
        // comentário em test_encarregado_nao_acessa_detalhe_de_rascunho.
        Livewire::actingAs($engenheiro)
            ->test('pages::radar.relatorio-detalhe', ['report' => $rascunho])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_comentario_so_funciona_apos_emissao(): void
    {
        $emitido = $this->criarReport(StatusReport::Emitido);
        $encarregado = $this->usuarioComPapel(Papel::Encarregado);

        Livewire::actingAs($encarregado)
            ->test('pages::radar.relatorio-detalhe', ['report' => $emitido])
            ->set('novoComentario', 'Ótimo avanço nesta semana.')
            ->call('adicionarComentario')
            ->assertDispatched('show-toast');

        $this->assertDatabaseHas('report_comentarios', [
            'report_id' => $emitido->id,
            'autor_id' => $encarregado->id,
            'comentario' => 'Ótimo avanço nesta semana.',
        ]);
    }

    public function test_dados_graficos_monta_series_com_percentual_acumulado(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'total_hh_previsto' => 100,
        ]);

        $curva->datapoints()->create([
            'tenant_id' => $this->tenant->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => '2026-01-01',
            'horas' => 80,
            'percentual_acumulado' => 80,
        ]);
        $curva->datapoints()->create([
            'tenant_id' => $this->tenant->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Realizado->value,
            'periodo_inicio' => '2026-01-01',
            'horas' => 60,
            'percentual_acumulado' => 60,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $dados = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->dadosGraficos;

        $mensal = $dados[0]['mensal'];
        $this->assertSame(['JAN/26'], $mensal['labels']);
        // barras = % do período (horas ÷ total_hh_previsto), nunca HH cru
        $this->assertEquals([80.0], $mensal['barras']['previsto']['data']);
        $this->assertEquals([60.0], $mensal['barras']['realizado']['data']);
        $this->assertEquals([80.0], $mensal['linhas']['previsto']['data']);
        $this->assertEquals([60.0], $mensal['linhas']['realizado']['data']);
        // aderência = %real acumulado ÷ %previsto acumulado = 60/80*100 = 75%
        $this->assertEquals(75.0, $mensal['tabela'][0]['aderencia']);
        // sem dados semanais nesse teste -> aderência atual é null
        $this->assertNull($dados[0]['aderencia_atual']);
    }

    public function test_aderencia_atual_usa_a_ultima_semana_com_realizado_nao_a_ultima_data_gravada(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'total_hh_previsto' => 400,
        ]);

        // 4 semanas de Previsto, 100 HH cada (25% do período em cada uma).
        // Só 3 delas têm Realizado (20 HH cada, 5% do período) — a 4ª
        // (mais recente) ainda não teve o realizado atualizado.
        $semanas = ['2026-05-25' => 100, '2026-06-01' => 100, '2026-06-08' => 100, '2026-06-15' => 100];
        $acumuladoPrevisto = 0;
        foreach ($semanas as $semana => $horas) {
            $acumuladoPrevisto += $horas;
            $curva->datapoints()->create([
                'tenant_id' => $this->tenant->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $semana,
                'horas' => $horas,
                'percentual_acumulado' => $acumuladoPrevisto / 4,
            ]);
        }
        $acumuladoReal = 0;
        foreach (['2026-05-25' => 20, '2026-06-01' => 20, '2026-06-08' => 20] as $semana => $horas) {
            $acumuladoReal += $horas;
            $curva->datapoints()->create([
                'tenant_id' => $this->tenant->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Realizado->value,
                'periodo_inicio' => $semana,
                'horas' => $horas,
                'percentual_acumulado' => $acumuladoReal / 4,
            ]);
        }

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $dados = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->dadosGraficos;

        // Aderência da semana de 08/06 (a última COM realizado) = %realizado
        // do período ÷ %previsto do período = (20/400*100) ÷ (100/400*100)
        // = 5% ÷ 25% * 100 = 20% — a semana de 15/06 não entra por não ter
        // realizado ainda.
        $this->assertEqualsWithDelta(20.0, $dados[0]['aderencia_atual'], 0.01);
    }

    // -------------------------------------------------------------------------
    // Edição de pontos de atenção e fotos enquanto rascunho
    // -------------------------------------------------------------------------

    public function test_gerente_pode_adicionar_ponto_de_atencao_em_report_rascunho(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->call('adicionarPontoAtencaoEdit', $curva->id)
            ->set("pontosAtencaoEdit.{$curva->id}.0.categoria", 'SUPRIMENTOS')
            ->set("pontosAtencaoEdit.{$curva->id}.0.texto", 'Atraso na entrega de aço.')
            ->call('salvarPontosAtencao', $curva->id)
            ->assertDispatched('show-toast');

        $this->assertDatabaseHas('report_pontos_atencao', [
            'report_curva_id' => $curva->id,
            'categoria' => 'SUPRIMENTOS',
            'texto' => 'Atraso na entrega de aço.',
        ]);
    }

    public function test_remover_ponto_de_atencao_edit_apaga_do_banco(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
        ]);
        $ponto = $curva->pontosAtencao()->create([
            'tenant_id' => $this->tenant->id,
            'categoria' => null,
            'texto' => 'Chuva na semana.',
            'ordem' => 0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->call('removerPontoAtencaoEdit', $curva->id, 0);

        $this->assertDatabaseMissing('report_pontos_atencao', ['id' => $ponto->id]);
    }

    public function test_nao_pode_editar_pontos_de_atencao_apos_emissao(): void
    {
        // Uma vez emitido, o report vira somente-leitura (ver ReportPolicy::
        // update) — confirma que a ação de edição é bloqueada mesmo que a
        // UI seja contornada, não só escondida.
        $report = $this->criarReport(StatusReport::Emitido);
        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
        ]);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->call('adicionarPontoAtencaoEdit', $curva->id)
            ->assertForbidden();
    }

    public function test_gerente_pode_enviar_e_remover_fotos_em_report_rascunho(): void
    {
        Storage::fake('public');

        $report = $this->criarReport(StatusReport::Rascunho);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->set('novasFotosUpload', [UploadedFile::fake()->image('canteiro.jpg')])
            ->set('legendasNovasFotosUpload', ['Vista geral do canteiro'])
            ->call('enviarFotos')
            ->assertDispatched('show-toast');

        $this->assertDatabaseHas('report_fotos', [
            'report_id' => $report->id,
            'legenda' => 'Vista geral do canteiro',
        ]);

        $foto = ReportFoto::where('report_id', $report->id)->first();
        Storage::disk('public')->assertExists($foto->caminho_arquivo);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->call('removerFoto', $foto->id);

        $this->assertDatabaseMissing('report_fotos', ['id' => $foto->id]);
        Storage::disk('public')->assertMissing($foto->caminho_arquivo);
    }

    public function test_salvar_legenda_foto_atualiza_legenda_existente(): void
    {
        Storage::fake('public');

        $report = $this->criarReport(StatusReport::Rascunho);
        $foto = $report->fotos()->create([
            'tenant_id' => $this->tenant->id,
            'caminho_arquivo' => 'report-fotos/teste.jpg',
            'legenda' => 'Legenda antiga',
            'ordem' => 0,
        ]);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->set("legendasFotosEdit.{$foto->id}", 'Legenda nova')
            ->call('salvarLegendaFoto', $foto->id);

        $this->assertSame('Legenda nova', $foto->fresh()->legenda);
    }

    public function test_nao_pode_enviar_fotos_apos_emissao(): void
    {
        Storage::fake('public');

        $report = $this->criarReport(StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->set('novasFotosUpload', [UploadedFile::fake()->image('foto.jpg')])
            ->call('enviarFotos')
            ->assertForbidden();
    }

    public function test_gerente_pode_editar_titulo_de_report_em_rascunho(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->set('tituloEdit', 'Report Semana 26 (revisado)')
            ->call('salvarTitulo')
            ->assertDispatched('show-toast');

        $this->assertSame('Report Semana 26 (revisado)', $report->fresh()->titulo);
    }

    public function test_nao_pode_editar_titulo_apos_emissao(): void
    {
        $report = $this->criarReport(StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->set('tituloEdit', 'Tentativa após emissão')
            ->call('salvarTitulo')
            ->assertForbidden();
    }

    public function test_pagina_de_detalhe_mostra_aviso_de_precisao_do_xml(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Aviso de precisão')
            ->assertSee('~0,3%', false);
    }
}
