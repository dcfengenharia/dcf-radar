<?php

namespace Tests\Feature;

use App\Enums\OrigemAtividade;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\Client;
use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Perfil;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ObraDetalheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra]);
    }

    public function test_pagina_carrega_para_usuario_com_acesso(): void
    {
        $this->get(route('gestao.obra.show', $this->obra))
            ->assertOk()
            ->assertSeeLivewire('pages::gestao.obra-detalhe');
    }

    public function test_usuario_sem_acesso_a_obra_recebe_forbidden(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup.
        $this->get(route('gestao.obra.show', $this->obra))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_kpis_da_visao_geral_batem_com_os_dados(): void
    {
        $pronta = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ]);
        $comRestricao = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ]);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $comRestricao->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => true,
        ]);

        $componente = $this->componente();

        $this->assertEquals(2, $componente->instance()->totalAtividades);
        $this->assertEquals(1, $componente->instance()->atividadesProntas);
        $this->assertEquals(1, $componente->instance()->restricoesAbertas);
        $this->assertEquals(1, $componente->instance()->restricoesBloqueantes);
    }

    public function test_editar_dados_da_obra_atualiza_o_registro(): void
    {
        $novoCliente = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->componente()
            ->call('abrirEdicaoDados')
            ->set('nomeEdit', 'Obra Renomeada')
            ->set('clienteIdEdit', $novoCliente->id)
            ->set('localizacaoEdit', 'Recife, PE')
            ->set('statusEdit', 'em_andamento')
            ->set('diaSemanaReportEdit', '3')
            ->call('salvarDadosObra');

        $this->obra->refresh();
        $this->assertEquals('Obra Renomeada', $this->obra->name);
        $this->assertEquals($novoCliente->id, $this->obra->client_id);
        $this->assertEquals('Recife, PE', $this->obra->location);
        $this->assertEquals('em_andamento', $this->obra->status);
        $this->assertSame(3, $this->obra->dia_semana_report);
    }

    public function test_desligar_relatorio_automatico_grava_null(): void
    {
        $this->obra->update(['dia_semana_report' => 2]);

        $this->componente()
            ->call('abrirEdicaoDados')
            ->assertSet('diaSemanaReportEdit', '2')
            ->set('diaSemanaReportEdit', '')
            ->call('salvarDadosObra');

        $this->assertNull($this->obra->refresh()->dia_semana_report);
    }

    public function test_usuario_sem_permissao_nao_pode_editar_dados_da_obra(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('abrirEdicaoDados')
            ->assertForbidden();
    }

    public function test_adicionar_alterar_e_remover_membro_da_equipe(): void
    {
        $novoMembro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        $componente = $this->componente()
            ->set('perfilNovoMembroId', $engenheiro->id)
            ->call('adicionarMembro', $novoMembro->id);

        $this->assertEquals(
            $engenheiro->id,
            $this->obra->users()->where('user_id', $novoMembro->id)->first()->pivot->perfil_id
        );

        $componente->call('alterarPerfil', $novoMembro->id, $encarregado->id);
        $this->assertEquals(
            $encarregado->id,
            $this->obra->users()->where('user_id', $novoMembro->id)->first()->pivot->perfil_id
        );

        $componente->call('removerMembro', $novoMembro->id);
        $this->assertFalse($this->obra->users()->where('user_id', $novoMembro->id)->exists());
    }

    public function test_busca_de_usuarios_para_adicionar_filtra_por_nome(): void
    {
        $achado = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ricardo', 'last_name' => 'Alves']);
        $naoAchado = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Marcos', 'last_name' => 'Souza']);

        $nomes = $this->componente()
            ->set('buscaUsuario', 'Ricardo')
            ->instance()->usuariosParaAdicionar
            ->pluck('first_name');

        $this->assertTrue($nomes->contains('Ricardo'));
        $this->assertFalse($nomes->contains('Marcos'));
    }

    public function test_usuario_sem_permissao_nao_pode_gerenciar_equipe(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('adicionarMembro', $outroUsuario->id)
            ->assertForbidden();
    }

    public function test_resumo_do_cronograma_conta_pacotes_e_atividades(): void
    {
        PacoteTrabalho::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now(),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => null,
        ]);

        $resumo = $this->componente()->instance()->resumoCronograma;

        $this->assertEquals(2, $resumo['totalPacotes']);
        $this->assertEquals(2, $resumo['totalAtividades']);
        $this->assertEquals(1, $resumo['comBaseline']);
    }

    public function test_historico_de_importacoes_lista_as_importacoes_da_obra(): void
    {
        CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'user_id' => $this->user->id,
            'arquivo' => 'cronograma-v1.xml',
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'criadas' => 10,
            'atualizadas' => 0,
            'removidas' => 0,
            'importado_em' => now()->subDays(2),
        ]);
        CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'user_id' => $this->user->id,
            'arquivo' => 'cronograma-v2.xml',
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'criadas' => 0,
            'atualizadas' => 8,
            'removidas' => 2,
            'importado_em' => now(),
        ]);

        $importacoes = $this->componente()->instance()->importacoes;

        $this->assertEquals(2, $importacoes->count());
        $this->assertEquals('cronograma-v2.xml', $importacoes->first()->arquivo);
    }

    public function test_dados_gantt_inclui_apenas_atividades_com_linha_de_base_completa(): void
    {
        $comBaseline = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'nome' => 'Atividade Com Baseline XYZ',
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => null,
            'baseline_termino' => null,
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $linha = collect($dados['data'])->firstWhere('id', "atividade_{$comBaseline->id}");

        $this->assertCount(1, $dados['data']);
        $this->assertEquals('Atividade Com Baseline XYZ', $linha['text']);
        $this->assertEquals('task', $linha['type']);
        $this->assertEquals($comBaseline->baseline_inicio->format('Y-m-d'), $linha['start_date']);
        $this->assertEquals(6, $linha['duration']);
    }

    public function test_dados_gantt_agrupa_atividade_sob_o_pacote_como_linha_de_projeto(): void
    {
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fundação',
            'codigo' => '1',
        ]);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'pacote_trabalho_id' => $pacote->id,
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $linhaPacote = collect($dados['data'])->firstWhere('id', "pacote_{$pacote->id}");
        $linhaAtividade = collect($dados['data'])->firstWhere('id', "atividade_{$atividade->id}");

        $this->assertNotNull($linhaPacote);
        $this->assertEquals('project', $linhaPacote['type']);
        $this->assertTrue($linhaPacote['open']);
        $this->assertEquals('1 · Fundação', $linhaPacote['text']);
        $this->assertEquals($atividade->baseline_inicio->format('Y-m-d'), $linhaPacote['start_date']);
        $this->assertEquals("pacote_{$pacote->id}", $linhaAtividade['parent']);
    }

    public function test_dados_gantt_pacote_sem_nenhuma_atividade_com_baseline_nao_aparece(): void
    {
        PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Pacote Vazio',
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $temPacoteVazio = collect($dados['data'])->contains(fn ($linha) => $linha['text'] === 'Pacote Vazio');

        $this->assertFalse($temPacoteVazio);
    }

    public function test_dados_gantt_colore_caminho_critico_diferente(): void
    {
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
            'caminho_critico' => true,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
            'caminho_critico' => false,
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $cores = collect($dados['data'])->pluck('color')->filter()->unique()->values();

        $this->assertCount(2, $cores);
        $this->assertTrue($cores->contains('#ff4d49'));
        $this->assertTrue($cores->contains('#696cff'));
    }

    public function test_dados_gantt_atividade_arquivada_nao_aparece(): void
    {
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => true,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);

        $this->assertCount(0, $this->componente()->instance()->dadosGantt['data']);
    }

    public function test_dados_gantt_sem_nenhuma_atividade_retorna_estrutura_vazia(): void
    {
        $dados = $this->componente()->instance()->dadosGantt;

        $this->assertEquals(['data' => [], 'links' => [], 'temTendencia' => false], $dados);
    }

    public function test_selecionar_aba_cronograma_dispara_evento_com_dados_do_gantt(): void
    {
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'nome' => 'Atividade Gantt Evento',
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);

        $this->componente()
            ->call('setAba', 'cronograma')
            ->assertDispatched('cronograma-tab-ativada', function (string $name, array $params) {
                return $params['ganttData']['data'][0]['text'] === 'Atividade Gantt Evento';
            });
    }

    public function test_dados_gantt_usa_linha_de_base_ao_vivo_sem_selecao(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
        ]);

        $linha = collect($this->componente()->instance()->dadosGantt['data'])->firstWhere('id', "atividade_{$atividade->id}");

        $this->assertEquals($atividade->baseline_inicio->format('Y-m-d'), $linha['start_date']);
    }

    public function test_dados_gantt_usa_datas_da_linha_de_base_selecionada(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now()->addDays(50), // ao vivo — não deve ser usado
            'baseline_termino' => now()->addDays(55),
        ]);

        $importacaoAntiga = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(10),
        ]);
        $linhaBase = LinhaBase::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'LB Original',
            'cronograma_importacao_id' => $importacaoAntiga->id,
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacaoAntiga->id,
            'atividade_id' => $atividade->id,
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
        ]);

        $dados = $this->componente()->set('linhaBaseId', $linhaBase->id)->instance()->dadosGantt;
        $linha = collect($dados['data'])->firstWhere('id', "atividade_{$atividade->id}");

        $this->assertEquals(now()->addDays(5)->format('Y-m-d'), $linha['start_date']);
        $this->assertEquals(now()->addDays(10)->format('Y-m-d'), $linha['termino_lb']);
    }

    public function test_dados_gantt_mostra_tendencia_da_importacao_mais_recente_por_padrao(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->subDay(),
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => now()->addDays(20),
            'data_termino' => now()->addDays(25),
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $linha = collect($dados['data'])->firstWhere('id', "atividade_{$atividade->id}");

        $this->assertTrue($dados['temTendencia']);
        $this->assertEquals(now()->addDays(20)->format('Y-m-d'), $linha['inicio_tend']);
        $this->assertEquals(now()->addDays(25)->format('Y-m-d'), $linha['termino_tend']);
    }

    public function test_dados_gantt_tendencia_nula_quando_obra_sem_importacao_de_avanco(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $linha = collect($dados['data'])->firstWhere('id', "atividade_{$atividade->id}");

        $this->assertFalse($dados['temTendencia']);
        $this->assertNull($linha['inicio_tend']);
        $this->assertNull($linha['termino_tend']);
    }

    public function test_trocar_linha_de_base_com_aba_cronograma_aberta_redispara_evento_com_dados_novos(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now()->addDays(50), // ao vivo — deve deixar de valer após selecionar a LB
            'baseline_termino' => now()->addDays(55),
        ]);
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        $linhaBase = LinhaBase::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'LB X',
            'cronograma_importacao_id' => $importacao->id,
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
        ]);

        $this->componente()
            ->call('setAba', 'cronograma')
            ->set('linhaBaseId', $linhaBase->id)
            ->assertDispatched('cronograma-tab-ativada', function (string $name, array $params) use ($atividade) {
                $linha = collect($params['ganttData']['data'])->firstWhere('id', "atividade_{$atividade->id}");
                return $linha['start_date'] === now()->addDays(5)->format('Y-m-d');
            });
    }

    public function test_dados_gantt_ordena_atividades_pelo_codigo_do_cronograma_nao_pela_data(): void
    {
        // Datas de baseline propositalmente INVERTIDAS em relação ao código
        // do cronograma — se o Gantt caísse de volta a ordenar por
        // baseline_inicio (bug reportado pelo usuário: ordem diferente do
        // MS Project importado), a atividade 2 apareceria antes da 1.
        $atividade1 = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'nome' => 'Primeira no cronograma',
            'codigo_cronograma' => '1',
            'baseline_inicio' => now()->addDays(20),
            'baseline_termino' => now()->addDays(25),
        ]);
        $atividade2 = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'nome' => 'Segunda no cronograma',
            'codigo_cronograma' => '2',
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDays(5),
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $ids = collect($dados['data'])->pluck('id')->values()->all();

        $this->assertEquals(
            ["atividade_{$atividade1->id}", "atividade_{$atividade2->id}"],
            $ids
        );
    }

    public function test_dados_gantt_ordem_manual_tem_precedencia_sobre_codigo_do_cronograma(): void
    {
        // ordem_manual só é respeitada DENTRO de um mesmo pacote (mesmo
        // comportamento de `⚡linhas-base.blade.php::arvoreAtividades()`)
        // — atividades órfãs de nível raiz são intercaladas só por
        // codigo_cronograma, nunca por ordem_manual.
        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        $atividade1 = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'pacote_trabalho_id' => $pacote->id,
            'codigo_cronograma' => '1',
            'ordem_manual' => 2,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);
        $atividade2 = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'pacote_trabalho_id' => $pacote->id,
            'codigo_cronograma' => '2',
            'ordem_manual' => 1,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $idsAtividade = collect($dados['data'])->pluck('id')->filter(fn ($id) => str_starts_with($id, 'atividade_'))->values()->all();

        $this->assertEquals(
            ["atividade_{$atividade2->id}", "atividade_{$atividade1->id}"],
            $idsAtividade
        );
    }

    public function test_dados_gantt_ordena_pacotes_pelo_codigo_natural_nao_string(): void
    {
        $pacote10 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Pacote 10',
            'codigo' => '10',
        ]);
        $pacote2 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Pacote 2',
            'codigo' => '2',
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'pacote_trabalho_id' => $pacote10->id,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'pacote_trabalho_id' => $pacote2->id,
            'baseline_inicio' => now(),
            'baseline_termino' => now()->addDay(),
        ]);

        $dados = $this->componente()->instance()->dadosGantt;
        $idsPacote = collect($dados['data'])->pluck('id')->filter(fn ($id) => str_starts_with($id, 'pacote_'))->values()->all();

        // Ordenação NATURAL (numérica) do código — "2" antes de "10" — nunca
        // string ("10" viria antes de "2" numa comparação de string crua).
        $this->assertEquals(["pacote_{$pacote2->id}", "pacote_{$pacote10->id}"], $idsPacote);
    }
}
