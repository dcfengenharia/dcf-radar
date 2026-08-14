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
        $this->criarReportEmitidoComRealizado($atividade, [30]); // 30HH realizado — cria também a importação Avanço subjacente

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_baseline']);
        $this->assertTrue($curva['tem_tendencia_selecionada']);
        $this->assertTrue($curva['tem_realizado']);
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
        // HH bruto do Realizado (o dado que vem da importação de avanço
        // selecionada, Ciclo 17 A.4 — não mais do último Report emitido) —
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
            'HH realizado (dado bruto vindo da importação de avanço selecionada) não deve mudar ao trocar de baseline'
        );
        $this->assertTrue($curvaComBaselineAntiga['tem_realizado']);
    }

    public function test_atividade_sem_importacao_de_avanco_mostra_so_previsto_com_indicador_neutro(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        // Nenhuma importação de avanço/tendência criada.

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_baseline']);
        $this->assertFalse($curva['tem_tendencia_selecionada']);
        $this->assertFalse($curva['tem_realizado']);
        $this->assertNotEmpty($curva['previsto']);
        $this->assertEmpty($curva['realizado']);
        $this->assertNull($curva['percentual_realizado']);
        $this->assertEquals('neutro', $curva['indicador']);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('Nenhuma importação de avanço/tendência disponível para esta obra');
    }

    public function test_atividade_sem_baseline_mostra_mensagem_amigavel_mas_realizado_aparece_se_houver_avanco(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        // Nenhuma LinhaBase criada — só uma importação de avanço (via helper
        // que também cria/emite um Report, mas o Report deixou de governar
        // esta curva no Ciclo 17 A.4 — o que importa aqui é a importação).
        $this->criarReportEmitidoComRealizado($atividade, [30]);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertFalse($curva['tem_baseline']);
        $this->assertEmpty($curva['previsto']);
        $this->assertNull($curva['percentual_previsto']);
        $this->assertTrue($curva['tem_tendencia_selecionada'], 'Realizado deve continuar disponível mesmo sem Baseline, se houver importação de avanço');
        $this->assertTrue($curva['tem_realizado']);
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

    // =========================================================================
    // CICLO 17, ETAPA A.2: TENDÊNCIA DO POPUP VIA SNAPSHOT (NUNCA CAMPO AO VIVO)
    // =========================================================================

    /**
     * Cria uma CronogramaImportacao Avanço (ou tipo informado) + o
     * AtividadeSnapshot correspondente — mesma dupla que MsProjectImporter
     * grava de verdade em toda importação real, aqui construída direto
     * pra controlar as datas de tendência de forma determinística.
     */
    private function criarImportacaoTendenciaComSnapshot(
        Atividade $atividade,
        \Illuminate\Support\Carbon $inicioTendencia,
        \Illuminate\Support\Carbon $terminoTendencia,
        ?\Illuminate\Support\Carbon $importadoEm = null,
        ?string $tipo = null,
    ): CronogramaImportacao {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => $tipo ?? TipoCronogramaImportacao::Avanco->value,
            'importado_em' => $importadoEm ?? now(),
        ]);

        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => $inicioTendencia,
            'data_termino' => $terminoTendencia,
        ]);

        return $importacao;
    }

    /**
     * Cenário A (CRÍTICO — prova exatamente o bug encontrado na A.1):
     * só existe Baseline, nenhuma importação Avanço/Ambos. O popup nunca
     * pode reaproveitar os campos ao vivo inicio_planejado/data_termino
     * como "Tendência" — precisa mostrar N/A, igual à tabela principal.
     */
    public function test_A_somente_baseline_tendencia_mostra_na_e_nunca_reutiliza_campo_ao_vivo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            // Campos AO VIVO propositalmente preenchidos com datas
            // distintas — é exatamente isso que o código antigo (bug da
            // A.1) mostrava como "Tendência" mesmo sem nenhum Avanço.
            'inicio_planejado' => \Illuminate\Support\Carbon::parse('2026-03-10'),
            'data_termino' => \Illuminate\Support\Carbon::parse('2026-03-20'),
        ]);

        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // só Baseline

        $componente = $this->componente()->call('verAtividade', $atividade->id);

        $this->assertNull($componente->instance()->modalTendenciaSnapshot);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('N/A')
            ->assertDontSee('10/03/2026')
            ->assertDontSee('20/03/2026');
    }

    /** Cenário B: Baseline com datas A, Avanço com datas B — popup mostra B. */
    public function test_B_baseline_mais_avanco_tendencia_mostra_datas_do_avanco(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // Baseline — datas A, irrelevantes aqui

        $inicioTend = \Illuminate\Support\Carbon::parse('2026-05-04');
        $terminoTend = \Illuminate\Support\Carbon::parse('2026-05-15');
        $this->criarImportacaoTendenciaComSnapshot($atividade, $inicioTend, $terminoTend);

        $snapshot = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalTendenciaSnapshot;
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->inicio_planejado->isSameDay($inicioTend));
        $this->assertTrue($snapshot->data_termino->isSameDay($terminoTend));

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('04/05/2026')
            ->assertSee('15/05/2026');
    }

    /** Cenário C: 2 importações de Avanço, sem seleção explícita — usa a mais recente (mesma regra de importacaoTendenciaAtual()). */
    public function test_C_multiplas_importacoes_avanco_sem_selecao_usa_a_mais_recente(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $avancoAntigo = $this->criarImportacaoTendenciaComSnapshot(
            $atividade,
            \Illuminate\Support\Carbon::parse('2025-11-05'),
            \Illuminate\Support\Carbon::parse('2025-11-15'),
            now()->subDays(10),
        );
        $avancoRecente = $this->criarImportacaoTendenciaComSnapshot(
            $atividade,
            \Illuminate\Support\Carbon::parse('2026-07-20'),
            \Illuminate\Support\Carbon::parse('2026-07-30'),
            now()->subDay(),
        );

        $componente = $this->componente()->call('verAtividade', $atividade->id);
        $snapshot = $componente->instance()->modalTendenciaSnapshot;

        $this->assertEquals($avancoRecente->id, $snapshot->cronograma_importacao_id);

        $componente
            ->assertSee('20/07/2026')
            ->assertDontSee('05/11/2025');
    }

    /** Cenário D: seleção explícita de tendenciaImportacaoId na página — popup usa ESSA, não a mais recente. */
    public function test_D_selecao_explicita_de_tendencia_na_pagina_e_usada_no_popup(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $avancoAntigo = $this->criarImportacaoTendenciaComSnapshot(
            $atividade,
            \Illuminate\Support\Carbon::parse('2025-11-05'),
            \Illuminate\Support\Carbon::parse('2025-11-15'),
            now()->subDays(10),
        );
        $this->criarImportacaoTendenciaComSnapshot(
            $atividade,
            \Illuminate\Support\Carbon::parse('2026-07-20'),
            \Illuminate\Support\Carbon::parse('2026-07-30'),
            now()->subDay(),
        );

        $componente = $this->componente()
            ->set('tendenciaImportacaoId', $avancoAntigo->id)
            ->call('verAtividade', $atividade->id);

        $snapshot = $componente->instance()->modalTendenciaSnapshot;
        $this->assertEquals($avancoAntigo->id, $snapshot->cronograma_importacao_id);

        $componente
            ->assertSee('05/11/2025')
            ->assertDontSee('20/07/2026');
    }

    /**
     * Cenário E (fecha a lacuna da A.1): Baseline 03 + Avanço 07,
     * DIFERENTES entre si, provados no MESMO popup — planejado/baseline
     * vem da Baseline 03 selecionada, tendência vem do Avanço 07.
     */
    public function test_E_baseline_e_tendencia_independentes_no_mesmo_popup(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $linhaBase03 = $this->criarLinhaBaseComPrevisto($atividade, [50, 50], 'Baseline 03');
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $linhaBase03->cronograma_importacao_id,
            'atividade_id' => $atividade->id,
            'baseline_inicio' => \Illuminate\Support\Carbon::parse('2026-02-01'),
            'baseline_termino' => \Illuminate\Support\Carbon::parse('2026-02-28'),
        ]);

        $avanco07 = $this->criarImportacaoTendenciaComSnapshot(
            $atividade,
            \Illuminate\Support\Carbon::parse('2026-08-01'),
            \Illuminate\Support\Carbon::parse('2026-08-15'),
        );

        $componente = $this->componente()
            ->set('modalBaselineId', $linhaBase03->id) // já seria o default (única LinhaBase), explícito por clareza
            ->call('verAtividade', $atividade->id);

        $curva = $componente->instance()->modalCurvaAtividade;
        $tendencia = $componente->instance()->modalTendenciaSnapshot;

        $this->assertTrue($curva['baseline_inicio']->isSameDay(\Illuminate\Support\Carbon::parse('2026-02-01')), 'Planejado/Baseline precisa vir da Baseline 03');
        $this->assertTrue($tendencia->inicio_planejado->isSameDay(\Illuminate\Support\Carbon::parse('2026-08-01')), 'Tendência precisa vir do Avanço 07, independente da Baseline 03');
        $this->assertEquals($avanco07->id, $tendencia->cronograma_importacao_id);

        $componente
            ->assertSee('01/02/2026')
            ->assertSee('01/08/2026');
    }

    /**
     * Cenário F + preservação de histórico (item 10 do pedido, coberto no
     * mesmo teste): trocar a tendência selecionada atualiza as datas do
     * popup sem alterar identidade da atividade nem os registros
     * operacionais (comentário, restrição) já vinculados a ela.
     */
    public function test_F_trocar_tendencia_atualiza_datas_sem_alterar_identidade_nem_historico(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $comentario = $atividade->comentarios()->create([
            'tenant_id' => $this->obra->tenant_id,
            'autor_id' => $this->user->id,
            'comentario' => 'Comentário de teste — precisa sobreviver à troca de tendência.',
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => false,
        ]);

        $avanco1 = $this->criarImportacaoTendenciaComSnapshot(
            $atividade,
            \Illuminate\Support\Carbon::parse('2026-03-01'),
            \Illuminate\Support\Carbon::parse('2026-03-10'),
            now()->subDays(5),
        );
        $avanco2 = $this->criarImportacaoTendenciaComSnapshot(
            $atividade,
            \Illuminate\Support\Carbon::parse('2026-09-01'),
            \Illuminate\Support\Carbon::parse('2026-09-10'),
            now(),
        );

        // Ciclo 17, A.4 — a partir daqui a troca de tendência DENTRO do
        // popup é local (modalTendenciaImportacaoId), não mais a seleção
        // da página ($tendenciaImportacaoId) refletindo ao vivo num popup
        // já aberto (ver também os testes H/I/J da A.4, mais abaixo, sobre
        // herança no momento de abrir).
        $componente = $this->componente()
            ->set('tendenciaImportacaoId', $avanco1->id)
            ->call('verAtividade', $atividade->id);
        $this->assertEquals($avanco1->id, $componente->instance()->modalTendenciaSnapshot->cronograma_importacao_id);

        $componente->set('modalTendenciaImportacaoId', $avanco2->id);
        $this->assertEquals($avanco2->id, $componente->instance()->modalTendenciaSnapshot->cronograma_importacao_id);

        // Identidade e histórico intactos — mesma atividade (mesma PK),
        // mesmo comentário, mesma restrição, nunca copiados/recriados.
        $atividadeId = $atividade->id;
        $atividade->refresh();
        $this->assertEquals($atividadeId, $atividade->id);
        $this->assertEquals(1, $atividade->comentarios()->count());
        $this->assertTrue($atividade->comentarios->first()->is($comentario));
        $this->assertEquals(1, $atividade->restricoes()->count());
        $this->assertTrue($atividade->restricoes->first()->is($restricao));
    }

    // =========================================================================
    // CICLO 17, A.3 — CICLO DE VIDA DO CHART.JS (dispatch de redesenho)
    //
    // O <canvas> da Curva S vive num wire:ignore — o PHPUnit não consegue
    // provar que o canvas continua desenhado na tela (isso é validação de
    // browser, documentada no relatório da etapa). O que É provável e
    // testado aqui: QUANDO o servidor dispara o evento que comanda o
    // redesenho ('curva-atividade-atualizada'), com QUE payload, e —
    // igualmente importante — QUANDO ele deliberadamente NÃO dispara
    // (ações Tipo A: checklist/comentário, que não podem mexer no gráfico).
    // =========================================================================

    public function test_abrir_atividade_dispara_evento_de_atualizacao_do_grafico_com_payload_minimo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);

        $componente = $this->componente()->call('verAtividade', $atividade->id);

        $componente->assertDispatched('curva-atividade-atualizada', function (string $name, array $params) use ($componente) {
            $dados = $params['dados'];
            // Mesmo array já provado por modalCurvaAtividade nos testes da
            // A.2 — o dispatch nunca duplica cálculo, só encaminha o
            // computed já resolvido.
            $this->assertEquals($componente->instance()->modalCurvaAtividade, $dados);

            // Payload mínimo: só o que o Chart.js precisa (escalares/arrays),
            // nunca Model Eloquent, DTO, árvore de atividade ou restrições/
            // comentários — confirma a exigência da A.3 de não inflar o
            // evento com dado que o JS não usa.
            $this->assertEqualsCanonicalizing(
                ['baseline_id', 'baseline_inicio', 'baseline_termino', 'tem_baseline', 'tendencia_id', 'tem_tendencia_selecionada', 'tem_realizado', 'tem_tendencia', 'previsto', 'realizado', 'tendencia', 'labels', 'percentual_previsto', 'percentual_realizado', 'indicador'],
                array_keys($dados)
            );
            $this->assertArrayNotHasKey('atividade', $dados);
            $this->assertArrayNotHasKey('restricoes', $dados);
            $this->assertArrayNotHasKey('comentarios', $dados);

            return true;
        });
    }

    public function test_trocar_baseline_no_popup_recalcula_e_dispara_atualizacao_do_grafico(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $lb1 = $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'BL01', now()->subDays(10));
        $lb2 = $this->criarLinhaBaseComPrevisto($atividade, [10, 10], 'BL02', now());

        $componente = $this->componente()->call('verAtividade', $atividade->id);
        $this->assertEquals($lb2->id, $componente->instance()->modalCurvaAtividade['baseline_id']); // mais recente por padrão

        $componente->set('modalBaselineId', $lb1->id)
            ->assertDispatched('curva-atividade-atualizada', function (string $name, array $params) use ($lb1) {
                return $params['dados']['baseline_id'] === $lb1->id
                    && $params['dados']['percentual_previsto'] > 0;
            });

        // A curva de verdade (o que o próximo redesenho vai usar) mudou —
        // não só o evento disparou, o dado por trás dele é outro de fato.
        $this->assertEquals($lb1->id, $componente->instance()->modalCurvaAtividade['baseline_id']);
    }

    public function test_trocar_granularidade_no_popup_recalcula_e_dispara_atualizacao_do_grafico(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);

        $componente = $this->componente()->call('verAtividade', $atividade->id);
        $labelsSemanal = $componente->instance()->modalCurvaAtividade['labels'];

        $componente->set('modalGranularidade', 'mensal')
            ->assertDispatched('curva-atividade-atualizada');

        $labelsMensal = $componente->instance()->modalCurvaAtividade['labels'];
        $this->assertNotEquals($labelsSemanal, $labelsMensal);
    }

    public function test_marcar_item_checklist_nao_dispara_atualizacao_do_grafico(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $item = \App\Models\ItemProntidao::create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Item de teste', 'ordem' => 1]);

        // Tipo A: marcar um item de prontidão não muda a curva da atividade
        // — o gráfico (protegido por wire:ignore) precisa ficar intocado,
        // então o servidor nunca deve mandar o comando de redesenho aqui.
        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->call('marcarItemNaDetalhe', $atividade->id, $item->id, true)
            ->assertNotDispatched('curva-atividade-atualizada');
    }

    public function test_adicionar_comentario_nao_dispara_atualizacao_do_grafico(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);

        // Tipo A: comentar não muda a curva — mesma garantia acima, agora
        // pro outro round-trip explicitamente citado na etapa A.3.
        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('comentarioNovoAtividade', 'Comentário que não deve mexer no gráfico.')
            ->call('adicionarComentarioAtividade', $atividade->id)
            ->assertNotDispatched('curva-atividade-atualizada');
    }

    public function test_trocar_de_atividade_dispara_atualizacao_do_grafico_com_dados_da_nova_atividade(): void
    {
        $atividadeA = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $this->criarLinhaBaseComPrevisto($atividadeA, [40, 60]);
        $this->criarLinhaBaseComPrevisto($atividadeB, [5, 5]);

        $componente = $this->componente()->call('verAtividade', $atividadeA->id);
        $dadosA = $componente->instance()->modalCurvaAtividade;

        // Abrir uma atividade diferente é sempre popup novo (o anterior já
        // fechou antes de qualquer novo clique ser possível) — mesmo assim
        // precisa disparar o comando de redesenho com o dado da atividade
        // CERTA, nunca reaproveitar visualmente o gráfico da anterior.
        $componente->call('verAtividade', $atividadeB->id)
            ->assertDispatched('curva-atividade-atualizada', function (string $name, array $params) use ($dadosA) {
                return $params['dados'] !== $dadosA;
            });

        $dadosB = $componente->instance()->modalCurvaAtividade;
        $this->assertNotEquals($dadosA['previsto'], $dadosB['previsto']);
    }

    // =========================================================================
    // CICLO 17, A.4 — SEMÂNTICA TEMPORAL DO GRÁFICO (Baseline x Tendência
    // independentes, seletor modal de tendência, Realizado/Tendência não
    // governados mais pelo último Report emitido)
    // =========================================================================

    /**
     * Importação de Avanço "crua" (sem Report nenhum, ao contrário de
     * criarReportEmitidoComRealizado()) — grava as séries pedidas
     * (Realizado e/ou Tendência) em avanco_periodos, mesma tabela/mesmo
     * formato que MsProjectImporter grava de verdade. Prova, por
     * construção, que o gráfico do popup nunca depende de Report existir.
     */
    private function criarImportacaoAvancoComSeries(Atividade $atividade, array $seriesComHoras, ?\Illuminate\Support\Carbon $importadoEm = null): CronogramaImportacao
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => $importadoEm ?? now(),
        ]);

        foreach ($seriesComHoras as $serie => $horasPorSemana) {
            $semana = now()->subWeeks(count($horasPorSemana))->startOfWeek();
            foreach ($horasPorSemana as $horas) {
                AvancoPeriodo::create([
                    'tenant_id' => $this->obra->tenant_id,
                    'cronograma_importacao_id' => $importacao->id,
                    'atividade_id' => $atividade->id,
                    'granularidade' => GranularidadePeriodo::Semanal->value,
                    'serie' => $serie,
                    'periodo_inicio' => $semana->copy(),
                    'horas' => $horas,
                ]);
                $semana->addWeek();
            }
        }

        return $importacao;
    }

    /** Cenário A: só Baseline — Previsto disponível, Realizado/Tendência ausentes, nunca inventados. */
    public function test_A4_A_somente_baseline_previsto_disponivel_realizado_e_tendencia_ausentes(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_baseline']);
        $this->assertNotEmpty($curva['previsto']);
        $this->assertFalse($curva['tem_tendencia_selecionada']);
        $this->assertFalse($curva['tem_realizado']);
        $this->assertFalse($curva['tem_tendencia']);
        $this->assertEmpty($curva['realizado']);
        $this->assertEmpty($curva['tendencia']);
    }

    /** Cenário B: Baseline + avanço só com Realizado — Previsto da baseline, Realizado da importação, Tendência ausente. */
    public function test_A4_B_baseline_mais_avanco_com_realizado(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // 100HH
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [30]]);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_baseline']);
        $this->assertTrue($curva['tem_realizado']);
        $this->assertFalse($curva['tem_tendencia']);
        $this->assertEmpty($curva['tendencia']);
        $this->assertEqualsWithDelta(100.0, $curva['percentual_previsto'], 0.5);
        $this->assertEqualsWithDelta(30.0, $curva['percentual_realizado'], 0.5);
    }

    /** Cenário C: Baseline + avanço só com Tendência — Previsto da baseline, Tendência da importação, Realizado ausente. */
    public function test_A4_C_baseline_mais_avanco_com_tendencia(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // 100HH
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Tendencia->value => [20]]);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_baseline']);
        $this->assertFalse($curva['tem_realizado']);
        $this->assertEmpty($curva['realizado']);
        $this->assertTrue($curva['tem_tendencia']);
        $this->assertEqualsWithDelta(20.0, collect($curva['tendencia'])->last()['percentual'], 0.5);
    }

    /** Cenário D: avanço com Realizado + Tendência — ambas exibidas corretamente, nunca uma virando a outra. */
    public function test_A4_D_avanco_com_realizado_e_tendencia_ambas_exibidas(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // 100HH
        $this->criarImportacaoAvancoComSeries($atividade, [
            SerieAvanco::Realizado->value => [30],
            SerieAvanco::Tendencia->value => [45],
        ]);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertTrue($curva['tem_realizado']);
        $this->assertTrue($curva['tem_tendencia']);
        $this->assertEqualsWithDelta(30.0, collect($curva['realizado'])->last()['percentual'], 0.5);
        $this->assertEqualsWithDelta(45.0, collect($curva['tendencia'])->last()['percentual'], 0.5);
    }

    /** Cenário E: múltiplos avanços — trocar a seleção muda datasets e datas do header. */
    public function test_A4_E_trocar_entre_multiplos_avancos_muda_datasets_e_header(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);

        $avanco1 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(10));
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $avanco1->id, 'atividade_id' => $atividade->id,
            'inicio_planejado' => \Illuminate\Support\Carbon::parse('2026-03-01'), 'data_termino' => \Illuminate\Support\Carbon::parse('2026-03-10'),
        ]);
        $avanco2 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [70]], now());
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $avanco2->id, 'atividade_id' => $atividade->id,
            'inicio_planejado' => \Illuminate\Support\Carbon::parse('2026-09-01'), 'data_termino' => \Illuminate\Support\Carbon::parse('2026-09-10'),
        ]);

        $componente = $this->componente()->call('verAtividade', $atividade->id)
            ->set('modalTendenciaImportacaoId', $avanco1->id);
        $curva1 = $componente->instance()->modalCurvaAtividade;
        $this->assertEqualsWithDelta(10.0, $curva1['percentual_realizado'], 0.5);
        $this->assertEquals($avanco1->id, $componente->instance()->modalTendenciaSnapshot->cronograma_importacao_id);

        $componente->set('modalTendenciaImportacaoId', $avanco2->id)
            ->assertDispatched('curva-atividade-atualizada');
        $curva2 = $componente->instance()->modalCurvaAtividade;
        $this->assertEqualsWithDelta(70.0, $curva2['percentual_realizado'], 0.5);
        $this->assertEquals($avanco2->id, $componente->instance()->modalTendenciaSnapshot->cronograma_importacao_id);

        $this->assertNotEquals($curva1['realizado'], $curva2['realizado']);
    }

    /** Cenário F: trocar baseline não altera a importação de avanço selecionada. */
    public function test_A4_F_trocar_baseline_nao_altera_importacao_de_avanco_selecionada(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb1 = $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'BL01', now()->subDays(10));
        $lb2 = $this->criarLinhaBaseComPrevisto($atividade, [10, 10], 'BL02', now());
        $avanco = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [30]]);

        $componente = $this->componente()->call('verAtividade', $atividade->id)
            ->set('modalTendenciaImportacaoId', $avanco->id);
        $this->assertEquals($avanco->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);

        $componente->set('modalBaselineId', $lb1->id);

        $this->assertEquals($lb1->id, $componente->instance()->modalCurvaAtividade['baseline_id']);
        $this->assertEquals($avanco->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);
    }

    /** Cenário G: trocar tendência/avanço não altera a baseline selecionada. */
    public function test_A4_G_trocar_tendencia_nao_altera_baseline_selecionada(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $avanco1 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(5));
        $avanco2 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [20]], now());

        $componente = $this->componente()->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $lb->id);
        $this->assertEquals($lb->id, $componente->instance()->modalCurvaAtividade['baseline_id']);

        $componente->set('modalTendenciaImportacaoId', $avanco1->id);

        $this->assertEquals($lb->id, $componente->instance()->modalCurvaAtividade['baseline_id']);
        $this->assertEquals($avanco1->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);
    }

    /** Cenário H: página com tendência explícita — popup herda EXATAMENTE essa seleção ao abrir. */
    public function test_A4_H_popup_herda_tendencia_explicita_da_pagina_ao_abrir(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $avancoAntigo = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(10));
        $avancoEscolhido = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [50]], now()->subDays(5));
        // Um avanço mais recente que $avancoEscolhido existe também — a
        // página escolhe explicitamente o do meio, não o mais recente.
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [90]], now());

        $componente = $this->componente()
            ->set('tendenciaImportacaoId', $avancoEscolhido->id)
            ->call('verAtividade', $atividade->id);

        $this->assertEquals($avancoEscolhido->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);
        $this->assertEqualsWithDelta(50.0, $componente->instance()->modalCurvaAtividade['percentual_realizado'], 0.5);
    }

    /** Cenário I: sem seleção explícita na página — popup usa a mesma importação efetiva (mais recente Avanço/Ambos) que a tabela usaria. */
    public function test_A4_I_sem_selecao_explicita_popup_usa_mesma_importacao_efetiva_da_pagina(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(10));
        $maisRecente = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [80]], now());

        $componente = $this->componente()->call('verAtividade', $atividade->id);

        $this->assertEquals($maisRecente->id, $componente->instance()->importacaoTendenciaAtual->id);
        $this->assertEquals($maisRecente->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);
        $this->assertEqualsWithDelta(80.0, $componente->instance()->modalCurvaAtividade['percentual_realizado'], 0.5);
    }

    /** Cenário J: reabertura — trocar tendência no popup A e reabrir noutra atividade (B) volta a herdar a página, não a escolha feita em A. */
    public function test_A4_J_reabrir_em_outra_atividade_volta_a_herdar_a_pagina(): void
    {
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividadeA, [40, 60]);
        $this->criarLinhaBaseComPrevisto($atividadeB, [40, 60]);

        // A tendência efetiva é uma propriedade da OBRA (mesma importação
        // pode tocar várias atividades), não da atividade aberta — só
        // precisamos de 2 importações competindo por "mais recente" pra
        // provar o comportamento, nenhuma delas precisa ser exclusiva de B.
        $avancoAntigo = $this->criarImportacaoAvancoComSeries($atividadeA, [SerieAvanco::Realizado->value => [10]], now()->subDays(10));
        $maisRecente = $this->criarImportacaoAvancoComSeries($atividadeA, [SerieAvanco::Realizado->value => [80]], now());

        $componente = $this->componente()->call('verAtividade', $atividadeA->id);
        $this->assertEquals($maisRecente->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);

        // Usuário troca localmente pra uma importação mais antiga dentro do popup de A.
        $componente->set('modalTendenciaImportacaoId', $avancoAntigo->id);
        $this->assertEquals($avancoAntigo->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);

        // Fecha A e abre B — precisa herdar a tendência EFETIVA DA PÁGINA de
        // novo (mais recente Avanço/Ambos, já que a página não tem seleção
        // explícita), nunca a escolha manual feita dentro do popup de A.
        $componente->set('modalAtividadeId', null)->call('verAtividade', $atividadeB->id);

        $this->assertEquals($maisRecente->id, $componente->instance()->modalTendenciaImportacaoId);
        $this->assertNotEquals($avancoAntigo->id, $componente->instance()->modalTendenciaImportacaoId);
    }

    /** Cenário K: trocar baseline e tendência dentro do popup nunca escreve em comentário/restrição/checklist. */
    public function test_A4_K_trocas_temporais_nao_alteram_historico_operacional(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb1 = $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'BL01', now()->subDays(10));
        $lb2 = $this->criarLinhaBaseComPrevisto($atividade, [10, 10], 'BL02', now());
        $avanco1 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(5));
        $avanco2 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [20]], now());

        $comentario = $atividade->comentarios()->create([
            'tenant_id' => $this->obra->tenant_id, 'autor_id' => $this->user->id, 'comentario' => 'Não pode sumir.',
        ]);
        $restricao = Restricao::factory()->create(['tenant_id' => $this->obra->tenant_id, 'atividade_id' => $atividade->id, 'bloqueante' => false]);
        $item = \App\Models\ItemProntidao::create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Item', 'ordem' => 1]);
        \App\Models\AtividadeItemProntidao::create(['tenant_id' => $this->obra->tenant_id, 'atividade_id' => $atividade->id, 'item_prontidao_id' => $item->id, 'concluido' => true]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $lb1->id)
            ->set('modalTendenciaImportacaoId', $avanco1->id)
            ->set('modalBaselineId', $lb2->id)
            ->set('modalTendenciaImportacaoId', $avanco2->id);

        $atividade->refresh();
        $this->assertEquals(1, $atividade->comentarios()->count());
        $this->assertTrue($atividade->comentarios->first()->is($comentario));
        $this->assertEquals(1, $atividade->restricoes()->count());
        $this->assertTrue($atividade->restricoes->first()->is($restricao));
        $this->assertTrue(\App\Models\AtividadeItemProntidao::where('atividade_id', $atividade->id)->where('item_prontidao_id', $item->id)->first()->concluido);
    }

    /** Payload do evento muda ao trocar tendência, com as séries certas presentes/ausentes. */
    public function test_A4_payload_do_evento_muda_ao_trocar_tendencia_com_series_corretas(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $avancoSoRealizado = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [30]], now()->subDays(5));
        $avancoSoTendencia = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Tendencia->value => [40]], now());

        $componente = $this->componente()->call('verAtividade', $atividade->id)
            ->set('modalTendenciaImportacaoId', $avancoSoRealizado->id);

        $componente->set('modalTendenciaImportacaoId', $avancoSoTendencia->id)
            ->assertDispatched('curva-atividade-atualizada', function (string $name, array $params) {
                $dados = $params['dados'];
                return $dados['tem_realizado'] === false
                    && $dados['tem_tendencia'] === true
                    && empty($dados['realizado'])
                    && !empty($dados['tendencia']);
            });
    }

    /** Payload nunca usa o cronograma_importacao_id do último Report emitido — nem por engano (Ciclo 17, A.4 remove essa dependência). */
    public function test_A4_payload_nao_usa_cronograma_do_ultimo_report_emitido(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);

        // Report emitido referenciando uma importação com 99HH de Realizado
        // — se o bug antigo (ler do último Report) reaparecesse, o teste
        // pegaria 99%, não os 15% da importação de avanço de verdade mais
        // recente (sem Report nenhum).
        $this->criarReportEmitidoComRealizado($atividade, [99]);
        $avancoSemReport = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [15]], now());

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertEquals($avancoSemReport->id, $curva['tendencia_id']);
        $this->assertEqualsWithDelta(15.0, $curva['percentual_realizado'], 0.5);
    }

    /** Baseline e avanço podem vir de importações completamente diferentes, provado simultaneamente no mesmo render. */
    public function test_A4_baseline_e_avanco_de_importacoes_diferentes_simultaneamente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb3 = $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'Baseline 03');
        $avanco7 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [33]]);

        $curva = $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $lb3->id)
            ->set('modalTendenciaImportacaoId', $avanco7->id)
            ->instance()
            ->modalCurvaAtividade;

        $this->assertEquals($lb3->id, $curva['baseline_id']);
        $this->assertEquals($avanco7->id, $curva['tendencia_id']);
        $this->assertEqualsWithDelta(33.0, $curva['percentual_realizado'], 0.5);
    }

    // =========================================================================
    // CICLO 17, A.4.CORREÇÃO — HERANÇA DA BASELINE DA PÁGINA AO ABRIR O POPUP
    // (achado C da auditoria: modalBaselineId era resetado incondicionalmente
    // pra null, nunca lendo $linhaBaseId — assimétrico com a tendência, que já
    // herdava corretamente desde a A.4)
    // =========================================================================

    /**
     * CRÍTICO — reproduz exatamente o cenário da auditoria: Baseline 02
     * (selecionada explicitamente na página) + Baseline 05 (mais recente/
     * default, NÃO selecionada) + Avanço 07. Valores de B02/B05/T07
     * deliberadamente distintos entre si pra nenhuma assertiva poder
     * passar por coincidência.
     */
    public function test_A4CORRECAO_popup_herda_baseline_e_tendencia_explicitas_da_pagina_imediatamente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        // Baseline 02: criada primeiro (mais antiga), 100HH.
        $baseline02 = $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'Baseline 02', now()->subDays(20));
        // Baseline 05: criada depois (mais recente/default), 20HH — valor
        // bem diferente de B02, pra detectar se o popup pegou a errada.
        $baseline05 = $this->criarLinhaBaseComPrevisto($atividade, [10, 10], 'Baseline 05', now());
        $this->assertEquals($baseline05->id, $this->componente()->instance()->linhasBase->first()->id, 'pré-condição: B05 precisa ser a default/mais recente');

        $avanco07 = $this->criarImportacaoAvancoComSeries($atividade, [
            SerieAvanco::Realizado->value => [25],
            SerieAvanco::Tendencia->value => [35],
        ]);

        $componente = $this->componente()
            ->set('linhaBaseId', $baseline02->id)
            ->set('tendenciaImportacaoId', $avanco07->id)
            ->call('verAtividade', $atividade->id);

        // Confirma IMEDIATAMENTE, antes de qualquer set() modal.
        $componente->assertSet('modalBaselineId', $baseline02->id)
            ->assertSet('modalTendenciaImportacaoId', $avanco07->id);

        $curva = $componente->instance()->modalCurvaAtividade;
        $this->assertEquals($baseline02->id, $curva['baseline_id']);
        $this->assertNotEquals($baseline05->id, $curva['baseline_id']);
        $this->assertEquals($avanco07->id, $curva['tendencia_id']);
        // Previsto de B02 (100HH, ambas semanas passadas) => 100%, nunca os 20HH de B05.
        $this->assertEqualsWithDelta(100.0, $curva['percentual_previsto'], 0.5);
        $this->assertEqualsWithDelta(25.0, $curva['percentual_realizado'], 0.5);
        $this->assertEqualsWithDelta(35.0, collect($curva['tendencia'])->last()['percentual'], 0.5);
    }

    /**
     * Sem seleção explícita na página ($linhaBaseId null): modalBaselineId
     * pode continuar null (mesma representação de sempre) — o que importa é
     * que modalCurvaAtividade() resolve pra baseline default/mais recente,
     * exatamente como já acontecia antes desta correção.
     */
    public function test_A4CORRECAO_sem_selecao_explicita_de_baseline_popup_usa_default_mais_recente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'BL Antiga', now()->subDays(20));
        $maisRecente = $this->criarLinhaBaseComPrevisto($atividade, [10, 10], 'BL Recente', now());

        $componente = $this->componente()->call('verAtividade', $atividade->id);

        $this->assertNull($componente->get('linhaBaseId'));
        $this->assertNull($componente->instance()->modalBaselineId);
        $this->assertEquals($maisRecente->id, $componente->instance()->modalCurvaAtividade['baseline_id']);
    }

    /**
     * Independência após a abertura: página fica em B02+T07; alterar SÓ a
     * baseline modal (pra B05) muda o Previsto mas preserva Realizado/
     * Tendência (ainda T07) e não escreve em $linhaBaseId da página.
     */
    public function test_A4CORRECAO_alterar_so_baseline_modal_preserva_tendencia_e_pagina(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $baseline02 = $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'B02', now()->subDays(20)); // 100HH, já passado => 100% previsto
        // B05: MESMO total de 100HH (pra não alterar o denominador do rebase
        // do Realizado — CurvaAvanco::rebasearPercentual() usa o total de
        // Previsto da baseline ATIVA), mas com a semana só daqui a 2 semanas
        // => 0% previsto até hoje (nenhum período <= hoje ainda) — mesma
        // técnica já usada em test_trocar_baseline_atualiza_previsto_mas_nao_o_realizado,
        // garante % de Previsto genuinamente diferente de B02, sem afetar %Realizado.
        $baseline05 = $this->criarLinhaBaseComPrevisto($atividade, [100], 'B05', now(), now()->addWeeks(2));
        $avanco07 = $this->criarImportacaoAvancoComSeries($atividade, [
            SerieAvanco::Realizado->value => [25],
            SerieAvanco::Tendencia->value => [35],
        ]);

        $componente = $this->componente()
            ->set('linhaBaseId', $baseline02->id)
            ->set('tendenciaImportacaoId', $avanco07->id)
            ->call('verAtividade', $atividade->id);

        $curvaAntes = $componente->instance()->modalCurvaAtividade;
        $this->assertEquals($baseline02->id, $curvaAntes['baseline_id']);
        $this->assertEqualsWithDelta(100.0, $curvaAntes['percentual_previsto'], 0.5);

        $componente->set('modalBaselineId', $baseline05->id);
        $curvaDepois = $componente->instance()->modalCurvaAtividade;

        $this->assertEquals($baseline05->id, $curvaDepois['baseline_id']);
        $this->assertEqualsWithDelta(0.0, $curvaDepois['percentual_previsto'], 0.5);
        $this->assertNotEquals($curvaAntes['percentual_previsto'], $curvaDepois['percentual_previsto']);
        $this->assertEquals($avanco07->id, $curvaDepois['tendencia_id']);
        $this->assertEqualsWithDelta(25.0, $curvaDepois['percentual_realizado'], 0.5);
        $this->assertEqualsWithDelta(35.0, collect($curvaDepois['tendencia'])->last()['percentual'], 0.5);
        // A página nunca foi tocada.
        $this->assertEquals($baseline02->id, $componente->get('linhaBaseId'));
    }

    /**
     * Simétrico: alterar SÓ a tendência modal (pra T10) muda Realizado/
     * Tendência mas preserva o Previsto (ainda B05) e não escreve em
     * $tendenciaImportacaoId da página.
     */
    public function test_A4CORRECAO_alterar_so_tendencia_modal_preserva_baseline_e_pagina(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        // 100HH de Previsto — Realizado/Tendência são rebaseados sobre esse
        // total (CurvaAvanco::rebasearPercentual()), então usar 100HH aqui
        // deixa "horas do Realizado" e "% do Realizado" numericamente
        // iguais, evitando erro de conta na asserção abaixo.
        $baseline05 = $this->criarLinhaBaseComPrevisto($atividade, [50, 50], 'B05', now());
        $avanco07 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [25]], now()->subDays(5));
        $avanco10 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [60], SerieAvanco::Tendencia->value => [70]], now()->subDays(1));

        $componente = $this->componente()
            ->set('linhaBaseId', $baseline05->id)
            ->set('tendenciaImportacaoId', $avanco07->id)
            ->call('verAtividade', $atividade->id);

        $curvaAntes = $componente->instance()->modalCurvaAtividade;
        $this->assertEquals($baseline05->id, $curvaAntes['baseline_id']);
        $this->assertEquals($avanco07->id, $curvaAntes['tendencia_id']);

        $componente->set('modalTendenciaImportacaoId', $avanco10->id);
        $curvaDepois = $componente->instance()->modalCurvaAtividade;

        $this->assertEquals($baseline05->id, $curvaDepois['baseline_id']);
        $this->assertEquals($curvaAntes['percentual_previsto'], $curvaDepois['percentual_previsto']);
        $this->assertEquals($avanco10->id, $curvaDepois['tendencia_id']);
        $this->assertEqualsWithDelta(60.0, $curvaDepois['percentual_realizado'], 0.5);
        $this->assertEqualsWithDelta(70.0, collect($curvaDepois['tendencia'])->last()['percentual'], 0.5);
        // A página nunca foi tocada.
        $this->assertEquals($avanco07->id, $componente->get('tendenciaImportacaoId'));
    }

    /**
     * Reabertura: página permanece B02+T07 o tempo todo; usuário altera
     * localmente pra B05+T10 dentro do popup, fecha, abre OUTRA atividade —
     * precisa voltar a herdar B02+T07 (da página), nunca B05+T10 (a escolha
     * feita na atividade anterior).
     */
    public function test_A4CORRECAO_reabertura_em_outra_atividade_volta_a_herdar_baseline_e_tendencia_da_pagina(): void
    {
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $baseline02 = $this->criarLinhaBaseComPrevisto($atividadeA, [40, 60], 'B02', now()->subDays(20));
        $this->criarLinhaBaseComPrevisto($atividadeB, [40, 60], 'B02-B', now()->subDays(20));
        $baseline05 = $this->criarLinhaBaseComPrevisto($atividadeA, [10, 10], 'B05', now());
        $this->criarLinhaBaseComPrevisto($atividadeB, [10, 10], 'B05-B', now());

        $avanco07 = $this->criarImportacaoAvancoComSeries($atividadeA, [SerieAvanco::Realizado->value => [25]], now()->subDays(5));
        $avanco10 = $this->criarImportacaoAvancoComSeries($atividadeA, [SerieAvanco::Realizado->value => [60]], now()->subDays(1));

        $componente = $this->componente()
            ->set('linhaBaseId', $baseline02->id)
            ->set('tendenciaImportacaoId', $avanco07->id)
            ->call('verAtividade', $atividadeA->id);

        $componente->assertSet('modalBaselineId', $baseline02->id)
            ->assertSet('modalTendenciaImportacaoId', $avanco07->id);

        // Usuário troca localmente, só dentro do popup de A.
        $componente->set('modalBaselineId', $baseline05->id)
            ->set('modalTendenciaImportacaoId', $avanco10->id);
        $componente->assertSet('modalBaselineId', $baseline05->id)
            ->assertSet('modalTendenciaImportacaoId', $avanco10->id);

        // Fecha A, abre B — precisa herdar de novo a seleção DA PÁGINA
        // (nunca alterada: B02 + T07), não a escolha manual feita em A.
        $componente->set('modalAtividadeId', null)->call('verAtividade', $atividadeB->id);

        $componente->assertSet('modalBaselineId', $baseline02->id)
            ->assertSet('modalTendenciaImportacaoId', $avanco07->id);

        // A página, em nenhum momento, foi alterada pelas trocas locais.
        $this->assertEquals($baseline02->id, $componente->get('linhaBaseId'));
        $this->assertEquals($avanco07->id, $componente->get('tendenciaImportacaoId'));
    }

    /**
     * Importação Ambos: uma ÚNICA CronogramaImportacao serve, ao mesmo
     * tempo, de fonte pra uma LinhaBase (Previsto) e de tendência/avanço
     * selecionada (Realizado+Tendência) — mesmo cronograma_importacao_id
     * nos dois papéis, sem ambiguidade (resolverImportacaoId() resolve
     * cada série por caminho de parâmetro independente).
     */
    public function test_A4CORRECAO_importacao_ambos_serve_como_baseline_e_tendencia_simultaneamente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $importacaoAmbos = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Ambos->value,
            'importado_em' => now()->subDays(5),
        ]);
        $linhaBaseAmbos = LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'LB via Ambos',
            'cronograma_importacao_id' => $importacaoAmbos->id,
        ]);

        $semana = now()->subWeeks(1)->startOfWeek();
        foreach ([
            SerieAvanco::Previsto->value => 50.0,
            SerieAvanco::Realizado->value => 40.0,
            SerieAvanco::Tendencia->value => 45.0,
        ] as $serie => $horas) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacaoAmbos->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => $serie,
                'periodo_inicio' => $semana->copy(),
                'horas' => $horas,
            ]);
        }

        $curva = $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $linhaBaseAmbos->id)
            ->set('modalTendenciaImportacaoId', $importacaoAmbos->id)
            ->instance()
            ->modalCurvaAtividade;

        $this->assertEquals($linhaBaseAmbos->id, $curva['baseline_id']);
        $this->assertEquals($importacaoAmbos->id, $curva['tendencia_id']);
        $this->assertEqualsWithDelta(100.0, $curva['percentual_previsto'], 0.5); // 50HH previsto, único período, já passado
        $this->assertEqualsWithDelta(80.0, $curva['percentual_realizado'], 0.5); // 40HH rebaseado sobre 50HH de previsto = 80%
        $this->assertEqualsWithDelta(90.0, collect($curva['tendencia'])->last()['percentual'], 0.5); // 45HH rebaseado sobre 50HH = 90%
    }
}
