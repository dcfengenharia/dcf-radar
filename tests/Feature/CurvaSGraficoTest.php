<?php

namespace Tests\Feature;

use App\Imports\Contracts\ImportadorCronograma;
use App\Models\CurvaAjuste;
use App\Models\Disciplina;
use App\Models\LinhaBase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CurvaSGraficoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;
    private LinhaBase $linhaBase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant     = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $importer   = app(ImportadorCronograma::class);
        $plano      = $importer->analisar(__DIR__ . '/../Fixtures/cronograma_sample.xml', $this->obra);
        $importacao = $importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        // A tela agora exige uma Linha de Base salva explicitamente —
        // sem isso, "periodos" fica vazio de propósito (ver mount() em
        // ⚡curvas.blade.php). Salva uma pra servir de padrão nos testes.
        $this->linhaBase = LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'LB inicial',
            'cronograma_importacao_id' => $importacao->id,
            'criado_por'               => $this->user->id,
        ]);
    }

    public function test_dados_grafico_batem_com_os_periodos_da_tabela(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);

        $periodos = $component->get('periodos');
        $dados    = $component->get('dadosGraficoCurvaS');

        $this->assertNotEmpty($periodos);
        $this->assertCount(count($periodos), $dados['labels']);
        $this->assertCount(count($periodos), $dados['percentualPeriodo']);
        $this->assertCount(count($periodos), $dados['percentualAcumulado']);
        $this->assertEquals('previsto', $dados['serie']);

        // O último valor acumulado do gráfico bate com o último acumulado da tabela.
        $this->assertEquals(
            round((float) end($periodos)['percentual'], 1),
            end($dados['percentualAcumulado'])
        );
    }

    public function test_sem_linha_base_salva_mostra_estado_vazio_dedicado(): void
    {
        // Obra nova, cronograma importado mas NENHUMA linha de base
        // salva — reproduz o bug relatado (curva não deve aparecer).
        $tenant = \App\Models\Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $importer = app(ImportadorCronograma::class);
        $plano    = $importer->analisar(__DIR__ . '/../Fixtures/cronograma_sample.xml', $obra);
        $importer->aplicar($plano, $obra, $user->id, 'sample.xml');

        $component = Livewire::test('pages::radar.curvas', ['obra' => $obra]);

        $this->assertNull($component->get('linhaBaseId'));
        $this->assertEmpty($component->get('periodos'));
        $component->assertSee('Nenhuma linha de base salva')
            ->assertDontSee('Pacote (EAP)');
    }

    public function test_linha_base_mais_recente_e_selecionada_por_padrao(): void
    {
        $importacaoV2 = app(ImportadorCronograma::class)->aplicar(
            app(ImportadorCronograma::class)->analisar(__DIR__ . '/../Fixtures/cronograma_v2.xml', $this->obra),
            $this->obra,
            $this->user->id,
            'v2.xml',
        );
        $lbMaisRecente = LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'LB mais recente',
            'cronograma_importacao_id' => $importacaoV2->id,
            'criado_por'               => $this->user->id,
        ]);

        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);

        $this->assertEquals($lbMaisRecente->id, $component->get('linhaBaseId'));
    }

    public function test_linha_base_solicitada_via_link_e_selecionada_mesmo_nao_sendo_a_mais_recente(): void
    {
        $importacaoV2 = app(ImportadorCronograma::class)->aplicar(
            app(ImportadorCronograma::class)->analisar(__DIR__ . '/../Fixtures/cronograma_v2.xml', $this->obra),
            $this->obra,
            $this->user->id,
            'v2.xml',
        );
        LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'LB mais recente',
            'cronograma_importacao_id' => $importacaoV2->id,
            'criado_por'               => $this->user->id,
        ]);

        // Reproduz o link "Ver curvas usando esta linha de base" da tela
        // Linhas de Base, clicado numa linha que NÃO é a mais recente —
        // o bug era ignorar isso e sempre cair na mais recente.
        $component = Livewire::withQueryParams(['linha_base_id' => $this->linhaBase->id])
            ->test('pages::radar.curvas', ['obra' => $this->obra]);

        $this->assertEquals($this->linhaBase->id, $component->get('linhaBaseId'));
    }

    public function test_trocar_linha_de_base_atualiza_o_grafico(): void
    {
        $importacaoV2 = app(ImportadorCronograma::class)->aplicar(
            app(ImportadorCronograma::class)->analisar(__DIR__ . '/../Fixtures/cronograma_v2.xml', $this->obra),
            $this->obra,
            $this->user->id,
            'v2.xml',
        );
        $lbV2 = LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'LB v2',
            'cronograma_importacao_id' => $importacaoV2->id,
            'criado_por'               => $this->user->id,
        ]);

        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->set('linhaBaseId', $this->linhaBase->id);

        $periodosV1 = $component->get('periodos');

        $component->set('linhaBaseId', $lbV2->id)->assertDispatched('curva-atualizada');

        $periodosV2 = $component->get('periodos');

        $this->assertNotEquals($periodosV1, $periodosV2);
    }

    public function test_trocar_granularidade_atualiza_labels_do_grafico(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);

        $labelsMensal = $component->get('dadosGraficoCurvaS')['labels'];

        $component->set('granularidade', 'semanal')->assertDispatched('curva-atualizada');

        $labelsSemanal = $component->get('dadosGraficoCurvaS')['labels'];

        $this->assertNotEquals($labelsMensal, $labelsSemanal);
        $this->assertStringStartsWith('Sem ', $labelsSemanal[0]);
    }

    public function test_salvar_e_remover_ajuste_disparam_atualizacao_do_grafico(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);
        $periodo   = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirEdicao', $periodo, 10.0)
            ->set('valorEdicao', '99')
            ->call('salvarAjuste')
            ->assertDispatched('curva-atualizada');

        $ajuste = CurvaAjuste::where('obra_id', $this->obra->id)->firstOrFail();

        $component->call('removerAjuste', $ajuste->id)
            ->assertDispatched('curva-atualizada');
    }

    public function test_falha_de_banco_ao_salvar_ajuste_mostra_toast_de_erro_sem_gravar(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);
        $periodo   = $component->get('periodos')[0]['periodo_inicio'];

        $conexaoReal = app('db');
        \Illuminate\Support\Facades\DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('falha forçada de teste'));

        $component->call('abrirEdicao', $periodo, 10.0)
            ->set('valorEdicao', '99')
            ->call('salvarAjuste')
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });

        \Illuminate\Support\Facades\DB::swap($conexaoReal);
        $this->assertEquals(0, CurvaAjuste::where('obra_id', $this->obra->id)->count());
    }

    public function test_limpar_filtros_dispara_atualizacao_do_grafico(): void
    {
        Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->set('etapaId', 'algum-id-fake')
            ->call('limparFiltros')
            ->assertDispatched('curva-atualizada');
    }

    public function test_salvar_ajuste_grava_escopo_do_filtro_ativo(): void
    {
        $disciplina = Disciplina::where('nome', 'Estrutura')->firstOrFail();

        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->set('disciplinaId', $disciplina->id);

        $periodo = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirEdicao', $periodo, 10.0)
            ->set('valorEdicao', '77')
            ->call('salvarAjuste');

        $ajuste = CurvaAjuste::where('obra_id', $this->obra->id)->firstOrFail();

        $this->assertEquals($disciplina->id, $ajuste->disciplina_id);
        $this->assertNull($ajuste->pacote_trabalho_id);
        $this->assertNull($ajuste->etapa_id);
        $this->assertNull($ajuste->frente_trabalho_id);
    }

    public function test_ajuste_salvo_sob_filtro_nao_aparece_na_curva_geral_apos_limpar(): void
    {
        $disciplina = Disciplina::where('nome', 'Estrutura')->firstOrFail();

        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->set('disciplinaId', $disciplina->id);

        $periodo = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirEdicao', $periodo, 10.0)
            ->set('valorEdicao', '77')
            ->call('salvarAjuste')
            ->call('limparFiltros');

        $pontoGeral = collect($component->get('periodos'))->firstWhere('periodo_inicio', $periodo);

        $this->assertNotNull($pontoGeral);
        $this->assertFalse($pontoGeral['ajustado'], 'Ajuste escopado à disciplina não deve aparecer na curva geral');
    }

    public function test_overlay_de_carregamento_esta_presente_no_markup(): void
    {
        Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->assertSeeHtml('wire:loading.delay');
    }

    public function test_grafico_traz_periodos_inicio_junto_com_os_labels(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);

        $dados = $component->get('dadosGraficoCurvaS');

        $this->assertArrayHasKey('periodosInicio', $dados);
        $this->assertCount(count($dados['labels']), $dados['periodosInicio']);
    }

    public function test_abrir_detalhe_periodo_carrega_atividades_e_abre_modal(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);
        $periodo   = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirDetalhePeriodo', $periodo)
            ->assertSet('modalDetalhePeriodo', true)
            ->assertSet('periodoDetalhado', $periodo)
            ->assertSee('Detalhe do período')
            ->assertDispatched('periodo-detalhado');

        $this->assertNotEmpty($component->get('atividadesPeriodo'));
        $this->assertNotEmpty($component->get('arvorePeriodo'));
        $this->assertNotEmpty($component->get('resumoDisciplinaPeriodo'));
    }

    public function test_arvore_periodo_hierarquiza_pacote_e_atividade(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->set('granularidade', 'mensal');
        $periodo = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirDetalhePeriodo', $periodo);

        $arvore = $component->get('arvorePeriodo');
        $this->assertEquals('pacote', $arvore[0]['tipo']);
        $this->assertEquals('Pacote A', $arvore[0]['nome']);
        $this->assertNotNull(collect($arvore)->firstWhere('tipo', 'atividade'));
    }

    public function test_exportar_detalhe_periodo_dispara_download_de_xlsx(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);
        $periodo   = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirDetalhePeriodo', $periodo)
            ->call('exportarDetalhePeriodo')
            ->assertFileDownloaded();
    }

    public function test_modal_detalhe_periodo_mostra_datas_de_linha_de_base_por_atividade(): void
    {
        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra]);
        $periodo   = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirDetalhePeriodo', $periodo)
            ->assertSee('Início LB')
            ->assertSee('Término LB');

        $atividades = $component->get('atividadesPeriodo');
        $this->assertNotEmpty($atividades);
        foreach ($atividades as $a) {
            $this->assertNotNull($a['baseline_inicio']);
            $this->assertNotNull($a['baseline_termino']);
        }
    }

    public function test_linhas_de_atividade_no_modal_nao_tem_indentacao_em_escada(): void
    {
        // Monta uma EAP com 2 níveis de pacote (raiz > subpacote) na mão —
        // nenhuma fixture XML do projeto tem esse nível de aninhamento —
        // pra ter uma atividade em nivel=2, onde o bug antigo (padding
        // cascateado por $linha['nivel']) e o comportamento novo (padding
        // fixo) produzem valores DIFERENTES e o teste vira significativo.
        $pacoteRaiz = \App\Models\PacoteTrabalho::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id'   => $this->obra->id,
            'nome'      => 'Raiz',
            'codigo'    => '1',
        ]);
        $subPacote = \App\Models\PacoteTrabalho::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id'   => $this->obra->id,
            'parent_id' => $pacoteRaiz->id,
            'nome'      => 'Sub',
            'codigo'    => '1.1',
        ]);
        $atividade = \App\Models\Atividade::factory()->create([
            'tenant_id'          => $this->obra->tenant_id,
            'obra_id'            => $this->obra->id,
            'pacote_trabalho_id' => $subPacote->id,
            'baseline_inicio'    => '2024-01-01',
            'baseline_termino'   => '2024-01-10',
        ]);

        \App\Models\AvancoPeriodo::create([
            'tenant_id'                => $this->obra->tenant_id,
            'cronograma_importacao_id' => $this->linhaBase->cronograma_importacao_id,
            'atividade_id'             => $atividade->id,
            'granularidade'            => \App\Enums\GranularidadePeriodo::Mensal,
            'serie'                    => \App\Enums\SerieAvanco::Previsto,
            'periodo_inicio'           => '2024-01-01',
            'horas'                    => 10,
        ]);

        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->set('linhaBaseId', $this->linhaBase->id)
            ->set('granularidade', 'mensal');

        $component->call('abrirDetalhePeriodo', '2024-01-01');

        // Pacote raiz (nivel 0) e subpacote (nivel 1) continuam indentando
        // por nível — mostra a hierarquia da EAP. Atividade (nivel 2) usa
        // padding FIXO de 20px — mesmo padrão do Lookahead — nunca 40px
        // (o que o cálculo antigo, nivel*20, teria produzido).
        $component->assertSeeHtml('padding-left: 0px')
            ->assertSeeHtml('padding-left: 20px')
            ->assertDontSeeHtml('padding-left: 40px');
    }

    public function test_detalhe_periodo_respeita_filtro_ativo_na_tela(): void
    {
        $disciplina = Disciplina::where('nome', 'Estrutura')->firstOrFail();

        $component = Livewire::test('pages::radar.curvas', ['obra' => $this->obra])
            ->set('disciplinaId', $disciplina->id);

        $periodo = $component->get('periodos')[0]['periodo_inicio'];

        $component->call('abrirDetalhePeriodo', $periodo);

        $atividades = $component->get('atividadesPeriodo');
        $this->assertNotEmpty($atividades);
        foreach ($atividades as $a) {
            $this->assertEquals('Estrutura', $a['disciplina']);
        }
    }
}
