<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemAtividade;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\StatusReport;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AtividadeComentario;
use App\Models\AtividadeSnapshot;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LookaheadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::radar.lookahead', ['obra' => $this->obra]);
    }

    public function test_filtro_por_baseline_mostra_apenas_atividades_com_baseline_na_janela(): void
    {
        $noPrazoBaseline = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Dentro do Prazo Baseline XYZ123',
            'baseline_inicio' => now()->addDays(5),
            'inicio_planejado' => now()->addDays(50),
        ]);

        $foraDoPrazoBaseline = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Fora do Prazo Baseline ABC456',
            'baseline_inicio' => now()->addDays(50),
            'inicio_planejado' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'baseline')
            ->set('janelaDias', 30)
            ->assertSee($noPrazoBaseline->nome)
            ->assertDontSee($foraDoPrazoBaseline->nome);
    }

    public function test_filtro_por_tendencia_mostra_apenas_atividades_com_inicio_planejado_na_janela(): void
    {
        $noPrazoTendencia = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Dentro do Prazo Tendencia XYZ123',
            'baseline_inicio' => now()->addDays(50),
        ]);

        $foraDoPrazoTendencia = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Fora do Prazo Tendencia ABC456',
            'baseline_inicio' => now()->addDays(5),
        ]);

        $avanco = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now(),
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $noPrazoTendencia->id,
            'inicio_planejado' => now()->addDays(5),
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $foraDoPrazoTendencia->id,
            'inicio_planejado' => now()->addDays(50),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 30)
            ->assertSee($noPrazoTendencia->nome)
            ->assertDontSee($foraDoPrazoTendencia->nome);
    }

    public function test_ocultar_concluidas_exclui_atividades_com_status_concluido(): void
    {
        $concluida = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
            'status' => StatusAtividade::Concluido->value,
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('ocultarConcluidas', true)
            ->assertDontSee($concluida->nome)
            ->set('ocultarConcluidas', false)
            ->assertSee($concluida->nome);
    }

    public function test_criar_atividade_manual_seta_origem_manual_e_replica_baseline(): void
    {
        $this->componente()
            ->call('abrirModalNovaAtividade')
            ->set('nomeNovaAtividade', 'Montagem de forma da viga V12')
            ->set('inicioNovaAtividade', now()->addDays(3)->toDateString())
            ->set('terminoNovaAtividade', now()->addDays(6)->toDateString())
            ->call('salvarAtividade');

        $atividade = Atividade::where('nome', 'Montagem de forma da viga V12')->firstOrFail();

        $this->assertEquals(OrigemAtividade::Manual, $atividade->origem);
        $this->assertTrue($atividade->baseline_inicio->isSameDay($atividade->inicio_planejado));
        $this->assertTrue($atividade->baseline_termino->isSameDay($atividade->data_termino));
    }

    public function test_criar_restricao_a_partir_da_tela(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->componente()
            ->call('abrirModalRestricao', $atividade->id)
            ->set('descricaoNova', 'Falta liberação do projeto executivo')
            ->call('salvarRestricao');

        $this->assertDatabaseHas('restricoes', [
            'atividade_id' => $atividade->id,
            'descricao' => 'Falta liberação do projeto executivo',
        ]);
    }

    public function test_gerar_plano_semanal_compromete_apenas_as_prontas_do_filtro(): void
    {
        $pronta = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
            'status' => StatusAtividade::Planejado->value,
        ]);

        $naoPronta = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
            'status' => StatusAtividade::Planejado->value,
        ]);
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $naoPronta->id,
            'bloqueante' => true,
            'status' => 'aberta',
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('gerarPlanoSemanal');

        $this->assertEquals(StatusAtividade::Comprometido, $pronta->fresh()->status);
        $this->assertEquals(StatusAtividade::Planejado, $naoPronta->fresh()->status);
    }

    public function test_usuario_sem_papel_suficiente_nao_pode_criar_atividade_manual(): void
    {
        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('abrirModalNovaAtividade')
            ->assertForbidden();
    }

    public function test_usuario_sem_papel_suficiente_nao_pode_criar_restricao(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('abrirModalRestricao', $atividade->id)
            ->assertForbidden();
    }

    public function test_filtro_inclui_atividade_que_so_termina_dentro_da_janela(): void
    {
        // Início fora da janela de 30 dias, mas término dentro dela
        $terminaNaJanela = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Termina na Janela XYZ123',
            'inicio_planejado' => now()->subDays(40),
            'data_termino' => now()->addDays(10),
            'baseline_inicio' => now()->subDays(40),
            'baseline_termino' => now()->addDays(10),
        ]);

        $foraDaJanela = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Fora da Janela ABC456',
            'inicio_planejado' => now()->addDays(50),
            'data_termino' => now()->addDays(60),
            'baseline_inicio' => now()->addDays(50),
            'baseline_termino' => now()->addDays(60),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 30)
            ->assertSee($terminaNaJanela->nome)
            ->assertDontSee($foraDaJanela->nome);
    }

    public function test_lista_mostra_contagem_de_restricoes_bloqueantes_e_nao_bloqueantes(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
            'status' => 'aberta',
        ]);
        Restricao::factory()->count(2)->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => false,
            'status' => 'aberta',
        ]);

        $teste = $this->componente()->set('fonteData', 'tendencia');
        $teste->assertSee($atividade->nome);

        $linha = $teste->instance()->atividades->first();
        $this->assertEquals(1, $linha['restricoesBloq']);
        $this->assertEquals(2, $linha['restricoesNaoBloq']);
    }

    public function test_criar_atividade_manual_com_frente_de_trabalho(): void
    {
        $frente = FrenteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Berço 3',
        ]);

        $this->componente()
            ->call('abrirModalNovaAtividade')
            ->set('nomeNovaAtividade', 'Concretagem do berço 3')
            ->set('frenteTrabalhoIdNova', $frente->id)
            ->set('inicioNovaAtividade', now()->addDays(3)->toDateString())
            ->set('terminoNovaAtividade', now()->addDays(6)->toDateString())
            ->call('salvarAtividade');

        $atividade = Atividade::where('nome', 'Concretagem do berço 3')->firstOrFail();
        $this->assertEquals($frente->id, $atividade->frente_trabalho_id);
    }

    public function test_busca_filtra_por_nome_da_tarefa(): void
    {
        $achada = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Concretagem do berço 3',
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);
        $naoAchada = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Montagem de forma',
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('search', 'berço 3')
            ->assertSee($achada->nome)
            ->assertDontSee($naoAchada->nome);
    }

    public function test_filtro_por_etapa(): void
    {
        $etapa = Etapa::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $comEtapa = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'etapa_id' => $etapa->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);
        $semEtapa = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('etapaIdFiltro', $etapa->id)
            ->assertSee($comEtapa->nome)
            ->assertDontSee($semEtapa->nome);
    }

    public function test_hierarquia_agrupa_atividade_sob_o_pacote_correto(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Berço 3',
            'codigo' => '1.2',
        ]);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->instance()->linhasArvore;

        $tipos = collect($linhas)->pluck('tipo');
        $this->assertContains('pacote', $tipos);
        $this->assertContains('atividade', $tipos);

        $noPacote = collect($linhas)->firstWhere('tipo', 'pacote');
        $noAtividade = collect($linhas)->firstWhere('tipo', 'atividade');
        $this->assertEquals($pacote->id, $noPacote['pacote']->id);
        $this->assertEquals($atividade->id, $noAtividade['row']['atividade']->id);
        $this->assertContains($pacote->id, $noAtividade['ancestrais']);
    }

    public function test_selecionar_importacao_de_tendencia_usa_datas_do_snapshot(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(50), // ao vivo — nunca usado pra tendência
            'data_termino' => now()->addDays(55),
            'baseline_inicio' => now()->addDays(50), // baseline também fora da janela
            'baseline_termino' => now()->addDays(55),
        ]);

        $importacaoAntiga = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->subDays(10),
        ]);

        // No snapshot antigo, a atividade estava planejada pra daqui 5 dias (dentro da janela)
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $importacaoAntiga->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => now()->addDays(5),
            'data_termino' => now()->addDays(10),
        ]);

        // Por padrão (sem seleção explícita), a única importação de Avanço
        // disponível já É a "mais recente" — usada automaticamente, mesmo
        // sem o usuário escolher nada no filtro.
        $this->componente()
            ->set('fonteData', 'tendencia')
            ->assertSee($atividade->nome);

        // Selecionando a mesma importação explicitamente: mesmo resultado.
        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('tendenciaImportacaoId', $importacaoAntiga->id)
            ->assertSee($atividade->nome);
    }

    public function test_exportar_pdf_e_excel_do_lookahead(): void
    {
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('exportarPdf')
            ->assertFileDownloaded();

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('exportarExcel')
            ->assertFileDownloaded();
    }

    public function test_pacotes_sao_ordenados_numericamente_e_nao_como_string(): void
    {
        $pai = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Pai',
            'codigo' => '5.1',
        ]);
        $dez = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pai->id,
            'nome' => 'Item 10',
            'codigo' => '5.1.10',
        ]);
        $tres = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pai->id,
            'nome' => 'Item 3',
            'codigo' => '5.1.3',
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $dez->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $tres->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->instance()->linhasArvore;

        $idsDeCodigo = collect($linhas)
            ->where('tipo', 'pacote')
            ->pluck('pacote.codigo')
            ->values();

        $this->assertEquals(['5.1', '5.1.3', '5.1.10'], $idsDeCodigo->all());
    }

    public function test_atividade_orfa_com_codigo_e_intercalada_entre_pacotes_raiz(): void
    {
        $pacote1 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'codigo' => '1',
        ]);
        $pacote3 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'codigo' => '3',
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote1->id, 'codigo_cronograma' => '1.1',
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote3->id, 'codigo_cronograma' => '3.1',
        ]);
        $orfa = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => null, 'codigo_cronograma' => '2', 'nome' => 'Orfa Meio',
        ]);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->set('janelaDias', 0)->instance()->linhasArvore;

        $sequenciaRaiz = collect($linhas)->where('nivel', 0)->map(function ($linha) {
            return $linha['tipo'] === 'pacote' ? $linha['pacote']->codigo : $linha['row']['atividade']->id;
        })->values();

        $this->assertEquals(
            [$pacote1->codigo, $orfa->id, $pacote3->codigo],
            $sequenciaRaiz->all()
        );
    }

    public function test_marco_no_inicio_do_cronograma_aparece_primeiro_nao_por_ultimo(): void
    {
        PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'codigo' => '2',
        ]);
        $marcoInicio = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => null, 'codigo_cronograma' => '1',
            'is_marco' => true, 'nome' => 'INÍCIO',
        ]);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->set('janelaDias', 0)->instance()->linhasArvore;

        $primeiraLinha = collect($linhas)->first();
        $this->assertEquals('atividade', $primeiraLinha['tipo']);
        $this->assertEquals($marcoInicio->id, $primeiraLinha['row']['atividade']->id);
    }

    public function test_ordem_dentro_do_grupo_respeita_codigo_cronograma_mesmo_com_datas_iguais(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'codigo' => '5.1',
        ]);
        $mesmaData = now()->addDays(10);
        $dez = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.10',
            'inicio_planejado' => $mesmaData, 'nome' => 'Zebra',
        ]);
        $tres = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.3',
            'inicio_planejado' => $mesmaData, 'nome' => 'Abacate',
        ]);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->set('janelaDias', 0)->instance()->linhasArvore;

        $idsNaOrdem = collect($linhas)->where('tipo', 'atividade')->pluck('row.atividade.id')->values();
        $this->assertEquals([$tres->id, $dez->id], $idsNaOrdem->all());
    }

    public function test_ordem_manual_continua_vencendo_codigo_cronograma_no_lookahead(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'codigo' => '5.1',
        ]);
        $codigoMaior = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.10',
            'ordem_manual' => 0, 'nome' => 'Forcada pra frente',
        ]);
        $codigoMenor = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.3',
            'ordem_manual' => 1, 'nome' => 'Forcada pra tras',
        ]);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->set('janelaDias', 0)->instance()->linhasArvore;

        $idsNaOrdem = collect($linhas)->where('tipo', 'atividade')->pluck('row.atividade.id')->values();
        $this->assertEquals([$codigoMaior->id, $codigoMenor->id], $idsNaOrdem->all());
    }

    public function test_orfa_sem_codigo_cronograma_nao_se_perde_e_cai_no_final_no_lookahead(): void
    {
        PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'codigo' => '1',
        ]);
        $orfaSemCodigo = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => null, 'codigo_cronograma' => null, 'nome' => 'Manual sem pacote',
        ]);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->set('janelaDias', 0)->instance()->linhasArvore;

        $ultimaLinha = collect($linhas)->last();
        $this->assertEquals('atividade', $ultimaLinha['tipo']);
        $this->assertEquals($orfaSemCodigo->id, $ultimaLinha['row']['atividade']->id);
    }

    public function test_filtro_por_frente_de_trabalho(): void
    {
        $frente = FrenteTrabalho::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $comFrente = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Com Frente XYZ123',
            'frente_trabalho_id' => $frente->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);
        $semFrente = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Sem Frente ABC456',
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('frenteTrabalhoIdFiltro', $frente->id)
            ->assertSee($comFrente->nome)
            ->assertDontSee($semFrente->nome);
    }

    public function test_criar_atividade_manual_com_pacote_e_etapa_aparece_no_lugar_certo_da_arvore(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Berço 3',
            'codigo' => '2.1',
        ]);
        $etapa = Etapa::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $this->componente()
            ->call('abrirModalNovaAtividade')
            ->set('nomeNovaAtividade', 'Armação da viga V20')
            ->set('pacoteTrabalhoIdNova', $pacote->id)
            ->set('etapaIdNova', $etapa->id)
            ->set('inicioNovaAtividade', now()->addDays(3)->toDateString())
            ->set('terminoNovaAtividade', now()->addDays(6)->toDateString())
            ->call('salvarAtividade');

        $atividade = Atividade::where('nome', 'Armação da viga V20')->firstOrFail();
        $this->assertEquals($pacote->id, $atividade->pacote_trabalho_id);
        $this->assertEquals($etapa->id, $atividade->etapa_id);

        $linhas = $this->componente()->set('fonteData', 'tendencia')->instance()->linhasArvore;
        $noAtividade = collect($linhas)->firstWhere('id', $atividade->id);

        $this->assertNotNull($noAtividade);
        $this->assertEquals('atividade', $noAtividade['tipo']);
        $this->assertContains($pacote->id, $noAtividade['ancestrais']);
    }

    public function test_criar_atividade_fora_da_janela_atual_dispara_aviso_em_vez_de_sucesso(): void
    {
        $componente = $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 30)
            ->call('abrirModalNovaAtividade')
            ->set('nomeNovaAtividade', 'Tarefa distante no tempo')
            ->set('inicioNovaAtividade', now()->addDays(90)->toDateString())
            ->set('terminoNovaAtividade', now()->addDays(95)->toDateString())
            ->call('salvarAtividade');

        $componente->assertDispatched('show-toast', function (string $name, array $params) {
            return $params['type'] === 'warning';
        });
    }

    public function test_percentual_de_avanco_e_exibido_na_lista(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
            'percentual_concluido' => 40,
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->assertSee($atividade->nome)
            ->assertSee('40%');
    }

    public function test_tabela_principal_mostra_datas_com_ano_de_dois_digitos(): void
    {
        // Coluna verificada é a de Linha de Base (sempre populada) — a de
        // Tendência mostra N/A nesta obra, que não tem nenhuma importação
        // de Avanço (ver test_sem_importacao_de_avanco_mostra_na_na_coluna_tendencia).
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->assertSee($atividade->baseline_inicio->format('d/m/y'))
            ->assertDontSee($atividade->baseline_inicio->format('d/m/Y'));
    }

    public function test_popup_detalhe_mostra_prazo_da_restricao(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'prazo_limite' => now()->addDays(10),
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('Prazo: ' . $restricao->prazo_limite->format('d/m/Y'));
    }

    public function test_dar_baixa_resolve_restricao_e_grava_data_informada(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $dataBaixa = now()->subDay()->toDateString();

        $this->componente()
            ->call('abrirModalBaixa', $restricao->id)
            ->set('dataBaixaNova', $dataBaixa)
            ->call('darBaixaRestricao');

        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Resolvida, $restricao->status);
        $this->assertTrue($restricao->resolvida_em->isSameDay($dataBaixa));
        $this->assertDatabaseCount('restricao_acoes', 0);
    }

    public function test_dar_baixa_com_texto_opcional_registra_acao(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->componente()
            ->call('abrirModalBaixa', $restricao->id)
            ->set('dataBaixaNova', now()->toDateString())
            ->set('textoBaixaNova', 'Liberação assinada pelo cliente.')
            ->call('darBaixaRestricao');

        $this->assertDatabaseHas('restricao_acoes', [
            'restricao_id' => $restricao->id,
            'descricao' => 'Liberação assinada pelo cliente.',
        ]);
    }

    public function test_dar_baixa_exige_data(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->componente()
            ->call('abrirModalBaixa', $restricao->id)
            ->set('dataBaixaNova', null)
            ->call('darBaixaRestricao')
            ->assertHasErrors(['dataBaixaNova' => 'required']);

        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
    }

    public function test_dar_baixa_nao_aceita_data_futura(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->componente()
            ->call('abrirModalBaixa', $restricao->id)
            ->set('dataBaixaNova', now()->addDays(5)->toDateString())
            ->call('darBaixaRestricao')
            ->assertHasErrors(['dataBaixaNova']);

        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
    }

    public function test_usuario_sem_papel_suficiente_nao_pode_dar_baixa(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('abrirModalBaixa', $restricao->id)
            ->assertForbidden();
    }

    public function test_imprimir_pdf_gera_download_com_hierarquia(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'inicio_planejado' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('imprimirPdf')
            ->assertFileDownloaded();
    }

    public function test_mover_atividade_para_baixo_troca_ordem_com_a_proxima(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $primeira = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'nome' => 'Primeira',
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);
        $segunda = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'nome' => 'Segunda',
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('moverAtividadeBaixo', $primeira->id);

        $this->assertEquals(1, $primeira->fresh()->ordem_manual);
        $this->assertEquals(0, $segunda->fresh()->ordem_manual);
    }

    public function test_mover_atividade_para_cima_troca_ordem_com_a_anterior(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $primeira = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'nome' => 'Primeira',
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);
        $segunda = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'nome' => 'Segunda',
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('moverAtividadeCima', $segunda->id);

        $this->assertEquals(1, $primeira->fresh()->ordem_manual);
        $this->assertEquals(0, $segunda->fresh()->ordem_manual);
    }

    public function test_mover_atividade_no_topo_do_grupo_nao_faz_nada(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $primeira = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'nome' => 'Primeira',
            'inicio_planejado' => now()->addDays(5),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'nome' => 'Segunda',
            'inicio_planejado' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('moverAtividadeCima', $primeira->id);

        $this->assertNull($primeira->fresh()->ordem_manual);
    }

    public function test_mover_atividade_nao_afeta_grupo_de_outro_pacote(): void
    {
        $pacoteA = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $pacoteB = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $emA1 = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteA->id,
            'nome' => 'A1',
            'inicio_planejado' => now()->addDays(5),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteA->id,
            'nome' => 'A2',
            'inicio_planejado' => now()->addDays(5),
        ]);
        $emB = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteB->id,
            'nome' => 'B1',
            'inicio_planejado' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('moverAtividadeBaixo', $emA1->id);

        $this->assertNull($emB->fresh()->ordem_manual);
    }

    public function test_usuario_sem_papel_suficiente_nao_pode_mover_atividade(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'inicio_planejado' => now()->addDays(5),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'inicio_planejado' => now()->addDays(5),
        ]);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->set('fonteData', 'tendencia')
            ->call('moverAtividadeBaixo', $atividade->id)
            ->assertForbidden();
    }

    public function test_filtro_todo_o_cronograma_mostra_atividade_fora_de_90_dias(): void
    {
        $distante = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(200),
            'data_termino' => now()->addDays(205),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 90)
            ->assertDontSee($distante->nome)
            ->set('janelaDias', 0)
            ->assertSee($distante->nome);
    }

    public function test_adicionar_comentario_na_atividade_aparece_no_detalhe(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('comentarioNovoAtividade', 'Aguardando aprovação do cliente.')
            ->call('adicionarComentarioAtividade', $atividade->id)
            ->assertSee('Aguardando aprovação do cliente.');

        $this->assertDatabaseHas('atividade_comentarios', [
            'atividade_id' => $atividade->id,
            'comentario' => 'Aguardando aprovação do cliente.',
        ]);
    }

    public function test_falha_de_banco_ao_comentar_mostra_toast_de_erro_sem_gravar(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $conexaoReal = app('db');
        \Illuminate\Support\Facades\DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('falha forçada de teste'));

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('comentarioNovoAtividade', 'Comentário que não deve ser salvo.')
            ->call('adicionarComentarioAtividade', $atividade->id)
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });

        \Illuminate\Support\Facades\DB::swap($conexaoReal);

        $this->assertEquals(0, AtividadeComentario::where('atividade_id', $atividade->id)->count());
    }

    public function test_contador_de_comentarios_aparece_na_lista(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
        ]);
        AtividadeComentario::factory()->count(2)->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->assertSee('2');
    }

    public function test_usuario_sem_papel_suficiente_nao_pode_comentar_atividade(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->set('comentarioNovoAtividade', 'Tentativa sem permissão')
            ->call('adicionarComentarioAtividade', $atividade->id)
            ->assertForbidden();
    }

    public function test_imprimir_pdf_usa_orientacao_escolhida(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
            'inicio_planejado' => now()->addDays(5),
        ]);

        $componente = $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('abrirModalImprimir')
            ->assertSet('modalImprimirAberto', true)
            ->set('orientacaoImpressao', 'portrait')
            ->call('imprimirPdf');

        $componente->assertSet('modalImprimirAberto', false);
        $componente->assertFileDownloaded();
    }

    public function test_botoes_de_colapsar_por_nivel_tem_wire_key_para_sobreviver_ao_morph(): void
    {
        // Bug real: sem wire:key, quando a quantidade de níveis muda entre
        // renders do Livewire (ex.: um pacote mais profundo aparece depois
        // de um filtro), o morph do DOM perde a associação entre o botão e
        // o nível certo, e o @click passa a não disparar colapsarAteNivel()
        // com o argumento correto — reproduzido manualmente no navegador
        // clicando o botão real vs. chamar a função direto no Alpine.
        $raiz = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $sub = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'parent_id' => $raiz->id,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $sub->id,
            'inicio_planejado' => now()->addDays(5),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 0)
            ->assertSeeHtml('wire:key="nivel-btn-0"')
            ->assertSeeHtml('wire:key="nivel-btn-1"');
    }

    public function test_sem_importacao_de_avanco_mostra_na_na_coluna_tendencia(): void
    {
        // Obra só com uma importação Baseline (nenhuma de Avanço/Ambos) — não
        // há de onde vir "tendência" de verdade, mesmo a Atividade tendo
        // inicio_planejado/data_termino ao vivo (esses vêm só da Baseline).
        CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(5),
        ]);

        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Sem Avanco XYZ123',
            'inicio_planejado' => now()->addDays(5),
            'data_termino' => now()->addDays(10),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 0)
            ->assertSee('Atividade Sem Avanco XYZ123')
            ->assertSeeHtml('>N/A<');
    }

    public function test_importacoes_disponiveis_para_tendencia_exclui_importacao_so_baseline(): void
    {
        $baseline = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(5),
        ]);
        $avanco = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->subDays(2),
        ]);

        $disponiveis = $this->componente()->instance()->importacoesDisponiveis;

        $this->assertTrue($disponiveis->contains('id', $avanco->id));
        $this->assertFalse($disponiveis->contains('id', $baseline->id));
    }

    public function test_filtro_de_janela_com_fonte_tendencia_sem_avanco_cai_para_baseline(): void
    {
        // Sem nenhuma importação de Avanço pra obra: escolher "Tendência" como
        // fonte não pode esconder tudo — o filtro de janela cai sozinho pra
        // Linha de Base (decisão explícita do usuário), mesmo a coluna
        // exibida continuando N/A.
        $dentroDaJanelaPelaBaseline = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Cai Pela Baseline XYZ123',
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
            'inicio_planejado' => now()->addDays(5),
            'data_termino' => now()->addDays(10),
        ]);

        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 30)
            ->assertSee($dentroDaJanelaPelaBaseline->nome);
    }

    public function test_importacao_de_avanco_mais_recente_e_usada_por_padrao_na_tendencia(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(50), // ao vivo, fora da janela
            'data_termino' => now()->addDays(55),
        ]);

        $antiga = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->subDays(10),
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $antiga->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => now()->addDays(60), // fora da janela também
            'data_termino' => now()->addDays(65),
        ]);

        $recente = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->subDay(),
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $recente->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => now()->addDays(5), // dentro da janela de 30
            'data_termino' => now()->addDays(10),
        ]);

        // Sem seleção explícita: usa a importação de Avanço mais recente
        // (a antiga colocaria fora da janela; a recente coloca dentro).
        $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 30)
            ->assertSee($atividade->nome);
    }

    // =========================================================================
    // POPUP DE DETALHE: CURVA S DA ATIVIDADE (PREVISTO x REALIZADO)
    // =========================================================================

    private function criarLinhaBaseComPrevisto(Atividade $atividade, array $horasPorSemana, ?string $nome = null, ?\Illuminate\Support\Carbon $criadaEm = null, ?\Illuminate\Support\Carbon $inicioPrimeiraSemana = null): LinhaBase
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(30),
        ]);

        $linhaBase = LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => $nome ?? 'BL01',
            'cronograma_importacao_id' => $importacao->id,
        ]);

        // latest() (usado por linhasBase()) ordena por created_at — em MySQL
        // isso tem precisão de segundo, então 2 LinhaBase criadas na mesma
        // chamada de teste podem empatar; forçar timestamps explícitos
        // garante que "a mais recente" seja determinística no teste.
        if ($criadaEm) {
            $linhaBase->forceFill(['created_at' => $criadaEm])->save();
        }

        $semana = ($inicioPrimeiraSemana ?? now()->subWeeks(count($horasPorSemana)))->copy()->startOfWeek();
        foreach ($horasPorSemana as $horas) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $semana->copy(),
                'horas' => $horas,
            ]);
            $semana->addWeek();
        }

        return $linhaBase;
    }

    private function criarReportEmitidoComRealizado(Atividade $atividade, array $horasPorSemana): Report
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->subDays(5),
        ]);

        $semana = now()->subWeeks(count($horasPorSemana))->startOfWeek();
        foreach ($horasPorSemana as $horas) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Realizado->value,
                'periodo_inicio' => $semana->copy(),
                'horas' => $horas,
            ]);
            $semana->addWeek();
        }

        return Report::factory()->emitido()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'periodo_referencia' => now(),
            'criado_por' => $this->user->id,
        ]);
    }

    public function test_curva_atividade_com_baseline_e_report_emitido_calcula_previsto_e_realizado(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // total 100HH, ambas semanas já passadas
        $this->criarReportEmitidoComRealizado($atividade, [30]); // 30HH realizado

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_baseline']);
        $this->assertTrue($curva['tem_report']);
        $this->assertNotEmpty($curva['previsto']);
        $this->assertNotEmpty($curva['realizado']);
        $this->assertEqualsWithDelta(100.0, $curva['percentual_previsto'], 0.5);
        $this->assertEqualsWithDelta(30.0, $curva['percentual_realizado'], 0.5);
        $this->assertEquals('desfavoravel', $curva['indicador']); // 30% realizado < 100% previsto
    }

    public function test_escala_mensal_formata_rotulos_como_mes_barra_ano(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(30),
        ]);
        LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'BL01 - Mensal',
            'cronograma_importacao_id' => $importacao->id,
        ]);

        // Períodos com granularidade MENSAL (não semanal) — datas fixas
        // pra o rótulo esperado ser determinístico ("JAN/26", "FEV/26").
        foreach ([
            ['inicio' => '2026-01-01', 'horas' => 50],
            ['inicio' => '2026-02-01', 'horas' => 50],
        ] as $periodo) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Mensal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $periodo['inicio'],
                'horas' => $periodo['horas'],
            ]);
        }

        $componente = $this->componente()->call('verAtividade', $atividade->id);
        $curva = $componente->set('modalGranularidade', 'mensal')->instance()->modalCurvaAtividade;

        $this->assertNotEmpty($curva['previsto']);
        $this->assertEqualsCanonicalizing(['JAN/26', 'FEV/26'], array_values($curva['labels']));
    }

    public function test_trocar_baseline_atualiza_previsto_mas_nao_o_realizado(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        // Baseline "antiga": semanas já totalmente passadas → 100% previsto até hoje.
        $linhaBaseAntiga = $this->criarLinhaBaseComPrevisto(
            $atividade, [40, 60], 'BL01 - Antiga', now()->subMinutes(10)
        );
        $this->criarReportEmitidoComRealizado($atividade, [30]);

        // Baseline "nova": única semana prevista só daqui a 2 semanas → 0%
        // previsto até hoje (nenhum período <= hoje ainda).
        $linhaBaseNova = $this->criarLinhaBaseComPrevisto(
            $atividade, [50], 'BL02 - Nova', now(), now()->addWeeks(2)
        );

        $componente = $this->componente()->call('verAtividade', $atividade->id);

        $curvaComBaselineNova = $componente->instance()->modalCurvaAtividade;
        $this->assertEquals($linhaBaseNova->id, $curvaComBaselineNova['baseline_id']); // mais recente por padrão
        $this->assertEqualsWithDelta(0.0, $curvaComBaselineNova['percentual_previsto'], 0.5);
        // HH bruto do Realizado (o dado que efetivamente vem do Report) —
        // é essa quantidade, não a %, que deve permanecer intocada ao
        // trocar de baseline (a % naturalmente muda de escala porque é
        // sempre rebaseada contra o total de Previsto da baseline
        // selecionada — mesma lógica já usada por CurvaAvanco::rebasearPercentual()
        // no Report semanal, pra Previsto e Realizado ficarem comparáveis
        // na MESMA escala).
        $horasRealizadoAntes = array_sum(array_column($curvaComBaselineNova['realizado'], 'horas'));

        $componente->set('modalBaselineId', $linhaBaseAntiga->id);
        $curvaComBaselineAntiga = $componente->instance()->modalCurvaAtividade;

        $this->assertEquals($linhaBaseAntiga->id, $curvaComBaselineAntiga['baseline_id']);
        $this->assertEqualsWithDelta(100.0, $curvaComBaselineAntiga['percentual_previsto'], 0.5);
        $this->assertNotEquals(
            $curvaComBaselineNova['percentual_previsto'],
            $curvaComBaselineAntiga['percentual_previsto'],
            'Previsto deve mudar ao trocar de baseline (totais de HH diferentes)'
        );

        $horasRealizadoDepois = array_sum(array_column($curvaComBaselineAntiga['realizado'], 'horas'));
        $this->assertEquals(
            $horasRealizadoAntes,
            $horasRealizadoDepois,
            'HH realizado (dado bruto vindo do último Report emitido) não deve mudar ao trocar de baseline'
        );
        $this->assertTrue($curvaComBaselineAntiga['tem_report']);
    }

    public function test_atividade_sem_report_emitido_mostra_so_previsto_com_indicador_neutro(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        // Nenhum Report emitido criado.

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_baseline']);
        $this->assertFalse($curva['tem_report']);
        $this->assertNotEmpty($curva['previsto']);
        $this->assertEmpty($curva['realizado']);
        $this->assertNull($curva['percentual_realizado']);
        $this->assertEquals('neutro', $curva['indicador']);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('Realizado ainda não disponível');
    }

    public function test_atividade_sem_baseline_mostra_mensagem_amigavel_mas_realizado_aparece_se_houver_report(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        // Nenhuma LinhaBase criada — só Report emitido.
        $this->criarReportEmitidoComRealizado($atividade, [30]);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertFalse($curva['tem_baseline']);
        $this->assertEmpty($curva['previsto']);
        $this->assertNull($curva['percentual_previsto']);
        $this->assertTrue($curva['tem_report'], 'Realizado deve continuar disponível mesmo sem Baseline, se houver Report emitido');
        $this->assertNotEmpty($curva['realizado']);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('Nenhuma Baseline disponível para esta atividade');
    }

    public function test_datas_e_percentual_previsto_vem_da_mesma_baseline_selecionada_nao_do_campo_ao_vivo(): void
    {
        // Bug relatado: campo AO VIVO da atividade (baseline_inicio) fica
        // desalinhado da Baseline selecionada no popup — antes da correção,
        // a data exibida vinha do campo ao vivo enquanto o %Previsto vinha
        // da baseline selecionada, então dava pra ver "% Previsto: 23%"
        // junto de uma data de início no FUTURO (inconsistente, sem sentido).
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'baseline_inicio' => now()->subDays(30), // campo ao vivo — propositalmente diferente
            'baseline_termino' => now()->subDays(20),
        ]);

        // Baseline selecionada só começa daqui a 1 semana — ainda não
        // começou, então %Previsto até hoje tem que ser nulo.
        $inicioFuturo = now()->addWeek()->startOfWeek();
        $linhaBase = $this->criarLinhaBaseComPrevisto($atividade, [50, 50], null, null, $inicioFuturo);

        // Snapshot da PRÓPRIA baseline selecionada mostra a data real
        // (futura) — diferente do campo ao vivo (passado).
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $linhaBase->cronograma_importacao_id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => $inicioFuturo,
            'data_termino' => $inicioFuturo->copy()->addWeeks(2),
            'baseline_inicio' => $inicioFuturo,
            'baseline_termino' => $inicioFuturo->copy()->addWeeks(2),
        ]);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertNull(
            $curva['percentual_previsto'],
            '% Previsto não pode ser positivo se a baseline selecionada ainda não começou'
        );
        $this->assertTrue(
            $curva['baseline_inicio']->isSameDay($inicioFuturo),
            'Data exibida deve vir da baseline SELECIONADA (snapshot), não do campo ao vivo da atividade'
        );
        $this->assertTrue(
            $curva['baseline_inicio']->isFuture(),
            'Data da baseline selecionada é futura de verdade — não pode ser confundida com o campo ao vivo (passado)'
        );
    }
}
