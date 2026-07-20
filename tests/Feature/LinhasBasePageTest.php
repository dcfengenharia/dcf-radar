<?php

namespace Tests\Feature;

use App\Enums\OrigemAtividade;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\Etapa;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LinhasBasePageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CronogramaImportacao $importacao;
    private LinhaBase $linhaBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'importado_em' => now(),
        ]);

        $this->linhaBase = LinhaBase::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Baseline 0',
            'cronograma_importacao_id' => $this->importacao->id,
            'criado_por' => $this->user->id,
        ]);
    }

    private function componente()
    {
        return Livewire::test('pages::radar.linhas-base', ['obra' => $this->obra])
            ->call('expandir', $this->linhaBase->id);
    }

    private function criarAtividade(array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'origem' => OrigemAtividade::MsProject->value,
            'status' => StatusAtividade::Planejado->value,
        ], $overrides));
    }

    public function test_arvore_ordena_pacotes_numericamente_e_nao_como_string(): void
    {
        $pai = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => '5.1',
        ]);
        $dez = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pai->id,
            'codigo' => '5.1.10',
        ]);
        $tres = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pai->id,
            'codigo' => '5.1.3',
        ]);
        $this->criarAtividade(['pacote_trabalho_id' => $dez->id]);
        $this->criarAtividade(['pacote_trabalho_id' => $tres->id]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $codigos = collect($arvore)->where('tipo', 'pacote')->pluck('pacote.codigo')->values();
        $this->assertEquals(['5.1', '5.1.3', '5.1.10'], $codigos->all());
    }

    public function test_atividade_orfa_com_codigo_e_intercalada_entre_pacotes_raiz(): void
    {
        $pacote1 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => '1',
        ]);
        $pacote3 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => '3',
        ]);
        $this->criarAtividade(['pacote_trabalho_id' => $pacote1->id, 'codigo_cronograma' => '1.1']);
        $this->criarAtividade(['pacote_trabalho_id' => $pacote3->id, 'codigo_cronograma' => '3.1']);
        $orfa = $this->criarAtividade([
            'pacote_trabalho_id' => null, 'codigo_cronograma' => '2', 'nome' => 'Orfa Meio',
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $sequenciaRaiz = collect($arvore)->where('nivel', 0)->map(function ($linha) {
            return $linha['tipo'] === 'pacote' ? $linha['pacote']->codigo : $linha['row']['atividade']->id;
        })->values();

        $this->assertEquals(
            [$pacote1->codigo, $orfa->id, $pacote3->codigo],
            $sequenciaRaiz->all()
        );
    }

    public function test_marco_no_inicio_do_cronograma_aparece_primeiro_nao_por_ultimo(): void
    {
        $pacote2 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => '2',
        ]);
        $marcoInicio = $this->criarAtividade([
            'pacote_trabalho_id' => null, 'codigo_cronograma' => '1',
            'is_marco' => true, 'nome' => 'INÍCIO',
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $primeiraLinha = collect($arvore)->first();
        $this->assertEquals('atividade', $primeiraLinha['tipo']);
        $this->assertEquals($marcoInicio->id, $primeiraLinha['row']['atividade']->id);
    }

    public function test_ordem_dentro_do_grupo_respeita_codigo_cronograma_mesmo_com_datas_iguais(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => '5.1',
        ]);
        $mesmaData = now()->addDays(10);
        $dez = $this->criarAtividade([
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.10',
            'inicio_planejado' => $mesmaData, 'nome' => 'Zebra',
        ]);
        $tres = $this->criarAtividade([
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.3',
            'inicio_planejado' => $mesmaData, 'nome' => 'Abacate',
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $idsNaOrdem = collect($arvore)->where('tipo', 'atividade')->pluck('row.atividade.id')->values();
        $this->assertEquals([$tres->id, $dez->id], $idsNaOrdem->all());
    }

    public function test_ordem_manual_continua_vencendo_codigo_cronograma(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => '5.1',
        ]);
        $codigoMaior = $this->criarAtividade([
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.10',
            'ordem_manual' => 0, 'nome' => 'Forcada pra frente',
        ]);
        $codigoMenor = $this->criarAtividade([
            'pacote_trabalho_id' => $pacote->id, 'codigo_cronograma' => '5.1.3',
            'ordem_manual' => 1, 'nome' => 'Forcada pra tras',
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $idsNaOrdem = collect($arvore)->where('tipo', 'atividade')->pluck('row.atividade.id')->values();
        $this->assertEquals([$codigoMaior->id, $codigoMenor->id], $idsNaOrdem->all());
    }

    public function test_orfa_sem_codigo_cronograma_nao_se_perde_e_cai_no_final(): void
    {
        $pacote1 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => '1',
        ]);
        $orfaSemCodigo = $this->criarAtividade([
            'pacote_trabalho_id' => null, 'codigo_cronograma' => null, 'nome' => 'Manual sem pacote',
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $ultimaLinha = collect($arvore)->last();
        $this->assertEquals('atividade', $ultimaLinha['tipo']);
        $this->assertEquals($orfaSemCodigo->id, $ultimaLinha['row']['atividade']->id);
    }

    public function test_datas_de_baseline_vem_do_snapshot_da_linha_de_base(): void
    {
        $atividade = $this->criarAtividade([
            'baseline_inicio' => now()->addDays(20),
            'baseline_termino' => now()->addDays(25),
        ]);

        AtividadeSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'baseline_inicio' => now()->addDays(1)->toDateString(),
            'baseline_termino' => now()->addDays(5)->toDateString(),
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;
        $linhaAtividade = collect($arvore)->firstWhere('id', $atividade->id);

        $this->assertTrue($linhaAtividade['row']['inicioBaseline']->isSameDay(now()->addDays(1)));
        $this->assertTrue($linhaAtividade['row']['terminoBaseline']->isSameDay(now()->addDays(5)));
        $this->assertEquals(5, $linhaAtividade['row']['duracaoBaseline']);
    }

    public function test_datas_de_baseline_caem_para_campos_ao_vivo_sem_snapshot(): void
    {
        $atividade = $this->criarAtividade([
            'baseline_inicio' => now()->addDays(10),
            'baseline_termino' => now()->addDays(12),
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;
        $linhaAtividade = collect($arvore)->firstWhere('id', $atividade->id);

        $this->assertTrue($linhaAtividade['row']['inicioBaseline']->isSameDay(now()->addDays(10)));
        $this->assertTrue($linhaAtividade['row']['terminoBaseline']->isSameDay(now()->addDays(12)));
        $this->assertEquals(3, $linhaAtividade['row']['duracaoBaseline']);
    }

    public function test_disciplina_e_id_externo_aparecem_na_lista(): void
    {
        $disciplina = Disciplina::factory()->create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $this->criarAtividade(['disciplina_id' => $disciplina->id, 'external_uid' => '999', 'nome' => 'Atividade Teste']);

        $this->componente()
            ->assertSee('Elétrica')
            ->assertSee('999')
            ->assertSee('Atividade Teste');
    }

    public function test_badge_de_caminho_critico_reflete_o_status(): void
    {
        $this->criarAtividade(['caminho_critico' => true, 'nome' => 'Atividade Critica']);

        $arvore = $this->componente()->instance()->arvoreAtividades;
        $linha = collect($arvore)->firstWhere('tipo', 'atividade');

        $this->assertTrue($linha['row']['atividade']->caminho_critico);
        $this->componente()->assertSeeHtml('bx-error-circle');
    }

    public function test_atividade_sem_caminho_critico_mostra_badge_nao(): void
    {
        $this->criarAtividade(['caminho_critico' => false, 'nome' => 'Atividade Normal']);

        $this->componente()->assertSee('Não');
    }

    public function test_editar_atividade_atualiza_nome_disciplina_e_caminho_critico(): void
    {
        $disciplina = Disciplina::factory()->create(['tenant_id' => $this->tenant->id]);
        $atividade = $this->criarAtividade(['caminho_critico' => false]);

        $this->componente()
            ->call('abrirEdicaoAtividade', $atividade->id)
            ->set('editNome', 'Nome Atualizado')
            ->set('editDisciplinaId', $disciplina->id)
            ->set('editCaminhoCritico', true)
            ->call('salvarEdicaoAtividade');

        $atividade->refresh();
        $this->assertEquals('Nome Atualizado', $atividade->nome);
        $this->assertEquals($disciplina->id, $atividade->disciplina_id);
        $this->assertTrue($atividade->caminho_critico);
    }

    public function test_falha_de_banco_ao_excluir_linha_base_mostra_toast_de_erro_sem_apagar(): void
    {
        $conexaoReal = app('db');
        \Illuminate\Support\Facades\DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('falha forçada de teste'));

        $this->componente()
            ->call('excluir', $this->linhaBase->id)
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });

        \Illuminate\Support\Facades\DB::swap($conexaoReal);
        $this->assertNotNull(LinhaBase::find($this->linhaBase->id));
    }

    public function test_usuario_sem_papel_minimo_nao_pode_editar_atividade(): void
    {
        $atividade = $this->criarAtividade();

        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::ClienteLeitura->value);
        $this->actingAs($encarregado);

        Livewire::test('pages::radar.linhas-base', ['obra' => $this->obra])
            ->call('abrirEdicaoAtividade', $atividade->id)
            ->assertForbidden();
    }

    public function test_busca_filtra_por_nome_ou_id_externo(): void
    {
        $this->criarAtividade(['nome' => 'Fundação bloco A', 'external_uid' => '10']);
        $this->criarAtividade(['nome' => 'Alvenaria bloco B', 'external_uid' => '20']);

        $arvore = $this->componente()->set('buscaAtividade', 'Fundação')->instance()->arvoreAtividades;
        $nomes = collect($arvore)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values();
        $this->assertEquals(['Fundação bloco A'], $nomes->all());

        $arvorePorId = $this->componente()->set('buscaAtividade', '20')->instance()->arvoreAtividades;
        $nomesPorId = collect($arvorePorId)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values();
        $this->assertEquals(['Alvenaria bloco B'], $nomesPorId->all());
    }

    public function test_filtro_de_disciplina_restringe_lista(): void
    {
        $eletrica = Disciplina::factory()->create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $civil = Disciplina::factory()->create(['tenant_id' => $this->tenant->id, 'nome' => 'Civil']);
        $this->criarAtividade(['disciplina_id' => $eletrica->id, 'nome' => 'Tarefa Elétrica']);
        $this->criarAtividade(['disciplina_id' => $civil->id, 'nome' => 'Tarefa Civil']);

        $arvore = $this->componente()->set('disciplinaIdFiltro', $eletrica->id)->instance()->arvoreAtividades;
        $nomes = collect($arvore)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values();
        $this->assertEquals(['Tarefa Elétrica'], $nomes->all());
    }

    public function test_filtro_de_caminho_critico_restringe_lista(): void
    {
        $this->criarAtividade(['caminho_critico' => true, 'nome' => 'Critica']);
        $this->criarAtividade(['caminho_critico' => false, 'nome' => 'Normal']);

        $arvoreSim = $this->componente()->set('caminhoCriticoFiltro', '1')->instance()->arvoreAtividades;
        $this->assertEquals(['Critica'], collect($arvoreSim)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values()->all());

        $arvoreNao = $this->componente()->set('caminhoCriticoFiltro', '0')->instance()->arvoreAtividades;
        $this->assertEquals(['Normal'], collect($arvoreNao)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values()->all());
    }

    public function test_filtro_de_restricoes_restringe_lista(): void
    {
        $comRestricao = $this->criarAtividade(['nome' => 'Com restrição']);
        $this->criarAtividade(['nome' => 'Sem restrição']);

        \App\Models\Restricao::create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $comRestricao->id,
            'descricao' => 'Impedimento teste',
            'prazo_limite' => now()->addDays(5),
            'bloqueante' => true,
            'status' => \App\Enums\StatusRestricao::Aberta->value,
            'aberta_em' => now(),
            'created_by_id' => $this->user->id,
        ]);

        $arvoreCom = $this->componente()->set('temRestricoesFiltro', '1')->instance()->arvoreAtividades;
        $this->assertEquals(['Com restrição'], collect($arvoreCom)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values()->all());

        $arvoreSem = $this->componente()->set('temRestricoesFiltro', '0')->instance()->arvoreAtividades;
        $this->assertEquals(['Sem restrição'], collect($arvoreSem)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values()->all());
    }

    public function test_filtro_de_etapa_restringe_lista(): void
    {
        $fundacao = Etapa::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Fundação']);
        $estrutura = Etapa::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Estrutura']);
        $this->criarAtividade(['etapa_id' => $fundacao->id, 'nome' => 'Tarefa Fundação']);
        $this->criarAtividade(['etapa_id' => $estrutura->id, 'nome' => 'Tarefa Estrutura']);

        $arvore = $this->componente()->set('etapaIdFiltro', $fundacao->id)->instance()->arvoreAtividades;
        $nomes = collect($arvore)->where('tipo', 'atividade')->pluck('row.atividade.nome')->values();
        $this->assertEquals(['Tarefa Fundação'], $nomes->all());
    }

    public function test_limpar_filtros_atividades_reseta_todos_os_campos(): void
    {
        $componente = $this->componente()
            ->set('buscaAtividade', 'algo')
            ->set('disciplinaIdFiltro', 'x')
            ->set('etapaIdFiltro', 'y')
            ->set('caminhoCriticoFiltro', '1')
            ->set('temRestricoesFiltro', '1');

        $this->assertTrue($componente->instance()->temFiltrosAtividadesAtivos());

        $componente->call('limparFiltrosAtividades');

        $this->assertSame('', $componente->get('buscaAtividade'));
        $this->assertNull($componente->get('disciplinaIdFiltro'));
        $this->assertNull($componente->get('etapaIdFiltro'));
        $this->assertSame('', $componente->get('caminhoCriticoFiltro'));
        $this->assertSame('', $componente->get('temRestricoesFiltro'));
        $this->assertFalse($componente->instance()->temFiltrosAtividadesAtivos());
    }

    public function test_botao_editar_some_para_usuario_sem_permissao_mas_botao_restricao_continua(): void
    {
        $this->criarAtividade(['nome' => 'Atividade Visivel']);

        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($clienteLeitura);

        Livewire::test('pages::radar.linhas-base', ['obra' => $this->obra])
            ->call('expandir', $this->linhaBase->id)
            ->assertDontSeeHtml('abrirEdicaoAtividade')
            ->assertSeeHtml('abrirNovaRestricao');
    }

    public function test_exportar_pdf_e_excel_das_atividades(): void
    {
        $this->criarAtividade(['nome' => 'Atividade Exportada']);

        $this->componente()->call('exportarAtividadesPdf')->assertFileDownloaded();
        $this->componente()->call('exportarAtividadesExcel')->assertFileDownloaded();
    }
}
