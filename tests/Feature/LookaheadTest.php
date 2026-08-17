<?php

namespace Tests\Feature;

use App\Actions\Atividade\AnexarArquivoAtividade;
use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemAtividade;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\StatusReport;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AtividadeAnexo;
use App\Models\AtividadeComentario;
use App\Models\AtividadeSnapshot;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\CurvaAjuste;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        Storage::fake(AtividadeAnexo::DISCO);

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);

        // Correção pós-QA (Ciclo 17) — Lookahead exige pelo menos 1 LinhaBase
        // ativa da obra pra ser operacional (ver temLinhaBaseAtiva() no
        // componente). Praticamente todos os testes desta suíte exercitam
        // comportamento OPERACIONAL (filtros/popup/curva/%peso/anexos/
        // isolamento) — sem essa LinhaBase padrão aqui, a tabela ficaria
        // vazia por design em toda a suíte. `created_at` propositalmente
        // 10 anos no passado (via forceFill, já que created_at não é
        // fillable): garante que `linhasBase->first()`/`latest()` NUNCA
        // escolha esta LinhaBase padrão em vez de uma criada pelo próprio
        // teste (evita empate de segundo — mesma classe de cuidado já
        // documentada no projeto para timestamps de teste). Testes que
        // precisam do cenário "sem LinhaBase ativa" removem/soft-deletam
        // esta explicitamente.
        $importacaoPadrao = CronogramaImportacao::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subYears(10),
        ]);
        $linhaBasePadrao = LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Linha de Base (setup padrão do teste)',
            'cronograma_importacao_id' => $importacaoPadrao->id,
            'criado_por' => $this->user->id,
        ]);
        $linhaBasePadrao->forceFill(['created_at' => now()->subYears(10)])->save();
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

    /**
     * Correção pós-QA (Ciclo 17) — expectativa antiga estava ERRADA: este
     * teste criava uma Atividade com `percentual_concluido = 40` (campo AO
     * VIVO) e SEM NENHUMA importação de Avanço/Ambos na obra, e ainda assim
     * esperava ver "40%" na tabela — exatamente o mecanismo do bug relatado
     * pelo usuário em produção (85% aparecendo sem nenhum Avanço importado).
     * `percentual_concluido` é gravado pelo MsProjectImporter em QUALQUER
     * tipo de importação, inclusive Baseline-only (ver aplicar(), branch
     * updateOrCreate compartilhada) — nunca pode alimentar a coluna
     * operacional "%" da tabela. Reescrito para provar o comportamento
     * CORRETO: sem Avanço/Ambos, a coluna nunca mostra o valor do campo ao
     * vivo — mostra "—". O cenário "com Avanço real, mostra o % correto" é
     * coberto pelos testes test_G_* (bateria desta correção, abaixo).
     */
    public function test_percentual_de_avanco_e_exibido_na_lista(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => now()->addDays(5),
            'baseline_inicio' => now()->addDays(5),
            'percentual_concluido' => 40,
        ]);

        $row = $this->componente()
            ->set('fonteData', 'tendencia')
            ->instance()
            ->atividades
            ->firstWhere('atividade.id', $atividade->id);

        $this->assertNull($row['percentualRealizado']);
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

    /**
     * Ciclo 17, A.8 — helpers dedicados (mesmo espírito de
     * criarLinhaBaseMensal()/criarAvancoPeriodoPrevistoMensal(), já usados
     * pela seção de % Peso) pra "espelhar" em Mensal o TOTAL já semeado em
     * Semanal por criarLinhaBaseComPrevisto()/criarReportEmitidoComRealizado()
     * — nunca alterando esses dois helpers compartilhados diretamente (ver
     * comentário em test_a6_k: aquele teste depende DELIBERADAMENTE de
     * criarLinhaBaseComPrevisto() só gravar Semanal). Espelha o MESMO total
     * (nunca um valor diferente) — exatamente a invariante real do
     * importador (mesmo HH gravado em paralelo nas duas granularidades) —
     * porque a partir desta correção o indicador resumido (percentual_realizado,
     * tabela e popup) é sempre Mensal (App\Services\AvancoAtividade), nunca
     * mais a granularidade escolhida no seletor do gráfico.
     */
    private function espelharPrevistoMensal(LinhaBase $lb, Atividade $atividade, array $horasPorSemana): void
    {
        AvancoPeriodo::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $lb->cronograma_importacao_id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => now()->subMonth()->startOfMonth(),
            'horas' => array_sum($horasPorSemana),
        ]);
    }

    private function espelharRealizadoMensal(Report $report, Atividade $atividade, array $horasPorSemana): void
    {
        AvancoPeriodo::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $report->cronograma_importacao_id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Realizado->value,
            'periodo_inicio' => now()->subMonth()->startOfMonth(),
            'horas' => array_sum($horasPorSemana),
        ]);
    }

    public function test_curva_atividade_com_baseline_e_report_emitido_calcula_previsto_e_realizado(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // total 100HH, ambas semanas já passadas
        $report = $this->criarReportEmitidoComRealizado($atividade, [30]); // 30HH realizado — cria também a importação Avanço subjacente
        // Ciclo 17, A.8 — percentual_realizado agora é sempre Mensal (canônico).
        $this->espelharPrevistoMensal($lb, $atividade, [40, 60]);
        $this->espelharRealizadoMensal($report, $atividade, [30]);

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

    // Correção pós-QA (Ciclo 17) — este teste testava a REGRA ANTIGA ("sem
    // LinhaBase salva, mas com uma importação de Avanço, Realizado ainda
    // aparece") — exatamente o comportamento incorreto relatado em QA e
    // corrigido nesta fase (Lookahead exige LinhaBase ativa pra qualquer
    // dado operacional aparecer, mesmo Realizado). Reescrito pra provar a
    // regra nova: sem NENHUMA LinhaBase ativa na obra (a LinhaBase padrão
    // do setUp() é removida explicitamente), mesmo havendo importação de
    // Avanço com Realizado real gravado, nada aparece.
    public function test_atividade_sem_nenhuma_linha_base_ativa_nao_mostra_realizado_mesmo_com_avanco_real(): void
    {
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        // Importação de Avanço com Realizado real gravado (via helper que
        // também emite um Report) — existe no banco, mas não deve tornar o
        // Lookahead operacional sozinha.
        $this->criarReportEmitidoComRealizado($atividade, [30]);

        $this->assertFalse($this->componente()->instance()->temLinhaBaseAtiva);
        $this->assertTrue($this->componente()->instance()->atividades->isEmpty());

        $componente = $this->componente();
        $componente->call('verAtividade', $atividade->id);
        $this->assertNull($componente->get('modalAtividadeId'), 'verAtividade() não deve abrir o popup sem LinhaBase ativa, mesmo chamado direto.');
        $this->assertEmpty($componente->instance()->modalCurvaAtividade);
        $this->assertNull($componente->instance()->modalPeso);

        $this->componente()
            ->assertSee('Esta obra ainda não possui uma linha de base ativa.')
            ->assertDontSee($atividade->nome);
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
                ['baseline_id', 'baseline_inicio', 'baseline_termino', 'tem_baseline', 'tendencia_id', 'tem_tendencia_selecionada', 'tem_realizado', 'tem_tendencia', 'previsto', 'realizado', 'tendencia', 'labels', 'percentual_previsto', 'percentual_realizado', 'curva_diverge_do_indicador', 'indicador'],
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
    /**
     * $espelharMensal (Ciclo 17, A.8, default false — nunca muda o
     * comportamento dos callers já existentes): quando true, grava também,
     * pra cada série, UM registro Mensal com o MESMO total já semeado em
     * Semanal — mesma invariante real do importador (mesmo HH em paralelo
     * nas duas granularidades) — necessário pros testes cujo cenário
     * depende de percentual_realizado, que a partir desta correção é
     * sempre Mensal (App\Services\AvancoAtividade), nunca a granularidade
     * do gráfico.
     */
    private function criarImportacaoAvancoComSeries(Atividade $atividade, array $seriesComHoras, ?\Illuminate\Support\Carbon $importadoEm = null, bool $espelharMensal = false): CronogramaImportacao
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

            if ($espelharMensal) {
                AvancoPeriodo::create([
                    'tenant_id' => $this->obra->tenant_id,
                    'cronograma_importacao_id' => $importacao->id,
                    'atividade_id' => $atividade->id,
                    'granularidade' => GranularidadePeriodo::Mensal->value,
                    'serie' => $serie,
                    'periodo_inicio' => now()->subMonth()->startOfMonth(),
                    'horas' => array_sum($horasPorSemana),
                ]);
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
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // 100HH
        $this->espelharPrevistoMensal($lb, $atividade, [40, 60]);
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [30]], espelharMensal: true);

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
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $this->espelharPrevistoMensal($lb, $atividade, [40, 60]);

        $avanco1 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(10), espelharMensal: true);
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $avanco1->id, 'atividade_id' => $atividade->id,
            'inicio_planejado' => \Illuminate\Support\Carbon::parse('2026-03-01'), 'data_termino' => \Illuminate\Support\Carbon::parse('2026-03-10'),
        ]);
        $avanco2 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [70]], now(), espelharMensal: true);
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
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $this->espelharPrevistoMensal($lb, $atividade, [40, 60]);
        $avancoAntigo = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(10), espelharMensal: true);
        $avancoEscolhido = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [50]], now()->subDays(5), espelharMensal: true);
        // Um avanço mais recente que $avancoEscolhido existe também — a
        // página escolhe explicitamente o do meio, não o mais recente.
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [90]], now(), espelharMensal: true);

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
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $this->espelharPrevistoMensal($lb, $atividade, [40, 60]);
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(10), espelharMensal: true);
        $maisRecente = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [80]], now(), espelharMensal: true);

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
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);
        $this->espelharPrevistoMensal($lb, $atividade, [40, 60]);

        // Report emitido referenciando uma importação com 99HH de Realizado
        // — se o bug antigo (ler do último Report) reaparecesse, o teste
        // pegaria 99%, não os 15% da importação de avanço de verdade mais
        // recente (sem Report nenhum).
        $reportComNoventaENove = $this->criarReportEmitidoComRealizado($atividade, [99]);
        $this->espelharRealizadoMensal($reportComNoventaENove, $atividade, [99]);
        $avancoSemReport = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [15]], now(), espelharMensal: true);

        $curva = $this->componente()->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertEquals($avancoSemReport->id, $curva['tendencia_id']);
        $this->assertEqualsWithDelta(15.0, $curva['percentual_realizado'], 0.5);
    }

    /** Baseline e avanço podem vir de importações completamente diferentes, provado simultaneamente no mesmo render. */
    public function test_A4_baseline_e_avanco_de_importacoes_diferentes_simultaneamente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb3 = $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'Baseline 03');
        $this->espelharPrevistoMensal($lb3, $atividade, [40, 60]);
        $avanco7 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [33]], espelharMensal: true);

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
        $this->espelharPrevistoMensal($baseline02, $atividade, [40, 60]);
        // Baseline 05: criada depois (mais recente/default), 20HH — valor
        // bem diferente de B02, pra detectar se o popup pegou a errada.
        $baseline05 = $this->criarLinhaBaseComPrevisto($atividade, [10, 10], 'Baseline 05', now());
        $this->espelharPrevistoMensal($baseline05, $atividade, [10, 10]);
        $this->assertEquals($baseline05->id, $this->componente()->instance()->linhasBase->first()->id, 'pré-condição: B05 precisa ser a default/mais recente');

        $avanco07 = $this->criarImportacaoAvancoComSeries($atividade, [
            SerieAvanco::Realizado->value => [25],
            SerieAvanco::Tendencia->value => [35],
        ], espelharMensal: true);

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
        $this->espelharPrevistoMensal($baseline02, $atividade, [40, 60]);
        // B05: MESMO total de 100HH (pra não alterar o denominador do rebase
        // do Realizado — CurvaAvanco::rebasearPercentual() usa o total de
        // Previsto da baseline ATIVA), mas com a semana só daqui a 2 semanas
        // => 0% previsto até hoje (nenhum período <= hoje ainda) — mesma
        // técnica já usada em test_trocar_baseline_atualiza_previsto_mas_nao_o_realizado,
        // garante % de Previsto genuinamente diferente de B02, sem afetar %Realizado.
        $baseline05 = $this->criarLinhaBaseComPrevisto($atividade, [100], 'B05', now(), now()->addWeeks(2));
        $this->espelharPrevistoMensal($baseline05, $atividade, [100]);
        $avanco07 = $this->criarImportacaoAvancoComSeries($atividade, [
            SerieAvanco::Realizado->value => [25],
            SerieAvanco::Tendencia->value => [35],
        ], espelharMensal: true);

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
        $this->espelharPrevistoMensal($baseline05, $atividade, [50, 50]);
        $avanco07 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [25]], now()->subDays(5), espelharMensal: true);
        $avanco10 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [60], SerieAvanco::Tendencia->value => [70]], now()->subDays(1), espelharMensal: true);

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
        // Ciclo 17, A.8 — percentual_realizado agora é sempre Mensal
        // (canônico); espelha Previsto/Realizado (Tendência não entra no
        // indicador resumido, só na curva) com o mesmo total já semeado em
        // Semanal acima.
        foreach ([SerieAvanco::Previsto->value => 50.0, SerieAvanco::Realizado->value => 40.0] as $serie => $horas) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacaoAmbos->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Mensal->value,
                'serie' => $serie,
                'periodo_inicio' => now()->subMonth()->startOfMonth(),
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

    // =========================================================================
    // Ciclo 17, A.6 — % Peso da atividade (HH Previsto ÷ HH Previsto do
    // projeto, ambos na mesma Linha de Base, granularidade Mensal).
    //
    // criarLinhaBaseComPrevisto() (helper acima) só grava granularidade
    // Semanal — não serve pros cenários de % Peso, que exige Mensal (evita
    // contar o mesmo HH em dobro, já que a importação real grava as duas
    // granularidades simultaneamente com o mesmo total). Os 2 helpers
    // abaixo são dedicados, só pra esta seção, sem tocar o helper existente
    // (usado por dezenas de outros testes já verdes).
    // =========================================================================

    private function criarLinhaBaseMensal(?string $nome = null): LinhaBase
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(30),
        ]);

        return LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => $nome ?? 'BL01 - Peso',
            'cronograma_importacao_id' => $importacao->id,
        ]);
    }

    private function criarAvancoPeriodoPrevistoMensal(Atividade $atividade, CronogramaImportacao $importacao, array $horasPorMes): void
    {
        $mes = now()->subMonths(count($horasPorMes))->startOfMonth();
        foreach ($horasPorMes as $horas) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Mensal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $mes->copy(),
                'horas' => $horas,
            ]);
            $mes->addMonth();
        }
    }

    public function test_a6_a_peso_da_atividade_na_tabela_e_hh_previsto_da_atividade_sobre_total_do_projeto(): void
    {
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $importacao = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeA, $importacao, [30, 20]); // 50HH
        $this->criarAvancoPeriodoPrevistoMensal($atividadeB, $importacao, [150]); // 150HH — total projeto = 200HH

        $atividades = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('janelaDias', 0)
            ->instance()
            ->atividades;

        $this->assertEqualsWithDelta(25.0, $atividades->firstWhere('atividade.id', $atividadeA->id)['peso'], 0.05);
        $this->assertEqualsWithDelta(75.0, $atividades->firstWhere('atividade.id', $atividadeB->id)['peso'], 0.05);
    }

    public function test_a6_b_sem_selecao_explicita_de_baseline_peso_usa_a_baseline_mais_recente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lbAntiga = $this->criarLinhaBaseMensal('BL Antiga');
        $lbAntiga->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lbAntiga->cronograma_importacao_id), [10]);

        $lbRecente = $this->criarLinhaBaseMensal('BL Recente');
        $lbRecente->forceFill(['created_at' => now()])->save();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lbRecente->cronograma_importacao_id), [40]);

        // Sem set('linhaBaseId', ...) — mesmo fallback já usado pelo popup
        // desde o Ciclo 17 A.4 (linhasBase->first(), a mais recente).
        $total = $this->componente()->set('janelaDias', 0)->instance()->totalHhPrevistoProjeto;

        $this->assertEqualsWithDelta(40.0, $total, 0.05);
    }

    public function test_a6_c_atividade_sem_hh_previsto_mostra_peso_nulo_no_lugar_de_zero(): void
    {
        $atividadeSemHh = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeComHh = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividadeComHh, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);
        // $atividadeSemHh nunca recebe nenhum AvancoPeriodo.

        $rows = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0)->instance()->atividades;

        $this->assertNull($rows->firstWhere('atividade.id', $atividadeSemHh->id)['peso']);
        $this->assertEqualsWithDelta(100.0, $rows->firstWhere('atividade.id', $atividadeComHh->id)['peso'], 0.05);
    }

    public function test_a6_d_atividade_com_hh_previsto_somando_zero_mostra_zero_virgula_zero_por_cento_nao_traco(): void
    {
        $atividadeZero = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeComHh = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $importacao = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeZero, $importacao, [0]); // registro EXISTE, soma 0
        $this->criarAvancoPeriodoPrevistoMensal($atividadeComHh, $importacao, [100]);

        $rows = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0)->instance()->atividades;
        $pesoZero = $rows->firstWhere('atividade.id', $atividadeZero->id)['peso'];

        $this->assertNotNull($pesoZero);
        $this->assertEqualsWithDelta(0.0, $pesoZero, 0.001);
    }

    public function test_a6_e_trocar_tendencia_no_popup_nao_altera_o_peso(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [50]);

        $avanco1 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10]], now()->subDays(5));
        $avanco2 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [20]], now());

        $componente = $this->componente()->call('verAtividade', $atividade->id)->set('modalBaselineId', $lb->id);
        $pesoAntes = $componente->instance()->modalPeso;

        $componente->set('modalTendenciaImportacaoId', $avanco1->id);
        $pesoDepois1 = $componente->instance()->modalPeso;

        $componente->set('modalTendenciaImportacaoId', $avanco2->id);
        $pesoDepois2 = $componente->instance()->modalPeso;

        $this->assertEqualsWithDelta(100.0, $pesoAntes, 0.05); // única atividade na baseline
        $this->assertSame($pesoAntes, $pesoDepois1);
        $this->assertSame($pesoDepois1, $pesoDepois2);
    }

    public function test_a6_f_peso_do_popup_bate_com_peso_da_tabela_para_mesma_atividade_e_baseline(): void
    {
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $importacao = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeA, $importacao, [30]);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeB, $importacao, [70]);

        $componente = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0);
        $pesoTabela = $componente->instance()->atividades->firstWhere('atividade.id', $atividadeA->id)['peso'];

        // verAtividade() herda modalBaselineId = linhaBaseId da página (Ciclo
        // 17 A.4.CORREÇÃO) — mesma baseline, sem seleção adicional.
        $pesoPopup = $componente->call('verAtividade', $atividadeA->id)->instance()->modalPeso;

        $this->assertEqualsWithDelta($pesoTabela, $pesoPopup, 0.001);
        $this->assertEqualsWithDelta(30.0, $pesoPopup, 0.05);
    }

    public function test_a6_g_trocar_baseline_na_tabela_recalcula_peso(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $outraAtividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb1 = $this->criarLinhaBaseMensal('BL1');
        $imp1 = CronogramaImportacao::find($lb1->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividade, $imp1, [50]);
        $this->criarAvancoPeriodoPrevistoMensal($outraAtividade, $imp1, [50]); // total 100HH -> atividade = 50%

        $lb2 = $this->criarLinhaBaseMensal('BL2');
        $imp2 = CronogramaImportacao::find($lb2->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividade, $imp2, [10]);
        $this->criarAvancoPeriodoPrevistoMensal($outraAtividade, $imp2, [90]); // total 100HH -> atividade = 10%

        $componente = $this->componente()->set('janelaDias', 0)->set('linhaBaseId', $lb1->id);
        $pesoBL1 = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id)['peso'];

        $componente->set('linhaBaseId', $lb2->id);
        $pesoBL2 = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id)['peso'];

        $this->assertEqualsWithDelta(50.0, $pesoBL1, 0.05);
        $this->assertEqualsWithDelta(10.0, $pesoBL2, 0.05);
    }

    public function test_a6_h_trocar_baseline_no_popup_recalcula_peso_sem_afetar_a_tabela(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $outraAtividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lbPagina = $this->criarLinhaBaseMensal('BL Pagina');
        $impPagina = CronogramaImportacao::find($lbPagina->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividade, $impPagina, [20]);
        $this->criarAvancoPeriodoPrevistoMensal($outraAtividade, $impPagina, [80]); // atividade = 20%

        $lbPopup = $this->criarLinhaBaseMensal('BL Popup');
        $impPopup = CronogramaImportacao::find($lbPopup->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividade, $impPopup, [60]);
        $this->criarAvancoPeriodoPrevistoMensal($outraAtividade, $impPopup, [40]); // atividade = 60%

        $componente = $this->componente()
            ->set('janelaDias', 0)
            ->set('linhaBaseId', $lbPagina->id)
            ->call('verAtividade', $atividade->id);

        $pesoTabelaAntes = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id)['peso'];
        $modalPesoAntes = $componente->instance()->modalPeso; // herdou $lbPagina ao abrir

        $componente->set('modalBaselineId', $lbPopup->id);

        $modalPesoDepois = $componente->instance()->modalPeso;
        $pesoTabelaDepois = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id)['peso'];

        $this->assertEqualsWithDelta(20.0, $pesoTabelaAntes, 0.05);
        $this->assertEqualsWithDelta(20.0, $modalPesoAntes, 0.05);
        $this->assertEqualsWithDelta(60.0, $modalPesoDepois, 0.05);
        $this->assertEqualsWithDelta(20.0, $pesoTabelaDepois, 0.05); // tabela intocada — independência confirmada
    }

    public function test_a6_i_total_do_projeto_zero_nunca_causa_divisao_por_zero(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        // Nenhum AvancoPeriodo criado pra nenhuma atividade nesta baseline —
        // total do projeto fica 0.

        $rows = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0)->instance()->atividades;

        $this->assertNull($rows->firstWhere('atividade.id', $atividade->id)['peso']);
    }

    public function test_a6_j_peso_arredonda_para_uma_casa_decimal_e_formata_com_virgula(): void
    {
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $importacao = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeA, $importacao, [1]);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeB, $importacao, [2]); // total 3HH -> 33,33.../66,66...

        $componente = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0);
        $rows = $componente->instance()->atividades;

        $this->assertEqualsWithDelta(33.3, $rows->firstWhere('atividade.id', $atividadeA->id)['peso'], 0.001);
        $this->assertEqualsWithDelta(66.7, $rows->firstWhere('atividade.id', $atividadeB->id)['peso'], 0.001);
        $componente->assertSee('33,3%')->assertSee('66,7%');
    }

    public function test_a6_k_registros_apenas_semanais_nao_alimentam_o_peso_sem_registro_mensal(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        // criarLinhaBaseComPrevisto() só grava granularidade Semanal — %
        // Peso exige Mensal (evita contar o mesmo HH em dobro, já que a
        // importação real grava as duas granularidades simultaneamente com
        // o mesmo total) — sem nenhum registro Mensal, peso fica null.
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]);

        $rows = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0)->instance()->atividades;

        $this->assertNull($rows->firstWhere('atividade.id', $atividade->id)['peso']);
    }

    public function test_a6_l_denominador_e_o_projeto_inteiro_incluindo_atividade_sem_pacote(): void
    {
        $pacote = PacoteTrabalho::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $atividadeComPacote = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacote->id,
        ]);
        $atividadeOrfa = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => null,
        ]);

        $lb = $this->criarLinhaBaseMensal();
        $importacao = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeComPacote, $importacao, [80]);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeOrfa, $importacao, [20]); // total 100HH, órfã inclusa

        $rows = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0)->instance()->atividades;

        $this->assertEqualsWithDelta(80.0, $rows->firstWhere('atividade.id', $atividadeComPacote->id)['peso'], 0.05);
        $this->assertEqualsWithDelta(20.0, $rows->firstWhere('atividade.id', $atividadeOrfa->id)['peso'], 0.05);
    }

    public function test_a6_m_coluna_peso_aparece_na_tabela_e_no_popup(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $componente = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0);
        $componente->assertSee('% Peso')->assertSee('100,0%');

        $componente->call('verAtividade', $atividade->id)->assertSee('100,0%');
    }

    // =========================================================================
    // Ciclo 17, A.7.2 — UI de anexos PDF no Lookahead
    // =========================================================================

    private function conteudoPdfValidoA72(int $tamanhoBytes = 2048): string
    {
        $cabecalho = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n";
        $rodape = "\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF";
        $recheio = str_repeat('A', max(0, $tamanhoBytes - strlen($cabecalho) - strlen($rodape)));

        return $cabecalho . $recheio . $rodape;
    }

    private function arquivoPdfFakeA72(string $nome = 'documento.pdf', int $tamanhoBytes = 2048): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $this->conteudoPdfValidoA72($tamanhoBytes));
    }

    // A — zero anexos: sem badge de contador na tabela.
    public function test_a72_a_atividade_sem_anexo_nao_mostra_badge_de_contador(): void
    {
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->componente()
            ->set('janelaDias', 0)
            ->assertDontSee('anexo(s)');
    }

    // B — múltiplos anexos: contador mostra a quantidade correta.
    public function test_a72_b_contador_de_anexos_aparece_na_lista_com_quantidade_correta(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('a.pdf'), $this->user);
        app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('b.pdf'), $this->user);
        app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('c.pdf'), $this->user);

        $this->componente()
            ->set('janelaDias', 0)
            ->assertSee('3 anexo(s)');
    }

    // C — popup lista os anexos da atividade.
    public function test_a72_c_popup_lista_anexos_da_atividade(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('planta-baixa.pdf'), $this->user);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('planta-baixa.pdf');
    }

    // D — metadata completa: nome, tamanho formatado, data, autor; fallback "Usuário removido".
    public function test_a72_d_popup_mostra_metadata_completa_do_anexo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('doc.pdf', 254 * 1024), $this->user);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('doc.pdf')
            ->assertSee('254 KB')
            ->assertSee($this->user->first_name)
            ->assertSee($anexo->created_at->format('d/m/Y'));
    }

    public function test_a72_d2_popup_mostra_usuario_removido_quando_enviado_por_e_nulo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('sem-autor.pdf'), $this->user);
        $anexo->update(['enviado_por' => null]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee('Usuário removido');
    }

    // E — upload autorizado via método real do Livewire cria o anexo.
    public function test_a72_e_upload_autorizado_via_metodo_real_cria_anexo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('novoAnexo', $this->arquivoPdfFakeA72('upload-real.pdf'))
            ->call('anexarArquivoAtividade', $atividade->id);

        $this->assertDatabaseHas('atividade_anexos', [
            'atividade_id' => $atividade->id,
            'nome_original' => 'upload-real.pdf',
        ]);
    }

    // F — upload sem permissão é bloqueado NO MÉTODO (não só pela ausência do botão).
    public function test_a72_f_upload_nao_autorizado_e_bloqueado_no_metodo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id)
            ->set('novoAnexo', $this->arquivoPdfFakeA72('tentativa.pdf'))
            ->call('anexarArquivoAtividade', $atividade->id)
            ->assertForbidden();

        $this->assertEquals(0, AtividadeAnexo::where('atividade_id', $atividade->id)->count());
    }

    // G — arquivo não-PDF é rejeitado na validação da UI.
    public function test_a72_g_upload_de_arquivo_nao_pdf_e_rejeitado_com_erro_no_input(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('novoAnexo', UploadedFile::fake()->create('malware.exe', 100))
            ->call('anexarArquivoAtividade', $atividade->id)
            ->assertHasErrors(['novoAnexo']);

        $this->assertEquals(0, AtividadeAnexo::where('atividade_id', $atividade->id)->count());
    }

    // H — acima de 10MB é rejeitado, reaproveitando AtividadeAnexo::TAMANHO_MAXIMO_KB.
    public function test_a72_h_upload_acima_do_limite_e_rejeitado(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $tamanhoAcimaDoLimite = (AtividadeAnexo::TAMANHO_MAXIMO_KB + 1) * 1024;

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('novoAnexo', $this->arquivoPdfFakeA72('grande.pdf', $tamanhoAcimaDoLimite))
            ->call('anexarArquivoAtividade', $atividade->id)
            ->assertHasErrors(['novoAnexo']);

        $this->assertEquals(0, AtividadeAnexo::where('atividade_id', $atividade->id)->count());
    }

    // I — upload atualiza lista e contador, sem fechar o popup.
    public function test_a72_i_upload_atualiza_lista_e_contador_sem_fechar_popup(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);

        $componente = $this->componente()
            ->set('janelaDias', 0)
            ->call('verAtividade', $atividade->id);

        $componente->assertDontSee('anexo(s)');

        $componente->set('novoAnexo', $this->arquivoPdfFakeA72('novo.pdf'))
            ->call('anexarArquivoAtividade', $atividade->id);

        $componente->assertSet('modalAtividadeId', $atividade->id);
        $componente->assertSee('novo.pdf')->assertSee('1 anexo(s)');
    }

    // J — upload preserva Baseline/Tendência selecionadas no popup.
    public function test_a72_j_upload_preserva_baseline_e_tendencia_selecionadas(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'BL01');
        $lb2 = $this->criarLinhaBaseComPrevisto($atividade, [20, 30], 'BL02');
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10, 20]]);
        $avanco2 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [5, 15]]);

        $componente = $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $lb2->id)
            ->set('modalTendenciaImportacaoId', $avanco2->id);

        $baselineAntes = $componente->instance()->modalBaselineId;
        $tendenciaAntes = $componente->instance()->modalTendenciaImportacaoId;

        $componente->set('novoAnexo', $this->arquivoPdfFakeA72('preserva-baseline.pdf'))
            ->call('anexarArquivoAtividade', $atividade->id);

        $this->assertEquals($baselineAntes, $componente->instance()->modalBaselineId);
        $this->assertEquals($tendenciaAntes, $componente->instance()->modalTendenciaImportacaoId);
        $this->assertEquals($lb2->id, $componente->instance()->modalBaselineId);
        $this->assertEquals($avanco2->id, $componente->instance()->modalTendenciaImportacaoId);
    }

    // K — upload nunca dispara redesenho do gráfico (ação Tipo A).
    public function test_a72_k_upload_nao_dispara_atualizacao_do_grafico(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->set('novoAnexo', $this->arquivoPdfFakeA72('sem-grafico.pdf'))
            ->call('anexarArquivoAtividade', $atividade->id)
            ->assertNotDispatched('curva-atividade-atualizada');
    }

    // L — exclusão autorizada remove registro E arquivo físico.
    public function test_a72_l_exclusao_autorizada_remove_registro_e_arquivo_fisico(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $usuarioComExcluir = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $usuarioComExcluir, Papel::GerentePlanejamento->value);

        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('remover.pdf'), $usuarioComExcluir);
        $caminho = $anexo->caminho_arquivo;

        $this->actingAs($usuarioComExcluir);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id)
            ->call('removerAnexoAtividade', $anexo->id);

        $this->assertDatabaseMissing('atividade_anexos', ['id' => $anexo->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertMissing($caminho);
    }

    // M — exclusão sem permissão é bloqueada NO MÉTODO.
    public function test_a72_m_exclusao_nao_autorizada_e_bloqueada_no_metodo(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('protegido.pdf'), $this->user);

        // $this->user (Engenheiro) tem 'editar' mas NÃO 'excluir' em restricoes.lookahead.
        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->call('removerAnexoAtividade', $anexo->id)
            ->assertForbidden();

        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexo->id]);
    }

    // N — exclusão atualiza lista e contador.
    public function test_a72_n_exclusao_atualiza_lista_e_contador(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $usuarioComExcluir = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $usuarioComExcluir, Papel::GerentePlanejamento->value);

        $anexo1 = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('fica.pdf'), $usuarioComExcluir);
        $anexo2 = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('sai.pdf'), $usuarioComExcluir);

        $this->actingAs($usuarioComExcluir);

        $componente = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->set('janelaDias', 0)
            ->call('verAtividade', $atividade->id);

        $componente->assertSee('2 anexo(s)')->assertSee('fica.pdf')->assertSee('sai.pdf');

        $componente->call('removerAnexoAtividade', $anexo2->id);

        $componente->assertSee('1 anexo(s)')->assertSee('fica.pdf')->assertDontSee('sai.pdf');
    }

    // O — exclusão preserva Baseline/Tendência selecionadas no popup.
    public function test_a72_o_exclusao_preserva_baseline_e_tendencia_selecionadas(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $this->criarLinhaBaseComPrevisto($atividade, [40, 60], 'BL01');
        $lb2 = $this->criarLinhaBaseComPrevisto($atividade, [20, 30], 'BL02');
        $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [10, 20]]);
        $avanco2 = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [5, 15]]);
        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('a-remover.pdf'), $this->user);

        // 'excluir' em restricoes.lookahead exige GerentePlanejamento+ — $this->user
        // (Engenheiro) só tem 'editar', não basta pra remover anexo aqui.
        $usuarioComExcluir = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $usuarioComExcluir, Papel::GerentePlanejamento->value);
        $this->actingAs($usuarioComExcluir);

        $componente = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $lb2->id)
            ->set('modalTendenciaImportacaoId', $avanco2->id);

        $baselineAntes = $componente->instance()->modalBaselineId;
        $tendenciaAntes = $componente->instance()->modalTendenciaImportacaoId;

        $componente->call('removerAnexoAtividade', $anexo->id);

        $this->assertEquals($baselineAntes, $componente->instance()->modalBaselineId);
        $this->assertEquals($tendenciaAntes, $componente->instance()->modalTendenciaImportacaoId);
        $this->assertEquals($lb2->id, $componente->instance()->modalBaselineId);
        $this->assertEquals($avanco2->id, $componente->instance()->modalTendenciaImportacaoId);
    }

    // P — exclusão nunca dispara redesenho do gráfico (ação Tipo A).
    public function test_a72_p_exclusao_nao_dispara_atualizacao_do_grafico(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('sem-grafico-2.pdf'), $this->user);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->call('removerAnexoAtividade', $anexo->id)
            ->assertNotDispatched('curva-atividade-atualizada');
    }

    // Q — usuário só com 'ver' enxerga lista/download, mas não upload/exclusão.
    public function test_a72_q_usuario_apenas_com_ver_nao_ve_upload_nem_exclusao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('somente-leitura.pdf'), $this->user);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        $componente = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id);

        $componente->assertSee('somente-leitura.pdf');
        $componente->assertSee(route('atividade-anexos.download', $anexo), false);
        $componente->assertDontSee("anexarArquivoAtividade('{$atividade->id}')", false);
        $componente->assertDontSee('removerAnexoAtividade', false);
    }

    // R — usuário com 'editar' enxerga o input de upload.
    public function test_a72_r_usuario_com_editar_ve_input_de_upload(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        // $this->user já tem Papel::Engenheiro -> 'editar'.
        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee("anexarArquivoAtividade('{$atividade->id}')", false);
    }

    // S — dois anexos com o mesmo nome original coexistem como entradas distintas.
    public function test_a72_s_dois_anexos_com_mesmo_nome_aparecem_como_entradas_separadas(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $anexo1 = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('procedimento.pdf'), $this->user);
        $anexo1->forceFill(['created_at' => now()->subHour()])->save();
        $anexo2 = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('procedimento.pdf'), $this->user);

        $this->assertNotEquals($anexo1->id, $anexo2->id);
        $this->assertEquals(2, AtividadeAnexo::where('atividade_id', $atividade->id)->count());

        $componente = $this->componente()->call('verAtividade', $atividade->id);

        $this->assertEquals(2, $componente->instance()->modalAnexos->count());
        $this->assertEquals(2, substr_count($componente->html(), 'procedimento.pdf'));
    }

    // T — contador da tabela e listagem do popup não escalam em query count.
    /**
     * Ciclo 17, A.7.2.CORREÇÃO — escopo de função dedicado (não o escopo do
     * método de teste), mesmo padrão já usado em
     * PlanoAcaoPainelTest::contarQueriesDaListagem(): DB::listen() empilha
     * listeners globalmente sem removê-los entre chamadas; se duas medições
     * reaproveitassem a MESMA variável do método de teste, o listener da 1ª
     * medição continuaria ativo (e somando na mesma variável) durante a 2ª,
     * inflando o "teto" de comparação e mascarando um N+1 real. Escopos de
     * função distintos isolam cada `$queryCount`, e o listener "morto" da
     * medição anterior passa a incrementar uma variável que ninguém mais lê.
     */
    private function contarQueriesTabelaLookahead(): int
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->componente()->set('fonteData', 'tendencia')->set('janelaDias', 0);

        return $queryCount;
    }

    private function contarQueriesPopupLookahead(string $atividadeId): int
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->componente()->call('verAtividade', $atividadeId);

        return $queryCount;
    }

    public function test_a72_t_contador_e_listagem_de_anexos_nao_geram_n_mais_1(): void
    {
        foreach (range(1, 5) as $i) {
            $at = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
            app(AnexarArquivoAtividade::class)->execute($at, $this->arquivoPdfFakeA72("a{$i}.pdf"), $this->user);
        }
        $queriesCom5 = $this->contarQueriesTabelaLookahead();

        foreach (range(6, 20) as $i) {
            $at = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
            app(AnexarArquivoAtividade::class)->execute($at, $this->arquivoPdfFakeA72("a{$i}.pdf"), $this->user);
        }
        $queriesCom20 = $this->contarQueriesTabelaLookahead();

        $this->assertLessThan($queriesCom5 + 5, $queriesCom20);

        // Popup: mais anexos na MESMA atividade não deve aumentar a
        // quantidade de queries (modalAnexos() é sempre 2 queries: anexos +
        // enviadoPor eager-loaded, nunca 1 por anexo).
        $atividadePopup = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        foreach (range(1, 3) as $i) {
            app(AnexarArquivoAtividade::class)->execute($atividadePopup, $this->arquivoPdfFakeA72("popup{$i}.pdf"), $this->user);
        }
        $queriesPopup3 = $this->contarQueriesPopupLookahead($atividadePopup->id);

        foreach (range(4, 10) as $i) {
            app(AnexarArquivoAtividade::class)->execute($atividadePopup, $this->arquivoPdfFakeA72("popup{$i}.pdf"), $this->user);
        }
        $queriesPopup10 = $this->contarQueriesPopupLookahead($atividadePopup->id);

        $this->assertLessThan($queriesPopup3 + 3, $queriesPopup10);
    }

    // =========================================================================
    // Ciclo 17, A.7.2.CORREÇÃO — isolamento contextual (popup/upload/exclusão
    // só operam na Obra do componente, nunca em outra obra do mesmo tenant)
    // =========================================================================

    /** @return array{0: Work, 1: Atividade} obra B + atividade dela, mesmo tenant do $this->obra */
    private function criarObraBComAtividade(): array
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $obraB->id,
            'nome' => 'Atividade Sigilosa da Obra B',
        ]);

        return [$obraB, $atividadeB];
    }

    public function test_a72_u_ver_atividade_rejeita_atividade_de_outra_obra_do_mesmo_tenant(): void
    {
        [, $atividadeB] = $this->criarObraBComAtividade();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->componente()->call('verAtividade', $atividadeB->id);
    }

    public function test_a72_v_ver_atividade_rejeita_mesmo_com_usuario_tendo_acesso_as_duas_obras(): void
    {
        [$obraB, $atividadeB] = $this->criarObraBComAtividade();
        // $this->user já tem Papel::Engenheiro na Obra A (setUp); vincula o
        // MESMO usuário também na Obra B, com o MESMO nível de acesso —
        // prova que a rejeição é por CONTEXTO da obra, não por autorização.
        $this->vincularObra($obraB, $this->user, Papel::Engenheiro->value);

        $this->assertTrue($this->user->temPermissaoNaObra($obraB->id, 'restricoes.lookahead', 'editar'));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->componente()->call('verAtividade', $atividadeB->id);
    }

    public function test_a72_w_upload_cross_obra_e_bloqueado_mesmo_com_editar_na_outra_obra(): void
    {
        [$obraB, $atividadeB] = $this->criarObraBComAtividade();
        $this->vincularObra($obraB, $this->user, Papel::Engenheiro->value);

        try {
            $this->componente()
                ->set('novoAnexo', $this->arquivoPdfFakeA72('cross-obra.pdf'))
                ->call('anexarArquivoAtividade', $atividadeB->id);
            $this->fail('Esperava ModelNotFoundException ao anexar em atividade de outra obra.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // esperado
        }

        $this->assertEquals(0, AtividadeAnexo::where('atividade_id', $atividadeB->id)->count());
        $this->assertEmpty(Storage::disk(AtividadeAnexo::DISCO)->allFiles());
    }

    public function test_a72_x_exclusao_cross_obra_e_bloqueada_mesmo_com_excluir_na_outra_obra(): void
    {
        [$obraB, $atividadeB] = $this->criarObraBComAtividade();
        $usuarioComExcluirNasDuas = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $usuarioComExcluirNasDuas, Papel::GerentePlanejamento->value);
        $this->vincularObra($obraB, $usuarioComExcluirNasDuas, Papel::GerentePlanejamento->value);

        $anexoB = app(AnexarArquivoAtividade::class)->execute($atividadeB, $this->arquivoPdfFakeA72('protegido-b.pdf'), $usuarioComExcluirNasDuas);

        $this->actingAs($usuarioComExcluirNasDuas);

        try {
            Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
                ->call('removerAnexoAtividade', $anexoB->id);
            $this->fail('Esperava ModelNotFoundException ao remover anexo de atividade de outra obra.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // esperado
        }

        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexoB->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexoB->caminho_arquivo);
    }

    public function test_a72_y_metadata_de_anexo_de_outra_obra_nunca_aparece_mesmo_manipulando_modalatividadeid(): void
    {
        [, $atividadeB] = $this->criarObraBComAtividade();
        app(AnexarArquivoAtividade::class)->execute($atividadeB, $this->arquivoPdfFakeA72('nao-deve-vazar.pdf'), $this->user);

        // Simula manipulação direta da propriedade pública (bypass de
        // verAtividade()) — modalAnexos()/atividadeDetalhe() precisam ter
        // defesa própria, não podem confiar só na guarda de verAtividade().
        $componente = $this->componente()->set('modalAtividadeId', $atividadeB->id);

        $this->assertTrue($componente->instance()->modalAnexos->isEmpty());
        $this->assertNull($componente->instance()->atividadeDetalhe);
        $componente->assertDontSee('nao-deve-vazar.pdf');
    }

    // =========================================================================
    // Ciclo 17, correção pós-QA — Lookahead exige LinhaBase ativa
    // (temLinhaBaseAtiva()). setUp() já cria uma LinhaBase padrão (10 anos no
    // passado, pra nunca virar "a mais recente" em nenhum teste que crie a
    // sua própria) — os testes abaixo removem essa padrão explicitamente
    // pra exercitar o cenário "sem nenhuma LinhaBase ativa".
    // =========================================================================

    public function test_sem_linha_base_com_importacao_baseline_lookahead_fica_bloqueado(): void
    {
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Bloqueada Baseline XYZ',
        ]);

        $componente = $this->componente();
        $this->assertFalse($componente->instance()->temLinhaBaseAtiva);
        $this->assertTrue($componente->instance()->atividades->isEmpty());
        $componente
            ->assertSee('Esta obra ainda não possui uma linha de base ativa.')
            ->assertDontSee('Atividade Bloqueada Baseline XYZ');
    }

    public function test_sem_linha_base_com_importacao_ambos_lookahead_fica_bloqueado(): void
    {
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Bloqueada Ambos XYZ',
        ]);
        $importacaoAmbos = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Ambos->value,
            'importado_em' => now(),
        ]);
        // Importação Ambos tem Previsto/Realizado/Tendência reais — mesmo
        // assim não deve virar LinhaBase operacional sozinha (o usuário
        // precisa ter uma LinhaBase FORMAL salva apontando pra ela).
        foreach ([SerieAvanco::Previsto, SerieAvanco::Realizado, SerieAvanco::Tendencia] as $serie) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacaoAmbos->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => $serie->value,
                'periodo_inicio' => now()->subWeek()->startOfWeek(),
                'horas' => 40,
            ]);
        }

        $componente = $this->componente();
        $this->assertFalse($componente->instance()->temLinhaBaseAtiva);
        $this->assertTrue($componente->instance()->atividades->isEmpty());
        $componente
            ->assertSee('Esta obra ainda não possui uma linha de base ativa.')
            ->assertDontSee('Atividade Bloqueada Ambos XYZ');
    }

    public function test_linha_base_apenas_soft_deleted_conta_como_sem_linha_base_ativa(): void
    {
        // A LinhaBase padrão do setUp() já está ativa — soft-delete ela
        // (em vez de forceDelete) pra provar que uma LinhaBase em lixeira
        // NUNCA conta como ativa, mesmo continuando no banco.
        $padrao = LinhaBase::where('obra_id', $this->obra->id)->firstOrFail();
        $padrao->delete();

        $this->assertEquals(1, LinhaBase::onlyTrashed()->where('obra_id', $this->obra->id)->count());
        $this->assertEquals(0, LinhaBase::where('obra_id', $this->obra->id)->count());

        $componente = $this->componente();
        $this->assertFalse($componente->instance()->temLinhaBaseAtiva);
        $componente->assertSee('Esta obra ainda não possui uma linha de base ativa.');
    }

    public function test_com_uma_linha_base_ativa_tabela_aparece_normalmente(): void
    {
        // Regressão positiva — LinhaBase padrão do setUp() continua ativa,
        // nenhum avanço criado: tabela aparece, Previsto funciona
        // (indiretamente, via popup), Tendência fica N/A.
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Com Baseline Ativa XYZ',
            'baseline_inicio' => now()->addDays(5),
        ]);

        $componente = $this->componente();
        $this->assertTrue($componente->instance()->temLinhaBaseAtiva);
        $componente
            ->assertSee('Atividade Com Baseline Ativa XYZ')
            ->assertDontSee('Esta obra ainda não possui uma linha de base ativa.');
    }

    public function test_restaurar_linha_base_traz_de_volta_tabela_peso_e_anexo_sem_terem_sido_apagados(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Com Historico Preservado XYZ',
            'baseline_inicio' => now()->addDays(5),
        ]);
        $linhaBaseNova = $this->criarLinhaBaseComPrevisto($atividade, [50]); // cria uma 2ª LinhaBase real, com Previsto de verdade
        // % Peso lê especificamente granularidade Mensal (Ciclo 17 A.6) —
        // criarLinhaBaseComPrevisto() só grava Semanal, então precisa de um
        // registro Mensal próprio pra este teste poder provar que o Peso
        // volta a aparecer.
        AvancoPeriodo::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $linhaBaseNova->cronograma_importacao_id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => now()->startOfMonth(),
            'horas' => 50,
        ]);

        $anexo = app(AnexarArquivoAtividade::class)->execute($atividade, $this->arquivoPdfFakeA72('historico.pdf'), $this->user);

        // Confirma estado operacional ANTES de remover nada.
        $antes = $this->componente();
        $this->assertTrue($antes->instance()->temLinhaBaseAtiva);
        $antes->assertSee('Atividade Com Historico Preservado XYZ');

        // Remove TODAS as LinhasBase da obra (padrão do setUp() + a criada
        // acima) — simula exatamente o cenário relatado em QA: excluir
        // todas as linhas de base salvas.
        $linhasBaseIds = LinhaBase::where('obra_id', $this->obra->id)->pluck('id');
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        $bloqueado = $this->componente();
        $this->assertFalse($bloqueado->instance()->temLinhaBaseAtiva);
        $bloqueado->assertSee('Esta obra ainda não possui uma linha de base ativa.');

        // CRÍTICO: nada foi apagado — Atividade, AvancoPeriodo (Previsto),
        // AtividadeAnexo e as próprias LinhaBase (só soft-deleted) continuam
        // intactos no banco.
        $this->assertDatabaseHas('atividades', ['id' => $atividade->id]);
        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexo->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
        $this->assertGreaterThan(0, AvancoPeriodo::where('atividade_id', $atividade->id)->count());
        $this->assertEquals($linhasBaseIds->count(), LinhaBase::onlyTrashed()->where('obra_id', $this->obra->id)->count());

        // Restaura a LinhaBase criada pelo teste (não a padrão do setUp(),
        // que existe só pro resto da suíte) — Lookahead volta a operar,
        // %Peso volta a aparecer, anexo continua acessível pelo popup.
        $linhaBaseRestaurada = LinhaBase::onlyTrashed()
            ->where('obra_id', $this->obra->id)
            ->where('nome', 'BL01')
            ->firstOrFail();
        $linhaBaseRestaurada->restore();

        $depois = $this->componente();
        $this->assertTrue($depois->instance()->temLinhaBaseAtiva);
        $depois->assertSee('Atividade Com Historico Preservado XYZ');

        $depois->call('verAtividade', $atividade->id);
        $this->assertNotNull($depois->get('modalAtividadeId'));
        $this->assertTrue($depois->instance()->modalCurvaAtividade['tem_baseline']);
        $this->assertNotNull($depois->instance()->modalPeso);
        $this->assertEquals(1, $depois->instance()->modalAnexos->count());
        $this->assertEquals('historico.pdf', $depois->instance()->modalAnexos->first()->nome_original);
    }

    // =========================================================================
    // Ciclo 17 — correção das ressalvas da auditoria: atividadeDetalhe()/
    // modalAnexos() precisam de guarda PRÓPRIA (não podem depender só de
    // verAtividade() já ter bloqueado), e a mensagem de "Nova Atividade"
    // sem LinhaBase precisa explicar a causa real, não falar de filtros.
    // =========================================================================

    public function test_teste_a_atividade_detalhe_bloqueado_via_manipulacao_direta_de_modalatividadeid(): void
    {
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Bloqueio Direto ADQ',
        ]);
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'descricao' => 'Restricao Sigilosa Bloqueio Direto ADQ',
        ]);
        AtividadeComentario::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'comentario' => 'Comentario Sigiloso Bloqueio Direto ADQ',
        ]);
        $item = \App\Models\ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Item Checklist Sigiloso Bloqueio Direto ADQ',
            'ordem' => 1,
        ]);
        \App\Models\AtividadeItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);

        $componente = $this->componente();

        // Prova, ANTES da ação, que não existe LinhaBase ativa.
        $this->assertFalse($componente->instance()->temLinhaBaseAtiva);
        $this->assertEquals(0, LinhaBase::where('obra_id', $this->obra->id)->count());

        // Manipulação direta da propriedade pública — nunca chama verAtividade().
        $componente->set('modalAtividadeId', $atividade->id);

        $this->assertNull($componente->instance()->atividadeDetalhe);
        $componente
            ->assertDontSee('Restricao Sigilosa Bloqueio Direto ADQ')
            ->assertDontSee('Comentario Sigiloso Bloqueio Direto ADQ')
            ->assertDontSee('Item Checklist Sigiloso Bloqueio Direto ADQ');
    }

    public function test_teste_b_modalanexos_bloqueado_via_manipulacao_direta_de_modalatividadeid(): void
    {
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Com Anexo Bloqueio Direto ADQ',
        ]);
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $atividade,
            $this->arquivoPdfFakeA72('sigiloso-bloqueio-direto-adq.pdf'),
            $this->user
        );

        $componente = $this->componente();

        // Prova, ANTES da ação, que não existe LinhaBase ativa.
        $this->assertFalse($componente->instance()->temLinhaBaseAtiva);

        // Manipulação direta da propriedade pública — nunca chama verAtividade().
        $componente->set('modalAtividadeId', $atividade->id);

        $this->assertTrue($componente->instance()->modalAnexos->isEmpty());
        $componente->assertDontSee('sigiloso-bloqueio-direto-adq.pdf');

        // O anexo continua existindo de verdade — só a EXPOSIÇÃO dentro do
        // Lookahead foi bloqueada, nunca o registro/arquivo em si.
        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexo->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
    }

    public function test_teste_c_restaurar_linha_base_traz_de_volta_atividadedetalhe_e_modalanexos_sem_duplicar_nada(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Ciclo Completo ADQ',
            'baseline_inicio' => now()->addDays(5),
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'descricao' => 'Restricao Ciclo Completo ADQ',
        ]);
        $comentario = AtividadeComentario::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'comentario' => 'Comentario Ciclo Completo ADQ',
        ]);
        $item = \App\Models\ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Item Ciclo Completo ADQ',
            'ordem' => 1,
        ]);
        \App\Models\AtividadeItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $atividade,
            $this->arquivoPdfFakeA72('ciclo-completo-adq.pdf'),
            $this->user
        );

        // LinhaBase padrão do setUp() já está ativa — confirma estado
        // operacional ANTES de remover nada.
        $antes = $this->componente();
        $this->assertTrue($antes->instance()->temLinhaBaseAtiva);
        $antes->call('verAtividade', $atividade->id);
        $this->assertNotNull($antes->instance()->atividadeDetalhe);
        $this->assertEquals(1, $antes->instance()->modalAnexos->count());

        // Remove (soft-delete) a LinhaBase da obra.
        $linhaBaseId = LinhaBase::where('obra_id', $this->obra->id)->firstOrFail()->id;
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        $bloqueado = $this->componente();
        $this->assertFalse($bloqueado->instance()->temLinhaBaseAtiva);
        $bloqueado->set('modalAtividadeId', $atividade->id);
        $this->assertNull($bloqueado->instance()->atividadeDetalhe);
        $this->assertTrue($bloqueado->instance()->modalAnexos->isEmpty());

        // Restaura legitimamente a MESMA LinhaBase (não recria nada nova).
        LinhaBase::onlyTrashed()->where('id', $linhaBaseId)->firstOrFail()->restore();

        // Nova instância do componente, abre normalmente via verAtividade().
        $depois = $this->componente();
        $this->assertTrue($depois->instance()->temLinhaBaseAtiva);
        $depois->call('verAtividade', $atividade->id);

        $detalhe = $depois->instance()->atividadeDetalhe;
        $this->assertNotNull($detalhe);
        $this->assertEquals($atividade->id, $detalhe['atividade']->id);
        $this->assertCount(1, $detalhe['atividade']->restricoes);
        $this->assertEquals($restricao->id, $detalhe['atividade']->restricoes->first()->id);
        $this->assertCount(1, $detalhe['atividade']->comentarios);
        $this->assertEquals($comentario->id, $detalhe['atividade']->comentarios->first()->id);
        $checklistItem = collect($detalhe['checklist'])->firstWhere('id', $item->id);
        $this->assertNotNull($checklistItem);
        $this->assertTrue($checklistItem['concluido']);

        $anexos = $depois->instance()->modalAnexos;
        $this->assertEquals(1, $anexos->count());
        $this->assertEquals($anexo->id, $anexos->first()->id);
        $this->assertEquals('ciclo-completo-adq.pdf', $anexos->first()->nome_original);

        // Nada foi recriado/duplicado — mesmos IDs, mesma contagem de sempre.
        $this->assertEquals(1, Atividade::where('id', $atividade->id)->count());
        $this->assertEquals(1, Restricao::where('atividade_id', $atividade->id)->count());
        $this->assertEquals(1, AtividadeComentario::where('atividade_id', $atividade->id)->count());
        $this->assertEquals(1, \App\Models\AtividadeItemProntidao::where('atividade_id', $atividade->id)->count());
        $this->assertEquals(1, AtividadeAnexo::where('atividade_id', $atividade->id)->count());
    }

    public function test_teste_d_criar_atividade_manual_sem_linha_base_ativa_mostra_mensagem_didatica(): void
    {
        LinhaBase::where('obra_id', $this->obra->id)->delete();

        $componente = $this->componente();

        // Prova, ANTES da ação, que não existe LinhaBase ativa.
        $this->assertFalse($componente->instance()->temLinhaBaseAtiva);

        $componente
            ->call('abrirModalNovaAtividade')
            ->set('nomeNovaAtividade', 'Atividade Manual Sem Baseline ADQ')
            ->set('inicioNovaAtividade', now()->addDays(3)->toDateString())
            ->set('terminoNovaAtividade', now()->addDays(6)->toDateString())
            ->call('salvarAtividade');

        // A atividade foi criada normalmente — criação nunca é bloqueada.
        $atividade = Atividade::where('nome', 'Atividade Manual Sem Baseline ADQ')->first();
        $this->assertNotNull($atividade);
        $this->assertEquals(OrigemAtividade::Manual, $atividade->origem);

        // Não aparece na tabela operacional — sem LinhaBase, é sempre vazia.
        $this->assertTrue($componente->instance()->atividades->isEmpty());
        $componente->assertDontSee('Atividade Manual Sem Baseline ADQ');

        $componente->assertDispatched('show-toast', function (string $name, array $params) {
            $mensagem = $params['message'] ?? '';

            $this->assertStringNotContainsStringIgnoringCase('janela', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('etapa', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('frente', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('ajuste', $mensagem);
            $this->assertStringContainsStringIgnoringCase('linha de base', $mensagem);

            return ($params['type'] ?? null) === 'warning';
        });
    }

    public function test_teste_e_criar_atividade_manual_com_linha_base_ativa_preserva_logica_antiga_visivel_e_fora_do_filtro(): void
    {
        // LinhaBase padrão do setUp() continua ativa — cenário operacional normal.
        $this->assertTrue($this->componente()->instance()->temLinhaBaseAtiva);

        // Caso 1 (regressão): atividade dentro do filtro atual → mensagem de
        // sucesso simples, sem menção a filtro nenhum — igual sempre foi.
        $componenteVisivel = $this->componente()
            ->set('fonteData', 'tendencia')
            ->call('abrirModalNovaAtividade')
            ->set('nomeNovaAtividade', 'Atividade Manual Visivel Com Baseline ADQ')
            ->set('inicioNovaAtividade', now()->addDays(3)->toDateString())
            ->set('terminoNovaAtividade', now()->addDays(6)->toDateString())
            ->call('salvarAtividade');

        $componenteVisivel->assertDispatched('show-toast', function (string $name, array $params) {
            return ($params['message'] ?? null) === 'Atividade criada.';
        });

        // Caso 2 (regressão): atividade fora da janela atual → aviso antigo
        // sobre janela/etapa/frente — igual sempre foi.
        $componenteForaFiltro = $this->componente()
            ->set('fonteData', 'tendencia')
            ->set('janelaDias', 30)
            ->call('abrirModalNovaAtividade')
            ->set('nomeNovaAtividade', 'Atividade Manual Fora Filtro Com Baseline ADQ')
            ->set('inicioNovaAtividade', now()->addDays(90)->toDateString())
            ->set('terminoNovaAtividade', now()->addDays(95)->toDateString())
            ->call('salvarAtividade');

        $componenteForaFiltro->assertDispatched('show-toast', function (string $name, array $params) {
            $mensagem = $params['message'] ?? '';

            return ($params['type'] ?? null) === 'warning'
                && str_contains($mensagem, 'fora do filtro atual')
                && str_contains($mensagem, 'janela de dias, etapa ou frente de trabalho');
        });

        $this->assertDatabaseHas('atividades', ['nome' => 'Atividade Manual Visivel Com Baseline ADQ']);
        $this->assertDatabaseHas('atividades', ['nome' => 'Atividade Manual Fora Filtro Com Baseline ADQ']);
    }

    // =========================================================================
    // Correção pós-QA (Ciclo 17) — coluna operacional "%" da tabela NUNCA
    // pode vir de Atividade.percentual_concluido (campo ao vivo, gravado
    // pelo importador em QUALQUER tipo de importação, inclusive Baseline).
    // Fonte única: $row['percentualRealizado'], calculada em atividades()
    // a partir de AvancoPeriodo (série Realizado, granularidade Mensal —
    // mesma convenção já usada pelo % Peso) na importação de Avanço/Ambos
    // EFETIVA da página, rebaseada contra o HH Previsto da PRÓPRIA
    // atividade na baseline efetiva (mesma semântica de
    // CurvaAvanco::rebasearPercentual(), sem invocar CurvaAvanco aqui —
    // curva completa por período é desnecessária pro número corrente da
    // tabela). Helpers dedicados desta seção (Mensal, nunca Semanal — ver
    // já documentado acima sobre criarLinhaBaseComPrevisto()/% Peso).
    // =========================================================================

    private function criarImportacaoAvancoMensal(?\Illuminate\Support\Carbon $importadoEm = null, ?string $tipo = null): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => $tipo ?? TipoCronogramaImportacao::Avanco->value,
            'importado_em' => $importadoEm ?? now(),
        ]);
    }

    private function criarAvancoPeriodoRealizadoMensal(Atividade $atividade, CronogramaImportacao $importacao, array $horasPorMes): void
    {
        $mes = now()->subMonths(count($horasPorMes))->startOfMonth();
        foreach ($horasPorMes as $horas) {
            AvancoPeriodo::create([
                'tenant_id' => $this->obra->tenant_id,
                'cronograma_importacao_id' => $importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Mensal->value,
                'serie' => SerieAvanco::Realizado->value,
                'periodo_inicio' => $mes->copy(),
                'horas' => $horas,
            ]);
            $mes->addMonth();
        }
    }

    /**
     * Teste A — REPRODUÇÃO DO BUG ORIGINAL, agora como regressão automatizada:
     * LinhaBase ativa + SOMENTE importação Baseline (nenhuma Avanço/Ambos) +
     * Atividade.percentual_concluido = 85 (exatamente o que o
     * MsProjectImporter grava numa importação Baseline-only, ver
     * aplicar()). A coluna % NUNCA pode mostrar 85%, 0% ou qualquer valor —
     * deve ser null/"—", igual às datas de Tendência (que já são N/A neste
     * cenário).
     */
    public function test_A_somente_baseline_sem_avanco_percentual_e_nulo_nunca_o_campo_ao_vivo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'baseline_inicio' => now()->subDays(10),
            'baseline_termino' => now()->addDays(10),
            'percentual_concluido' => 85,
        ]);

        $componente = $this->componente()->set('janelaDias', 0);

        $this->assertFalse($componente->instance()->temImportacaoAvanco);

        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertNull($row['percentualRealizado']);

        $html = $componente->html();
        $this->assertStringNotContainsString('>85%<', $html);
    }

    /**
     * Teste B — existe importação de Avanço/Ambos na obra, mas a ATIVIDADE
     * específica não tem nenhum HH Previsto registrado na baseline efetiva
     * (sem denominador pra rebasear) — percentual continua null, mesmo
     * regra já aplicada ao % Peso (test_a6_c).
     */
    public function test_B_avanco_existe_mas_atividade_sem_hh_previsto_percentual_e_nulo(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        // Nenhum AvancoPeriodo Previsto pra esta atividade nesta baseline.

        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [50]);

        $row = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0)
            ->instance()
            ->atividades
            ->firstWhere('atividade.id', $atividade->id);

        $this->assertNull($row['percentualRealizado']);
    }

    /**
     * Teste C — O MAIS IMPORTANTE desta correção: prova que Baseline NUNCA
     * é fonte do avanço, mesmo quando o campo ao vivo (percentual_concluido
     * = 85, herdado da importação Baseline) e o Avanço real divergem.
     * Baseline: atividade com 100HH Previsto. Avanço real: 20HH Realizado
     * (= 20%). A tabela DEVE mostrar 20%, NUNCA 85%.
     */
    public function test_C_baseline_com_85_porcento_no_campo_ao_vivo_mas_avanco_real_e_20_tabela_mostra_20(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'percentual_concluido' => 85, // valor "herdado" da importação Baseline — nunca deve vazar pra tabela
        ]);

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [20]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0);

        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);

        $this->assertEqualsWithDelta(20.0, $row['percentualRealizado'], 0.05);
        $this->assertNotEquals(85.0, $row['percentualRealizado']);

        $html = $componente->html();
        $this->assertStringContainsString('>20%<', $html);
        $this->assertStringNotContainsString('>85%<', $html);
    }

    /**
     * Teste D — importação tipo "Ambos" também é fonte válida (mesma regra
     * já aplicada às datas de Tendência/importacoesDisponiveis()): 50HH
     * Previsto / 30HH Realizado na MESMA importação Ambos = 60%.
     */
    public function test_D_importacao_tipo_ambos_tambem_alimenta_o_percentual_realizado(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $ambos = $this->criarImportacaoAvancoMensal(tipo: TipoCronogramaImportacao::Ambos->value);
        $lb = LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'LB via Ambos',
            'cronograma_importacao_id' => $ambos->id,
        ]);
        $this->criarAvancoPeriodoPrevistoMensal($atividade, $ambos, [50]);
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $ambos, [30]);

        $row = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $ambos->id)
            ->set('janelaDias', 0)
            ->instance()
            ->atividades
            ->firstWhere('atividade.id', $atividade->id);

        $this->assertEqualsWithDelta(60.0, $row['percentualRealizado'], 0.05);
    }

    /**
     * Teste E — distingue "sem dado" (atividade nunca tocada pelo Avanço —
     * nenhum AvancoPeriodo Realizado pra ela) de "zero real" (Realizado
     * explicitamente registrado somando 0HH). Mesma distinção já aplicada
     * ao % Peso (test_a6_c vs test_a6_d), agora replicada pro Realizado.
     */
    public function test_E_distingue_sem_dado_de_avanco_de_avanco_real_somando_zero(): void
    {
        $atividadeSemDado = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeZeroReal = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $importacaoBaseline = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeSemDado, $importacaoBaseline, [100]);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeZeroReal, $importacaoBaseline, [100]);

        $avanco = $this->criarImportacaoAvancoMensal();
        // $atividadeSemDado nunca recebe nenhum AvancoPeriodo Realizado.
        $this->criarAvancoPeriodoRealizadoMensal($atividadeZeroReal, $avanco, [0]);

        $rows = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0)
            ->instance()
            ->atividades;

        $this->assertNull($rows->firstWhere('atividade.id', $atividadeSemDado->id)['percentualRealizado']);
        $this->assertEqualsWithDelta(0.0, $rows->firstWhere('atividade.id', $atividadeZeroReal->id)['percentualRealizado'], 0.001);
    }

    /**
     * Teste F — % Peso permanece 100% independente e correto mesmo com a
     * correção de % Realizado no mesmo map() da tabela (as duas colunas
     * usam queries em lote separadas, sem interferência entre si).
     */
    public function test_F_peso_permanece_correto_e_independente_apos_a_correcao_do_percentual_realizado(): void
    {
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $importacaoBaseline = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeA, $importacaoBaseline, [30]); // 30HH
        $this->criarAvancoPeriodoPrevistoMensal($atividadeB, $importacaoBaseline, [70]); // 70HH — total 100HH

        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividadeA, $avanco, [15]); // 50% de 30HH

        $rows = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0)
            ->instance()
            ->atividades;

        $rowA = $rows->firstWhere('atividade.id', $atividadeA->id);
        $rowB = $rows->firstWhere('atividade.id', $atividadeB->id);

        $this->assertEqualsWithDelta(30.0, $rowA['peso'], 0.05);
        $this->assertEqualsWithDelta(70.0, $rowB['peso'], 0.05);
        $this->assertEqualsWithDelta(50.0, $rowA['percentualRealizado'], 0.05);
        $this->assertNull($rowB['percentualRealizado']); // sem nenhum Realizado pra B
    }

    /**
     * Teste G — trocar a importação de tendência selecionada
     * ($tendenciaImportacaoId) atualiza o % Realizado pra refletir a nova
     * fotografia de avanço (nunca fica preso na primeira importação lida).
     */
    public function test_G_trocar_a_importacao_de_tendencia_atualiza_o_percentual_realizado(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $avancoAntigo = $this->criarImportacaoAvancoMensal(now()->subDays(10));
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avancoAntigo, [30]);

        $avancoNovo = $this->criarImportacaoAvancoMensal(now());
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avancoNovo, [70]);

        $componente = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0);

        $componente->set('tendenciaImportacaoId', $avancoAntigo->id);
        $pctAntigo = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id)['percentualRealizado'];

        $componente->set('tendenciaImportacaoId', $avancoNovo->id);
        $pctNovo = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id)['percentualRealizado'];

        $this->assertEqualsWithDelta(30.0, $pctAntigo, 0.05);
        $this->assertEqualsWithDelta(70.0, $pctNovo, 0.05);
        $this->assertNotEquals($pctAntigo, $pctNovo);
    }

    /**
     * Teste H — exportações (Excel/PDF flat/PDF árvore) usam a MESMA fonte
     * corrigida ($row['percentualRealizado']), nunca $at->percentual_concluido.
     * LookaheadExport::map() é chamado diretamente (mesma técnica já usada
     * por outros testes de export no projeto), evitando depender de
     * geração real de arquivo binário só pra verificar a coluna certa.
     */
    public function test_H_exports_excel_e_pdf_usam_o_percentual_corrigido_nunca_o_campo_ao_vivo(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'percentual_concluido' => 85,
        ]);

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);
        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [20]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0);

        $linhas = $componente->instance()->atividades;
        $linha = $linhas->firstWhere('atividade.id', $atividade->id);

        $export = new \App\Exports\LookaheadExport($linhas, true);
        $mapeada = $export->map($linha);

        // Índice 7 = coluna "Avanço" (ver headings()).
        $this->assertSame('20%', $mapeada[7]);
        $this->assertNotSame('85%', $mapeada[7]);

        // PDFs (flat e árvore) leem $linha['percentualRealizado'] direto —
        // confirmado por leitura de código nesta correção; a view em si
        // (dompdf) não é exercitada aqui (mesma convenção já usada pelos
        // demais testes desta suíte pra exports, que chamam o download
        // sem inspecionar o binário renderizado).
        $this->assertEqualsWithDelta(20.0, $linha['percentualRealizado'], 0.05);
    }

    /**
     * Teste I — a nova query em lote de % Realizado nunca vira N+1: com 6
     * atividades na tabela, exatamente 1 (UMA) query bate em `avanco_periodos`
     * filtrando `serie = 'realizado'` — nunca 1 por atividade.
     *
     * Comparar CONTAGEM TOTAL bruta de queries entre 2 instanciações
     * separadas de Livewire::test() (como o padrão estabelecido em
     * test_a72_t_contador_e_listagem_de_anexos_nao_geram_n_mais_1) provou
     * ser não-determinístico aqui: um cache em memória de request
     * (permissões/itens de prontidão) pode ser "aquecido" de forma
     * diferente entre a 1ª e a 2ª instanciação dentro do MESMO método de
     * teste, produzindo uma diferença de contagem sem relação nenhuma com
     * N+1 real (diagnosticado via dump de SQL antes de escrever este
     * teste: a 2ª instanciação pulou 3 queries de permissão/prontidão já
     * resolvidas pela 1ª). A asserção abaixo é mais precisa: inspeciona o
     * SQL de cada query já dentro de uma ÚNICA instanciação/render e conta
     * só as que batem no padrão da nova query — robusta a qualquer
     * variação de cache de outras partes da página.
     */
    public function test_I_percentual_realizado_nao_gera_n_mais_1_na_tabela(): void
    {
        $lb = $this->criarLinhaBaseMensal();
        $importacaoBaseline = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $avanco = $this->criarImportacaoAvancoMensal();

        foreach (range(1, 6) as $i) {
            $at = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
            $this->criarAvancoPeriodoPrevistoMensal($at, $importacaoBaseline, [100]);
            $this->criarAvancoPeriodoRealizadoMensal($at, $avanco, [$i * 10]);
        }

        // Setup do estado (linhaBaseId/tendenciaImportacaoId) ANTES de
        // registrar o listener — cada ->set() do Livewire::test() dispara
        // um ciclo de render completo próprio (mount + N sets = N+1
        // renders, cada um reavaliando atividades() por trás do Blade);
        // medir com o listener já ativo durante essas trocas infla a
        // contagem em múltiplos inteiros do número de sets, mascarando o
        // que de fato importa aqui — quantas vezes a query em lote nova
        // dispara POR RENDER. Isolando o listener só ao redor do ÚLTIMO
        // ->set() mede exatamente 1 render.
        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id);

        $queriesRealizado = 0;
        $queriesPrevisto = 0;
        DB::listen(function ($query) use (&$queriesRealizado, &$queriesPrevisto) {
            if (str_contains($query->sql, 'avanco_periodos') && str_contains($query->sql, 'group by')) {
                if (in_array('realizado', $query->bindings, true)) {
                    $queriesRealizado++;
                } elseif (in_array('previsto', $query->bindings, true)) {
                    $queriesPrevisto++;
                }
            }
        });

        $componente->set('janelaDias', 0);

        // Exatamente 1 query em lote por série (Previsto pro %Peso já
        // existente, Realizado pro %Realizado desta correção) — nunca 6
        // (1 por atividade), nunca 0 (a query precisa ter disparado).
        $this->assertEquals(1, $queriesRealizado);
        $this->assertEquals(1, $queriesPrevisto);
    }

    /**
     * Teste J — isolamento entre atividades: cada linha da tabela mostra o
     * percentual da PRÓPRIA atividade, sem vazamento entre atividades
     * diferentes no mesmo lote (prova a correção da query em lote —
     * agrupamento por atividade_id nunca mistura valores).
     */
    public function test_J_percentual_realizado_e_isolado_por_atividade_no_mesmo_lote(): void
    {
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividadeC = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $importacaoBaseline = CronogramaImportacao::find($lb->cronograma_importacao_id);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeA, $importacaoBaseline, [100]);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeB, $importacaoBaseline, [100]);
        $this->criarAvancoPeriodoPrevistoMensal($atividadeC, $importacaoBaseline, [100]);

        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividadeA, $avanco, [10]);
        $this->criarAvancoPeriodoRealizadoMensal($atividadeB, $avanco, [50]);
        $this->criarAvancoPeriodoRealizadoMensal($atividadeC, $avanco, [90]);

        $rows = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0)
            ->instance()
            ->atividades;

        $this->assertEqualsWithDelta(10.0, $rows->firstWhere('atividade.id', $atividadeA->id)['percentualRealizado'], 0.05);
        $this->assertEqualsWithDelta(50.0, $rows->firstWhere('atividade.id', $atividadeB->id)['percentualRealizado'], 0.05);
        $this->assertEqualsWithDelta(90.0, $rows->firstWhere('atividade.id', $atividadeC->id)['percentualRealizado'], 0.05);
    }

    /**
     * Teste K — o popup de detalhe (Curva S da atividade, já corrigido em
     * fase anterior do Ciclo 17) continua 100% intocado e correto: a tabela
     * e o popup, escopados pela MESMA baseline/tendência, concordam no
     * mesmo percentual de Realizado pra mesma atividade — regressão contra
     * qualquer divergência introduzida por esta correção.
     */
    public function test_K_tabela_e_popup_concordam_no_percentual_realizado_para_a_mesma_atividade(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [35]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0);

        $pctTabela = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id)['percentualRealizado'];

        // Ciclo 17, A.8 — o indicador resumido do popup (percentual_realizado)
        // é canônico (App\Services\AvancoAtividade, sempre Mensal) desde esta
        // correção: NÃO precisa mais alinhar modalGranularidade ao dado
        // seedado — concorda com a tabela independentemente da escala
        // escolhida pelo usuário no seletor do gráfico (prova formal disso
        // em test_L, abaixo).
        $componente->call('verAtividade', $atividade->id);
        $pctPopup = $componente->instance()->modalCurvaAtividade['percentual_realizado'];

        $this->assertEqualsWithDelta(35.0, $pctTabela, 0.05);
        $this->assertEqualsWithDelta(35.0, $pctPopup, 0.05);
        $this->assertEqualsWithDelta($pctTabela, $pctPopup, 0.05);
    }

    // =========================================================================
    // CICLO 17, A.8 — PARIDADE TABELA × POPUP (auditoria adversarial)
    // =========================================================================

    /**
     * Teste L — CRÍTICO: alternar a granularidade do gráfico do popup
     * (Semanal ↔ Mensal) NÃO pode mudar o indicador resumido
     * percentual_realizado — só os pontos/labels da curva mudam. Prova
     * exatamente o achado C da auditoria (tabela sempre Mensal fixo, popup
     * variava com o seletor, causando X% ≠ Y% pra mesma atividade/Baseline/
     * Avanço).
     */
    public function test_L_alternar_granularidade_do_grafico_nao_muda_o_percentual_realizado(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseComPrevisto($atividade, [40, 60]); // 100HH Semanal
        $this->espelharPrevistoMensal($lb, $atividade, [40, 60]); // 100HH Mensal (mesmo total)
        $avanco = $this->criarImportacaoAvancoComSeries($atividade, [SerieAvanco::Realizado->value => [30]], espelharMensal: true); // 30HH nas duas

        $componente = $this->componente()->call('verAtividade', $atividade->id);

        $componente->set('modalGranularidade', 'semanal');
        $pctSemanal = $componente->instance()->modalCurvaAtividade['percentual_realizado'];
        $labelsSemanal = $componente->instance()->modalCurvaAtividade['labels'];

        $componente->set('modalGranularidade', 'mensal');
        $pctMensal = $componente->instance()->modalCurvaAtividade['percentual_realizado'];
        $labelsMensal = $componente->instance()->modalCurvaAtividade['labels'];

        $this->assertEqualsWithDelta(30.0, $pctSemanal, 0.05);
        $this->assertEqualsWithDelta(30.0, $pctMensal, 0.05);
        $this->assertEquals($pctSemanal, $pctMensal, 'percentual_realizado precisa ser EXATAMENTE igual, não só próximo');
        // As séries/labels da curva, ao contrário, mudam com a granularidade
        // — prova que só o indicador ficou canônico, o gráfico continua vivo.
        $this->assertNotEquals($labelsSemanal, $labelsMensal);
    }

    /**
     * Teste M — CRÍTICO: um CurvaAjuste GLOBAL (curva geral do
     * empreendimento, nenhum filtro de pacote/etapa/disciplina/frente/etc.)
     * NÃO pode contaminar o indicador resumido de uma atividade individual.
     * Prova o achado da investigação: CurvaAvanco::calcular() aplica esse
     * ajuste à curva VISUAL de qualquer atividade (bug pré-existente, fora
     * do escopo desta correção — ver docblock de AvancoAtividade), mas o
     * indicador (tabela E popup) usa a fonte canônica, que nunca consulta
     * curva_ajustes — prova que a proteção funciona e que a divergência
     * fica sinalizada explicitamente via curva_diverge_do_indicador.
     */
    public function test_M_curva_ajuste_global_nao_contamina_o_indicador_individual(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $periodo = now()->subMonth()->startOfMonth();

        $importacaoBaseline = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value, 'importado_em' => now()->subDays(30),
        ]);
        $lb = LinhaBase::create(['obra_id' => $this->obra->id, 'nome' => 'LB Ajuste', 'cronograma_importacao_id' => $importacaoBaseline->id]);
        AvancoPeriodo::create([
            'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $importacaoBaseline->id,
            'atividade_id' => $atividade->id, 'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value, 'periodo_inicio' => $periodo, 'horas' => 100,
        ]);

        $importacaoAvanco = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value, 'importado_em' => now(),
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $importacaoAvanco->id,
            'atividade_id' => $atividade->id, 'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Realizado->value, 'periodo_inicio' => $periodo, 'horas' => 30, // 30% real
        ]);

        // Ajuste GLOBAL: obra inteira, nenhum pacote/etapa/disciplina/frente/
        // etc. selecionado — exatamente o "bucket" que CurvaAvanco::calcular()
        // usa quando chamado só com atividadeId (nenhum outro filtro).
        CurvaAjuste::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'serie' => SerieAvanco::Realizado->value,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'periodo_inicio' => $periodo,
            'valor_ajustado' => 90, // valor manipulado, bem diferente de 30
            'valor_calculado_no_ajuste' => 30,
        ]);

        // --- TABELA: nunca consulta CurvaAjuste — precisa continuar 30%.
        $rowTabela = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $importacaoAvanco->id)
            ->set('janelaDias', 0)
            ->instance()
            ->atividades
            ->firstWhere('atividade.id', $atividade->id);
        $this->assertEqualsWithDelta(30.0, $rowTabela['percentualRealizado'], 0.05, 'tabela contaminada por ajuste global');

        // --- POPUP: a CURVA visual (CurvaAvanco::calcular()) É contaminada
        // pelo ajuste (achado documentado da investigação) — confirma que o
        // cenário de teste realmente reproduz o bug de origem.
        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $importacaoAvanco->id)
            ->call('verAtividade', $atividade->id)
            ->set('modalGranularidade', 'mensal');
        $curva = $componente->instance()->modalCurvaAtividade;
        $ultimoPontoCurva = collect($curva['realizado'])->last();
        $this->assertTrue($ultimoPontoCurva['ajustado'], 'pré-condição: a curva precisa mesmo estar sob efeito do ajuste global');
        $this->assertEqualsWithDelta(90.0, $ultimoPontoCurva['percentual'], 0.5, 'pré-condição: curva contaminada com o valor ajustado');

        // --- POPUP: o INDICADOR resumido (fonte canônica) precisa continuar
        // 30%, mesmo com a curva mostrando 90% ao lado.
        $this->assertEqualsWithDelta(30.0, $curva['percentual_realizado'], 0.05, 'indicador do popup contaminado por ajuste global');
        $this->assertNotEquals(90.0, $curva['percentual_realizado']);

        // --- A divergência entre curva (90%) e indicador (30%) precisa
        // ficar sinalizada explicitamente pro Blade poder deixar isso claro
        // ao usuário — nunca uma divergência silenciosa.
        $this->assertTrue($curva['curva_diverge_do_indicador']);

        // --- UX: o ícone/tooltip de esclarecimento precisa aparecer no HTML
        // renderizado quando essa divergência existe.
        $html = $componente->html();
        $this->assertStringContainsString('bx-info-circle', $html);
    }

    /**
     * Teste N — tabela e popup continuam concordando quando SÓ a Baseline
     * muda (denominador diferente, numerador igual) — mesmo cenário já
     * provado só pra tabela em testes anteriores, agora com paridade
     * explícita popup incluída.
     */
    public function test_N_tabela_e_popup_concordam_ao_trocar_somente_a_baseline(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lbA = $this->criarLinhaBaseMensal('LB-A');
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lbA->cronograma_importacao_id), [100]);
        $lbB = $this->criarLinhaBaseMensal('LB-B');
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lbB->cronograma_importacao_id), [50]);
        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [25]);

        $componente = $this->componente()->set('tendenciaImportacaoId', $avanco->id)->set('janelaDias', 0);

        $componente->set('linhaBaseId', $lbA->id);
        $rowA = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $curvaA = $componente->call('verAtividade', $atividade->id)->set('modalBaselineId', $lbA->id)->instance()->modalCurvaAtividade;

        $componente->set('linhaBaseId', $lbB->id);
        $rowB = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $curvaB = $componente->call('verAtividade', $atividade->id)->set('modalBaselineId', $lbB->id)->instance()->modalCurvaAtividade;

        $this->assertEqualsWithDelta(25.0, $rowA['percentualRealizado'], 0.05); // 25/100
        $this->assertEqualsWithDelta(25.0, $curvaA['percentual_realizado'], 0.05);
        $this->assertEqualsWithDelta(50.0, $rowB['percentualRealizado'], 0.05); // 25/50
        $this->assertEqualsWithDelta(50.0, $curvaB['percentual_realizado'], 0.05);
        $this->assertNotEquals($rowA['percentualRealizado'], $rowB['percentualRealizado']);
    }

    /**
     * Teste O — tabela e popup continuam concordando quando SÓ o Avanço
     * muda (numerador diferente, denominador igual).
     */
    public function test_O_tabela_e_popup_concordam_ao_trocar_somente_o_avanco(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);
        $avancoX = $this->criarImportacaoAvancoMensal(now()->subDays(10));
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avancoX, [20]);
        $avancoY = $this->criarImportacaoAvancoMensal(now());
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avancoY, [60]);

        $componente = $this->componente()->set('linhaBaseId', $lb->id)->set('janelaDias', 0);

        $componente->set('tendenciaImportacaoId', $avancoX->id);
        $rowX = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $curvaX = $componente->call('verAtividade', $atividade->id)->set('modalTendenciaImportacaoId', $avancoX->id)->instance()->modalCurvaAtividade;

        $componente->set('tendenciaImportacaoId', $avancoY->id);
        $rowY = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $curvaY = $componente->call('verAtividade', $atividade->id)->set('modalTendenciaImportacaoId', $avancoY->id)->instance()->modalCurvaAtividade;

        $this->assertEqualsWithDelta(20.0, $rowX['percentualRealizado'], 0.05);
        $this->assertEqualsWithDelta(20.0, $curvaX['percentual_realizado'], 0.05);
        $this->assertEqualsWithDelta(60.0, $rowY['percentualRealizado'], 0.05);
        $this->assertEqualsWithDelta(60.0, $curvaY['percentual_realizado'], 0.05);
    }

    /**
     * Teste P — Realizado genuinamente zero (registro existe, soma 0):
     * tabela e popup concordam em 0,0%, nunca "—"/null.
     */
    public function test_P_tabela_e_popup_concordam_em_zero_real(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);
        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [0]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0);

        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $curva = $componente->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertNotNull($row['percentualRealizado']);
        $this->assertEqualsWithDelta(0.0, $row['percentualRealizado'], 0.001);
        $this->assertNotNull($curva['percentual_realizado']);
        $this->assertEqualsWithDelta(0.0, $curva['percentual_realizado'], 0.001);
    }

    // =========================================================================
    // Ciclo 17, A.8.HARDENING — tendenciaImportacaoId/modalTendenciaImportacaoId
    // são propriedades Livewire públicas (client-controlled); a partir desta
    // etapa, um ID só vira referência temporal depois de provado pertencer a
    // importacoesDisponiveis() (obra atual + tipo Avanco/Ambos). Mesmo
    // princípio já usado pra LinhaBase: ID inválido/manipulado NEUTRALIZA
    // (nunca cai silenciosamente pro fallback nem usa a importação alheia).
    // =========================================================================

    private function criarImportacaoParaObra(Work $obra, TipoCronogramaImportacao $tipo, ?\Illuminate\Support\Carbon $importadoEm = null): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => $tipo->value,
            'importado_em' => $importadoEm ?? now(),
        ]);
    }

    /**
     * Teste A — página: manipular tendenciaImportacaoId pra apontar uma
     * importação de Avanço de OUTRA obra do mesmo tenant neutraliza (nunca
     * usa a importação alheia, nunca cai silenciosamente pro fallback).
     */
    public function test_hardening_a_pagina_neutraliza_tendencia_de_outra_obra_do_mesmo_tenant(): void
    {
        [$obraB] = $this->criarObraBComAtividade();
        $avancoB = $this->criarImportacaoParaObra($obraB, TipoCronogramaImportacao::Avanco);

        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avancoB->id)
            ->set('janelaDias', 0);

        $this->assertNull($componente->instance()->importacaoTendenciaAtual);
        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertNull($row['percentualRealizado']);
        $this->assertNull($row['inicioTendencia']);
        $this->assertNull($row['terminoTendencia']);
    }

    /**
     * Teste B — mesmo cenário do A, mas o usuário tem acesso REAL às DUAS
     * obras (mesmo perfil/permissão nas duas) — prova que a rejeição é por
     * CONTEXTO da obra ativa, nunca por falta de autorização.
     */
    public function test_hardening_b_neutraliza_mesmo_com_usuario_tendo_acesso_real_as_duas_obras(): void
    {
        [$obraB] = $this->criarObraBComAtividade();
        $this->vincularObra($obraB, $this->user, Papel::Engenheiro->value);
        $avancoB = $this->criarImportacaoParaObra($obraB, TipoCronogramaImportacao::Avanco);

        $this->assertTrue($this->user->temPermissaoNaObra($obraB->id, 'restricoes.lookahead', 'editar'));

        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avancoB->id)
            ->set('janelaDias', 0);

        $this->assertNull($componente->instance()->importacaoTendenciaAtual);
        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertNull($row['percentualRealizado']);
    }

    /**
     * Teste C — popup: manipular modalTendenciaImportacaoId pra outra obra
     * do mesmo tenant neutraliza — nunca produz Realizado/Tendência daquela
     * obra dentro do popup.
     */
    public function test_hardening_c_popup_neutraliza_tendencia_de_outra_obra_do_mesmo_tenant(): void
    {
        [$obraB] = $this->criarObraBComAtividade();
        $avancoB = $this->criarImportacaoParaObra($obraB, TipoCronogramaImportacao::Avanco);

        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $curva = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $lb->id)
            ->set('modalTendenciaImportacaoId', $avancoB->id)
            ->instance()
            ->modalCurvaAtividade;

        $this->assertFalse($curva['tem_tendencia_selecionada']);
        $this->assertFalse($curva['tem_realizado']);
        $this->assertNull($curva['percentual_realizado']);
    }

    /**
     * Teste D — página: importação de Avanço de OUTRO TENANT nunca é
     * aceita, mesmo com o ID correto (global scope de BelongsToTenant já
     * protege a query, mas a validação central também precisa neutralizar).
     */
    public function test_hardening_d_pagina_nunca_aceita_tendencia_de_outro_tenant(): void
    {
        $tenantC = Tenant::factory()->create();
        $avancoC = TenantContext::actingAs($tenantC, function () use ($tenantC) {
            $obraC = Work::factory()->create(['tenant_id' => $tenantC->id]);
            return $this->criarImportacaoParaObra($obraC, TipoCronogramaImportacao::Avanco);
        });

        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avancoC->id)
            ->set('janelaDias', 0);

        $this->assertNull($componente->instance()->importacaoTendenciaAtual);
        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertNull($row['percentualRealizado']);
    }

    /** Teste E — mesmo cenário do D, mas no popup. */
    public function test_hardening_e_popup_nunca_aceita_tendencia_de_outro_tenant(): void
    {
        $tenantC = Tenant::factory()->create();
        $avancoC = TenantContext::actingAs($tenantC, function () use ($tenantC) {
            $obraC = Work::factory()->create(['tenant_id' => $tenantC->id]);
            return $this->criarImportacaoParaObra($obraC, TipoCronogramaImportacao::Avanco);
        });

        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $curva = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->call('verAtividade', $atividade->id)
            ->set('modalBaselineId', $lb->id)
            ->set('modalTendenciaImportacaoId', $avancoC->id)
            ->instance()
            ->modalCurvaAtividade;

        $this->assertFalse($curva['tem_tendencia_selecionada']);
        $this->assertNull($curva['percentual_realizado']);
    }

    /**
     * Teste F — uma importação Baseline PURA (mesma obra) nunca é aceita
     * como Tendência, mesmo selecionada explicitamente — importacoesDisponiveis()
     * já filtra por tipo Avanco/Ambos, então o ID de uma Baseline pura
     * simplesmente não está no conjunto válido.
     */
    public function test_hardening_f_baseline_pura_nunca_e_aceita_como_tendencia(): void
    {
        $baselinePura = $this->criarImportacaoParaObra($this->obra, TipoCronogramaImportacao::Baseline);

        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $baselinePura->id)
            ->set('janelaDias', 0);

        $this->assertNull($componente->instance()->importacaoTendenciaAtual);
        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertNull($row['percentualRealizado']);
    }

    /** Teste G — ID inexistente não causa exception nem vazamento, só neutraliza. */
    public function test_hardening_g_id_inexistente_nao_causa_excecao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
            ->set('janelaDias', 0);

        $this->assertNull($componente->instance()->importacaoTendenciaAtual);
        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertNull($row['percentualRealizado']);
    }

    /** Teste H — seleção explícita VÁLIDA (mesma obra, tipo Avanço) continua funcionando normalmente. */
    public function test_hardening_h_selecao_valida_explicita_continua_funcionando(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);
        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [40]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0);

        $this->assertEquals($avanco->id, $componente->instance()->importacaoTendenciaAtual->id);
        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertEqualsWithDelta(40.0, $row['percentualRealizado'], 0.05);
    }

    /** Teste I — sem seleção explícita, fallback continua escolhendo a importação Avanço/Ambos mais recente da obra. */
    public function test_hardening_i_fallback_sem_selecao_continua_escolhendo_mais_recente(): void
    {
        $antigo = $this->criarImportacaoAvancoMensal(now()->subDays(10));
        $recente = $this->criarImportacaoAvancoMensal(now());

        $componente = $this->componente()->set('janelaDias', 0);

        $this->assertEquals($recente->id, $componente->instance()->importacaoTendenciaAtual->id);
    }

    /**
     * Teste J — herança página→popup continua correta: popup herda a
     * tendência efetiva da página ao abrir; trocar dentro do popup nunca
     * escreve de volta na página; reabrir noutra atividade volta a herdar
     * a página (nunca a escolha feita no popup anterior).
     */
    public function test_hardening_j_heranca_pagina_popup_continua_correta(): void
    {
        $atividade1 = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $atividade2 = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $avancoX = $this->criarImportacaoAvancoMensal(now()->subDays(10));
        $avancoY = $this->criarImportacaoAvancoMensal(now());

        $componente = $this->componente()
            ->set('tendenciaImportacaoId', $avancoX->id)
            ->set('janelaDias', 0);

        // Página = Avanço X → abre popup → modal herda X.
        $componente->call('verAtividade', $atividade1->id);
        $this->assertEquals($avancoX->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);

        // Popup muda pra Avanço Y → página continua X.
        $componente->set('modalTendenciaImportacaoId', $avancoY->id);
        $this->assertEquals($avancoY->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);
        $this->assertEquals($avancoX->id, $componente->instance()->tendenciaImportacaoId);

        // Fecha/reabre noutra atividade → volta a herdar X da página.
        $componente->call('verAtividade', $atividade2->id);
        $this->assertEquals($avancoX->id, $componente->instance()->modalCurvaAtividade['tendencia_id']);
    }

    /**
     * Teste K — regressão de paridade tabela×popup (A.8) preservada após o
     * hardening: mesma atividade/Baseline/Avanço válidos → mesmo percentual.
     */
    public function test_hardening_k_tabela_e_popup_continuam_concordando_apos_o_hardening(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);
        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [35]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0);

        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $curva = $componente->call('verAtividade', $atividade->id)->instance()->modalCurvaAtividade;

        $this->assertEqualsWithDelta(35.0, $row['percentualRealizado'], 0.05);
        $this->assertEqualsWithDelta($row['percentualRealizado'], $curva['percentual_realizado'], 0.05);
    }

    /**
     * Teste L — CurvaAjuste global continua sem alterar o indicador
     * factual após o hardening (AvancoAtividade/CurvaAjuste intocados
     * nesta etapa — reconfirma que a correção A.8 continua íntegra).
     */
    public function test_hardening_l_curva_ajuste_global_continua_sem_alterar_indicador(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $periodo = now()->subMonth()->startOfMonth();

        $lb = $this->criarLinhaBaseMensal();
        $this->criarAvancoPeriodoPrevistoMensal($atividade, CronogramaImportacao::find($lb->cronograma_importacao_id), [100]);
        $avanco = $this->criarImportacaoAvancoMensal();
        $this->criarAvancoPeriodoRealizadoMensal($atividade, $avanco, [30]);

        CurvaAjuste::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'serie' => SerieAvanco::Realizado->value,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'periodo_inicio' => $periodo,
            'valor_ajustado' => 90,
            'valor_calculado_no_ajuste' => 30,
        ]);

        $componente = $this->componente()
            ->set('linhaBaseId', $lb->id)
            ->set('tendenciaImportacaoId', $avanco->id)
            ->set('janelaDias', 0);

        $row = $componente->instance()->atividades->firstWhere('atividade.id', $atividade->id);
        $this->assertEqualsWithDelta(30.0, $row['percentualRealizado'], 0.05);
        $this->assertNotEquals(90.0, $row['percentualRealizado']);
    }
}
