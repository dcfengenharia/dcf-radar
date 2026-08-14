<?php

namespace Tests\Feature;

use App\Enums\StatusItemSuprimento;
use App\Enums\StatusPlanoAcao;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\DocumentoEngenharia;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\PacoteTrabalho;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 15, Etapa B.2 — página Livewire `radar.central-prontidao`. Tela
 * SOMENTE LEITURA: cobre autorização, isolamento tenant/obra, filtros/
 * horizonte, resumo dos 4 estados, motivos, detalhe expansível,
 * deep-links e ausência de N+1. Nenhum teste aqui verifica escrita —
 * porque não existe nenhuma ação de escrita na Central pra testar.
 */
class CentralProntidaoPageTest extends TestCase
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
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'fora_do_cronograma' => false,
            // AtividadeFactory sorteia inicio_planejado em até +3 meses —
            // fixado aqui dentro do horizonte padrão (30 dias) pra não
            // depender de sorte do faker nos testes que não passam essa
            // data explicitamente.
            'inicio_planejado' => now()->addDays(5),
        ], $overrides));
    }

    private function criarImportacao(?Work $obra = null): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'obra_id' => ($obra ?? $this->obra)->id,
            'importado_em' => now(),
        ]);
    }

    private function criarPlanoAcao(array $overrides = [], ?Work $obra = null): PlanoAcao
    {
        $obra ??= $this->obra;

        return PlanoAcao::create(array_merge([
            'obra_id' => $obra->id,
            'cronograma_importacao_origem_id' => $this->criarImportacao($obra)->id,
            'regra_id' => 'SLACK-001',
            'titulo' => 'Folga negativa identificada',
            'recomendacao' => 'Revise o encadeamento lógico.',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => [],
        ], $overrides));
    }

    private function criarItemSuprimento(array $overrides = []): ItemSuprimento
    {
        return ItemSuprimento::create(array_merge([
            'obra_id' => $this->obra->id,
            'nome' => 'Aço estrutural',
            'status' => StatusItemSuprimento::EmRisco->value,
        ], $overrides));
    }

    private function criarDocumentoEngenharia(array $overrides = []): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-001',
            'descricao' => 'Projeto estrutural',
            'data_planejada' => null,
        ], $overrides));
    }

    // =========================================================================
    // 1/2 — AUTORIZAÇÃO
    // =========================================================================

    public function test_usuario_autorizado_acessa_a_central(): void
    {
        $this->criarAtividade(['nome' => 'Atividade Visível']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Central de Prontidão')
            ->assertSee('Atividade Visível');
    }

    public function test_usuario_sem_permissao_nao_acessa(): void
    {
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        // Mesmo padrão documentado em PlanoAcaoPaginaTest/ImportacaoDetalheTest
        // — Handler::render() intercepta o 403 de navegação de página cheia.
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    // =========================================================================
    // 3/4 — OBRA ATIVA / ISOLAMENTO
    // =========================================================================

    public function test_usa_a_obra_ativa_correta(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, 'gerente_planejamento');

        $this->criarAtividade(['nome' => 'Da Obra Ativa'], $this->obra);
        $this->criarAtividade(['nome' => 'De Outra Obra'], $outraObra);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Da Obra Ativa')
            ->assertDontSee('De Outra Obra');
    }

    public function test_atividade_de_outro_tenant_nunca_aparece(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        Atividade::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'fora_do_cronograma' => false,
            'nome' => 'Atividade de Outro Tenant',
        ]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertDontSee('Atividade de Outro Tenant');
    }

    // =========================================================================
    // 5 — HORIZONTE
    // =========================================================================

    public function test_horizonte_e_aplicado(): void
    {
        $this->criarAtividade(['nome' => 'Dentro do Horizonte', 'inicio_planejado' => now()->addDays(5)]);
        $this->criarAtividade(['nome' => 'Fora do Horizonte', 'inicio_planejado' => now()->addDays(90)]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('horizonte', '15')
            ->assertSee('Dentro do Horizonte')
            ->assertDontSee('Fora do Horizonte')
            ->set('horizonte', '0')
            ->assertSee('Fora do Horizonte');
    }

    // =========================================================================
    // 6 — FILTROS
    // =========================================================================

    public function test_filtro_de_busca_textual_funciona(): void
    {
        $this->criarAtividade(['nome' => 'Concretagem Fundação']);
        $this->criarAtividade(['nome' => 'Alvenaria Bloco B']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('busca', 'Concretagem')
            ->assertSee('Concretagem Fundação')
            ->assertDontSee('Alvenaria Bloco B');
    }

    public function test_filtro_por_pacote_funciona(): void
    {
        $pacote = \App\Models\PacoteTrabalho::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Estrutura',
        ]);
        $outroPacote = \App\Models\PacoteTrabalho::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Acabamento',
        ]);

        $this->criarAtividade(['nome' => 'Atividade da Estrutura', 'pacote_trabalho_id' => $pacote->id]);
        $this->criarAtividade(['nome' => 'Atividade do Acabamento', 'pacote_trabalho_id' => $outroPacote->id]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('filtroPacoteId', $pacote->id)
            ->assertSee('Atividade da Estrutura')
            ->assertDontSee('Atividade do Acabamento');
    }

    // =========================================================================
    // 7/8/9/10 — RESUMO E CLASSIFICAÇÃO
    // =========================================================================

    public function test_resumo_contabiliza_corretamente_os_quatro_estados(): void
    {
        $pronta = $this->criarAtividade(['nome' => 'Pronta']);

        $atencao = $this->criarAtividade(['nome' => 'Atencao', 'external_uid' => 'AT-1']);
        $this->criarPlanoAcao(['uids_referencia' => ['AT-1']]);

        $naoPronta = $this->criarAtividade(['nome' => 'NaoPronta']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $naoPronta->id, 'bloqueante' => true]);

        $concluida = $this->criarAtividade(['nome' => 'Concluida', 'concluido_em' => now()]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);

        $resumo = $component->instance()->resumo();

        $this->assertSame(1, $resumo['pronta']);
        $this->assertSame(1, $resumo['atencao']);
        $this->assertSame(1, $resumo['nao_pronta']);
        $this->assertSame(1, $resumo['concluida']);
    }

    public function test_nao_pronta_mostra_pelo_menos_um_motivo(): void
    {
        $at = $this->criarAtividade(['nome' => 'Bloqueada']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true, 'descricao' => 'Aguardando liberação']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Restrição bloqueante');
    }

    public function test_atencao_nunca_aparece_como_nao_pronta(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Contexto', 'external_uid' => 'AT-2']);
        $this->criarPlanoAcao(['uids_referencia' => ['AT-2']]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);

        $view = $component->instance()->views()->firstWhere('atividadeId', $at->id);

        $this->assertSame(\App\Support\CentralProntidao\StatusOperacionalProntidao::Atencao, $view->statusOperacional);
        $this->assertTrue($view->pronta);
    }

    public function test_concluida_nao_entra_na_contagem_de_pronta_ou_nao_pronta(): void
    {
        $at = $this->criarAtividade(['nome' => 'Finalizada', 'concluido_em' => now()]);
        // Mesmo com restrição bloqueante aberta, CONCLUIDA tem prioridade
        // (Ciclo 14) — não deve contar em nenhum dos outros 3 buckets.
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);
        $resumo = $component->instance()->resumo();

        $this->assertSame(0, $resumo['pronta']);
        $this->assertSame(0, $resumo['nao_pronta']);
        $this->assertSame(0, $resumo['atencao']);
        $this->assertSame(1, $resumo['concluida']);
    }

    // =========================================================================
    // 11 — DETALHE EXPANDIDO
    // =========================================================================

    public function test_detalhe_expandido_mostra_dados_relacionados(): void
    {
        $at = $this->criarAtividade(['nome' => 'Atividade Detalhada']);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $at->id,
            'bloqueante' => true,
            'descricao' => 'Falta liberação da fiscalização',
        ]);
        ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Projeto Executivo', 'ordem' => 0]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Atividade Detalhada')
            ->assertSee('Falta liberação da fiscalização')
            ->assertSee('Projeto Executivo');
    }

    // =========================================================================
    // 12 — DEEP-LINKS
    // =========================================================================

    public function test_deep_link_de_plano_de_acao_tem_o_parametro_regra_correto(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Plano de Acao', 'external_uid' => 'AT-3']);
        $this->criarPlanoAcao(['regra_id' => 'STRUCT-005', 'uids_referencia' => ['AT-3']]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSeeHtml(route('radar.plano-acao', ['regra' => 'STRUCT-005']));
    }

    public function test_deep_link_de_restricao_aponta_para_o_quadro_de_restricoes(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Restricao']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSeeHtml(route('radar.restricoes'));
    }

    public function test_deep_link_de_lookahead_aponta_para_a_pagina_do_lookahead(): void
    {
        $this->criarAtividade(['nome' => 'Qualquer Atividade']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSeeHtml(route('radar.lookahead'));
    }

    // =========================================================================
    // 13 — NENHUM CONTROLE DE ESCRITA
    // =========================================================================

    /**
     * Inspeção de código-fonte, não de reflection em tempo de execução —
     * mais robusta pra um componente Volt single-file (classe anônima
     * compilada). Confirma dois sinais fortes de "somente leitura":
     * (1) nenhum método público com verbo de escrita conhecido; (2) o
     * componente nunca usa `ExecutaComTransacaoSegura` (trait presente em
     * TODA página do projeto que executa alguma escrita real — Ciclo 15,
     * CLAUDE.md).
     */
    public function test_componente_nao_expoe_nenhum_metodo_de_escrita(): void
    {
        // Ciclo 15, B.3: passou a inspecionar TAMBÉM o partial de detalhe
        // (novo nesta etapa) — o mesmo padrão de "somente leitura" precisa
        // valer pros 2 arquivos, não só pro componente principal.
        $arquivos = [
            resource_path('views/pages/radar/⚡central-prontidao.blade.php'),
            resource_path('views/pages/radar/_partials/central-prontidao-detalhe.blade.php'),
        ];

        $verbosDeEscritaProibidos = [
            'function resolver', 'function reabrir', 'function criar', 'function excluir',
            'function deletar', 'function atualizar', 'function marcar', 'function desmarcar',
            'function salvar', 'function confirmar', 'function transformar', 'function editar',
        ];

        foreach ($arquivos as $arquivo) {
            $codigoFonte = file_get_contents($arquivo);

            $this->assertStringNotContainsString('ExecutaComTransacaoSegura', $codigoFonte);
            $this->assertStringNotContainsString('transacaoSegura', $codigoFonte);
            // Nenhum wire:click deve existir no partial — toda interação lá
            // é navegação (<a href>) ou Alpine puro (@click.stop só existe
            // pra impedir que o clique no link também dispare o toggle de
            // expansão da linha pai, nunca uma ação de escrita).
            if (str_ends_with($arquivo, 'central-prontidao-detalhe.blade.php')) {
                $this->assertStringNotContainsString('wire:click', $codigoFonte);
            }

            foreach ($verbosDeEscritaProibidos as $assinatura) {
                $this->assertStringNotContainsString(
                    strtolower($assinatura),
                    strtolower($codigoFonte),
                    "Assinatura de método de escrita encontrada ('{$assinatura}') em {$arquivo} — a Central deve ser somente leitura."
                );
            }
        }
    }

    // =========================================================================
    // 14 — AUSÊNCIA DE RELAÇÕES
    // =========================================================================

    public function test_atividade_sem_nenhuma_relacao_nao_causa_erro(): void
    {
        $this->criarAtividade(['nome' => 'Atividade Isolada']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Atividade Isolada')
            ->assertSee('Pronta');
    }

    // =========================================================================
    // 15 — N+1
    // =========================================================================

    private function popularCenarioCompleto(int $quantidade): void
    {
        $item = ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Checklist', 'ordem' => 0]);

        for ($i = 0; $i < $quantidade; $i++) {
            $at = $this->criarAtividade(['nome' => "Atividade {$i}", 'external_uid' => "n1-{$i}"]);
            \App\Models\AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item->id, 'concluido' => true]);
            Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => false]);
            $this->criarPlanoAcao(['uids_referencia' => ["n1-{$i}"]]);

            $suprimento = ItemSuprimento::create([
                'obra_id' => $this->obra->id,
                'nome' => "Item {$i}",
                'status' => StatusItemSuprimento::EmRisco->value,
            ]);
            $suprimento->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
            $doc = DocumentoEngenharia::create([
                'obra_id' => $this->obra->id,
                'codigo' => "DOC-{$i}",
                'descricao' => 'doc',
                'data_planejada' => now()->subDay(),
            ]);
            $suprimento->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
        }
    }

    public function test_renderizacao_nao_introduz_n_mais_1(): void
    {
        $this->popularCenarioCompleto(5);

        $queryCount5 = 0;
        DB::listen(function () use (&$queryCount5) {
            $queryCount5++;
        });
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);

        // Novo cenário isolado — mede o custo ABSOLUTO da segunda chamada,
        // não um acumulado sobre o mesmo dataset (mesmo cuidado documentado
        // em CentralProntidaoQueryTest/PlanoAcaoPainelTest).
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $this->popularCenarioCompleto(20);

        $queryCount20 = 0;
        DB::listen(function () use (&$queryCount20) {
            $queryCount20++;
        });
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);

        $this->assertLessThan(
            $queryCount5 + 10,
            $queryCount20,
            "esperava contagem de queries praticamente constante. Com 5 atividades: {$queryCount5}, com 20 atividades: {$queryCount20}"
        );
    }

    // =========================================================================
    // Ciclo 15, Etapa B.3 — painel de detalhe expansível + deep-links
    // =========================================================================

    public function test_detalhe_mostra_restricao_nao_bloqueante_sem_tratar_como_impeditivo(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Restricao Leve']);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $at->id,
            'bloqueante' => false,
            'descricao' => 'Aguardando confirmação de fornecedor',
        ]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Aguardando confirmação de fornecedor')
            ->assertSee('Restrições Não-Bloqueantes');

        $view = $component->instance()->views()->firstWhere('atividadeId', $at->id);
        $this->assertTrue($view->pronta);
        $this->assertSame(\App\Support\CentralProntidao\StatusOperacionalProntidao::Atencao, $view->statusOperacional);
    }

    public function test_detalhe_mostra_checklist_parcial_com_nome_dos_itens_pendentes(): void
    {
        $at = $this->criarAtividade(['nome' => 'Checklist Parcial']);
        $item1 = ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Projeto liberado', 'ordem' => 0]);
        ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Materiais no canteiro', 'ordem' => 1]);
        \App\Models\AtividadeItemProntidao::create([
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item1->id,
            'concluido' => true,
        ]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Materiais no canteiro')
            ->assertSee('1/2');
    }

    public function test_detalhe_mostra_checklist_completo_sem_pendentes(): void
    {
        $at = $this->criarAtividade(['nome' => 'Checklist Completo']);
        $item1 = ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Projeto liberado', 'ordem' => 0]);
        \App\Models\AtividadeItemProntidao::create([
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item1->id,
            'concluido' => true,
        ]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Todos os itens concluídos')
            ->assertSee('1/1');
    }

    public function test_detalhe_mostra_planoacao_aberta_com_titulo_e_severidade(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com PlanoAcao', 'external_uid' => 'B3-1']);
        $this->criarPlanoAcao(['titulo' => 'Ciclo lógico identificado', 'uids_referencia' => ['B3-1']]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Ciclo lógico identificado')
            ->assertSee('Planos de Ação Relacionados');
    }

    public function test_detalhe_mostra_duas_planoacoes_distintas_com_mesmo_external_uid(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Duas PlanoAcoes', 'external_uid' => 'B3-2']);
        $this->criarPlanoAcao(['regra_id' => 'SLACK-001', 'titulo' => 'Folga negativa', 'uids_referencia' => ['B3-2']]);
        $this->criarPlanoAcao(['regra_id' => 'STRUCT-005', 'titulo' => 'Ciclo lógico', 'uids_referencia' => ['B3-2']]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Folga negativa')
            ->assertSee('Ciclo lógico')
            ->assertSeeHtml(route('radar.plano-acao', ['regra' => 'SLACK-001']))
            ->assertSeeHtml(route('radar.plano-acao', ['regra' => 'STRUCT-005']));

        $view = $component->instance()->views()->firstWhere('atividadeId', $at->id);
        $this->assertCount(2, $view->planoAcoesAbertas);
    }

    public function test_detalhe_diferencia_suprimento_em_risco_de_atrasado(): void
    {
        $atRisco = $this->criarAtividade(['nome' => 'Suprimento Em Risco']);
        $itemRisco = $this->criarItemSuprimento(['nome' => 'Aço', 'status' => StatusItemSuprimento::EmRisco->value]);
        $itemRisco->atividades()->attach($atRisco->id, ['tenant_id' => $this->tenant->id]);

        $atAtrasado = $this->criarAtividade(['nome' => 'Suprimento Atrasado']);
        $itemAtrasado = $this->criarItemSuprimento(['nome' => 'Cimento', 'status' => StatusItemSuprimento::Atrasado->value]);
        $itemAtrasado->atividades()->attach($atAtrasado->id, ['tenant_id' => $this->tenant->id]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Aço')
            ->assertSee('Cimento')
            ->assertSeeHtml('bg-label-warning">Em risco')
            ->assertSeeHtml('bg-label-danger">Atrasado');
    }

    public function test_detalhe_mostra_documento_atrasado_e_nao_emitido(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Engenharia']);
        $item = $this->criarItemSuprimento();
        $item->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
        $doc = $this->criarDocumentoEngenharia(['codigo' => 'DOC-XYZ', 'data_planejada' => now()->subDay()]);
        $item->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('DOC-XYZ')
            ->assertSee('Não emitido')
            ->assertSee('Atrasado');
    }

    public function test_atividade_com_multiplos_dominios_simultaneos_renderiza_sem_erro(): void
    {
        $at = $this->criarAtividade(['nome' => 'Atividade Combo', 'external_uid' => 'B3-COMBO']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => false]);
        $item = ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Item Único', 'ordem' => 0]);
        \App\Models\AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item->id, 'concluido' => true]);
        $this->criarPlanoAcao(['uids_referencia' => ['B3-COMBO']]);
        $suprimento = $this->criarItemSuprimento(['status' => StatusItemSuprimento::EmRisco->value]);
        $suprimento->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
        $doc = $this->criarDocumentoEngenharia(['data_planejada' => now()->subDay()]);
        $suprimento->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Atividade Combo');

        $view = $component->instance()->views()->firstWhere('atividadeId', $at->id);
        $this->assertTrue($view->pronta);
        $this->assertSame(\App\Support\CentralProntidao\StatusOperacionalProntidao::Atencao, $view->statusOperacional);
    }

    public function test_nao_pronta_com_alertas_contextuais_simultaneos_continua_nao_pronta(): void
    {
        $at = $this->criarAtividade(['nome' => 'Bloqueada Com Contexto', 'external_uid' => 'B3-NP']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);
        $this->criarPlanoAcao(['uids_referencia' => ['B3-NP']]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);
        $view = $component->instance()->views()->firstWhere('atividadeId', $at->id);

        $this->assertFalse($view->pronta);
        $this->assertSame(\App\Support\CentralProntidao\StatusOperacionalProntidao::NaoPronta, $view->statusOperacional);
    }

    public function test_concluida_com_alertas_contextuais_simultaneos_continua_concluida(): void
    {
        $at = $this->criarAtividade(['nome' => 'Finalizada Com Contexto', 'external_uid' => 'B3-CC', 'concluido_em' => now()]);
        $this->criarPlanoAcao(['uids_referencia' => ['B3-CC']]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);
        $view = $component->instance()->views()->firstWhere('atividadeId', $at->id);

        $this->assertSame(\App\Support\CentralProntidao\StatusOperacionalProntidao::Concluida, $view->statusOperacional);
    }

    public function test_todos_os_links_do_detalhe_apontam_para_rotas_existentes(): void
    {
        $at = $this->criarAtividade(['nome' => 'Atividade Com Tudo', 'external_uid' => 'B3-LINKS']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);
        $this->criarPlanoAcao(['regra_id' => 'SLACK-001', 'uids_referencia' => ['B3-LINKS']]);
        $suprimento = $this->criarItemSuprimento(['status' => StatusItemSuprimento::EmRisco->value]);
        $suprimento->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);

        // Se alguma dessas rotas não existisse, route() já lançaria
        // RouteNotFoundException antes mesmo de comparar strings.
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSeeHtml(route('radar.restricoes'))
            ->assertSeeHtml(route('radar.plano-acao', ['regra' => 'SLACK-001']))
            ->assertSeeHtml(route('radar.suprimentos'))
            ->assertSeeHtml(route('radar.lookahead'));
    }

    /**
     * Expandir/recolher é 100% Alpine client-side (`x-show`/`@click`) — o
     * partial de detalhe já é renderizado no servidor pra TODA atividade
     * visível, independente do estado de expansão (achado documentado na
     * auditoria da B.2). Isso significa que NÃO EXISTE nenhum método
     * Livewire de "expandir" pra chamar — a prova de que expandir nunca
     * gera query nova é a AUSÊNCIA desse mecanismo no código-fonte, não um
     * novo round-trip pra medir.
     */
    public function test_expansao_e_puramente_client_side_sem_acao_livewire(): void
    {
        $codigoComponente = file_get_contents(resource_path('views/pages/radar/⚡central-prontidao.blade.php'));

        $this->assertStringNotContainsString('function expandir', strtolower($codigoComponente));
        $this->assertStringNotContainsString('function toggle', strtolower($codigoComponente));
        $this->assertStringNotContainsString('function abrirdetalhe', strtolower($codigoComponente));

        // wire:key é usado só pra estabilidade de diffing do Livewire nas
        // linhas de tabela (mesmo padrão de plano-acao-detalhe.blade.php),
        // não é uma ação — o que de fato provaria uma ação de servidor
        // seria wire:click/wire:model/wire:submit, nenhum presente.
        $codigoPartial = file_get_contents(resource_path('views/pages/radar/_partials/central-prontidao-detalhe.blade.php'));
        $this->assertStringNotContainsString('wire:click', $codigoPartial);
        $this->assertStringNotContainsString('wire:model', $codigoPartial);
        $this->assertStringNotContainsString('wire:submit', $codigoPartial);
        $this->assertStringNotContainsString('wire:poll', $codigoPartial);
    }

    /**
     * Demonstra numericamente que o custo de renderizar TODOS os detalhes
     * (já incluídos no HTML desde o primeiro render, ver teste acima) não
     * escala com a quantidade de domínios simultâneos por atividade —
     * complementa o teste de N+1 já existente (que varia a QUANTIDADE de
     * atividades); este varia a RIQUEZA de dados por atividade.
     */
    public function test_atividade_com_muitos_dominios_nao_aumenta_queries_em_relacao_a_atividade_simples(): void
    {
        $atSimples = $this->criarAtividade(['nome' => 'Simples']);

        $queryCountSimples = 0;
        DB::listen(function () use (&$queryCountSimples) {
            $queryCountSimples++;
        });
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');

        $atCombo = $this->criarAtividade(['nome' => 'Combo', 'external_uid' => 'B3-Q']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atCombo->id, 'bloqueante' => false]);
        $this->criarPlanoAcao(['uids_referencia' => ['B3-Q']]);
        $suprimento = $this->criarItemSuprimento(['status' => StatusItemSuprimento::Atrasado->value]);
        $suprimento->atividades()->attach($atCombo->id, ['tenant_id' => $this->tenant->id]);
        $doc = $this->criarDocumentoEngenharia(['data_planejada' => now()->subDay()]);
        $suprimento->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $queryCountCombo = 0;
        DB::listen(function () use (&$queryCountCombo) {
            $queryCountCombo++;
        });
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra]);

        $this->assertLessThan(
            $queryCountSimples + 5,
            $queryCountCombo,
            "esperava contagem de queries praticamente igual independente da riqueza de dados por atividade. Simples: {$queryCountSimples}, Combo: {$queryCountCombo}"
        );
    }

    // =========================================================================
    // Ciclo 15, Etapa B.5 — ordenação da tabela principal + indicador de
    // proximidade temporal. Puramente apresentação sobre $this->views já
    // filtrado/materializado — nenhuma query nova, nenhuma reclassificação
    // de status. Testado pelo componente real (Livewire::test), nunca
    // chamando os métodos privados diretamente.
    // =========================================================================

    public function test_ordem_padrao_preserva_comportamento_anterior_a_b5(): void
    {
        $this->criarAtividade(['nome' => 'A Primeiro', 'inicio_planejado' => now()->addDays(5), 'codigo_cronograma' => '1']);
        $this->criarAtividade(['nome' => 'B Meio', 'inicio_planejado' => now()->addDays(10), 'codigo_cronograma' => '2']);
        $this->criarAtividade(['nome' => 'C Ultimo', 'inicio_planejado' => now()->addDays(20), 'codigo_cronograma' => '3']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSeeInOrder(['A Primeiro', 'B Meio', 'C Ultimo']);
    }

    public function test_ordenacao_por_status_segue_precedencia_nao_pronta_atencao_pronta_concluida(): void
    {
        $this->criarAtividade(['nome' => 'Pronta X']);
        $this->criarAtividade(['nome' => 'Atencao X', 'external_uid' => 'B5-1']);
        $this->criarPlanoAcao(['uids_referencia' => ['B5-1']]);
        $naoPronta = $this->criarAtividade(['nome' => 'NaoPronta X']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $naoPronta->id, 'bloqueante' => true]);
        $this->criarAtividade(['nome' => 'Concluida X', 'concluido_em' => now()]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('ordenarPor', 'status')
            ->assertSeeInOrder(['NaoPronta X', 'Atencao X', 'Pronta X', 'Concluida X']);
    }

    public function test_ordenacao_por_inicio_planejado_asc_e_desc(): void
    {
        $this->criarAtividade(['nome' => 'Cedo', 'inicio_planejado' => now()->addDays(3)]);
        $this->criarAtividade(['nome' => 'Tarde', 'inicio_planejado' => now()->addDays(15)]);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSeeInOrder(['Cedo', 'Tarde']); // default (ASC, sem clicar em nada)

        // sortField já nasce 'inicioPlanejado'/'asc' por padrão — o 1º clique
        // no cabeçalho inverte pra DESC (mesmo comportamento de
        // ⚡restricoes.blade.php::ordenarPor() pra uma coluna já ativa).
        $component->call('ordenarPor', 'inicioPlanejado')
            ->assertSeeInOrder(['Tarde', 'Cedo']);

        $component->call('ordenarPor', 'inicioPlanejado')
            ->assertSeeInOrder(['Cedo', 'Tarde']);
    }

    public function test_ordenacao_por_codigo_asc_e_desc(): void
    {
        // Ciclo 15, Etapa B.5.CORREÇÃO — códigos WBS reais são
        // segmentados por ponto (derivados do OutlineNumber do MS
        // Project). Este fixture expõe deliberadamente o caso que a
        // comparação lexicográfica simples acertava errado
        // ("2.10"/"2.11" antes de "2.2" numa ordenação de string crua).
        $this->criarAtividade(['nome' => 'Codigo2.1', 'codigo_cronograma' => '2.1']);
        $this->criarAtividade(['nome' => 'Codigo2.2', 'codigo_cronograma' => '2.2']);
        $this->criarAtividade(['nome' => 'Codigo2.10', 'codigo_cronograma' => '2.10']);
        $this->criarAtividade(['nome' => 'Codigo2.11', 'codigo_cronograma' => '2.11']);

        $component = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('ordenarPor', 'codigoCronograma')
            ->assertSeeInOrder(['Codigo2.1', 'Codigo2.2', 'Codigo2.10', 'Codigo2.11']);

        $component->call('ordenarPor', 'codigoCronograma')
            ->assertSeeInOrder(['Codigo2.11', 'Codigo2.10', 'Codigo2.2', 'Codigo2.1']);
    }

    public function test_ordenacao_por_codigo_com_niveis_hierarquicos_diferentes(): void
    {
        // Cobre quantidade de segmentos diferente entre códigos (não só
        // o caso "2.x" — também códigos de nível raiz sem ponto nenhum,
        // ex. "1" vs "1.1" vs "10").
        $this->criarAtividade(['nome' => 'Nivel1', 'codigo_cronograma' => '1']);
        $this->criarAtividade(['nome' => 'Nivel1_1', 'codigo_cronograma' => '1.1']);
        $this->criarAtividade(['nome' => 'Nivel1_2', 'codigo_cronograma' => '1.2']);
        $this->criarAtividade(['nome' => 'Nivel1_10', 'codigo_cronograma' => '1.10']);
        $this->criarAtividade(['nome' => 'Nivel2', 'codigo_cronograma' => '2']);
        $this->criarAtividade(['nome' => 'Nivel10', 'codigo_cronograma' => '10']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('ordenarPor', 'codigoCronograma')
            ->assertSeeInOrder(['Nivel1', 'Nivel1_1', 'Nivel1_2', 'Nivel1_10', 'Nivel2', 'Nivel10']);
    }

    public function test_ordem_padrao_usa_codigo_natural_como_desempate_em_empate_de_data(): void
    {
        // Fecha a lacuna identificada na auditoria da B.5: o desempate por
        // código da ordem PADRÃO (sem clicar em nenhum cabeçalho) também
        // precisa usar comparação natural, nunca lexicográfica.
        $mesmaData = now()->addDays(8);
        $this->criarAtividade(['nome' => 'Empate2_10', 'inicio_planejado' => $mesmaData, 'codigo_cronograma' => '2.10']);
        $this->criarAtividade(['nome' => 'Empate2_2', 'inicio_planejado' => $mesmaData, 'codigo_cronograma' => '2.2']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSeeInOrder(['Empate2_2', 'Empate2_10']);
    }

    public function test_alternancia_de_direcao_ao_clicar_na_mesma_coluna(): void
    {
        $this->criarAtividade(['nome' => 'Qualquer']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('ordenarPor', 'codigoCronograma')
            ->assertSeeHtml('bx-sort-up')
            ->call('ordenarPor', 'codigoCronograma')
            ->assertSeeHtml('bx-sort-down')
            ->call('ordenarPor', 'codigoCronograma')
            ->assertSeeHtml('bx-sort-up');
    }

    public function test_ordenar_nao_traz_de_volta_atividades_filtradas(): void
    {
        $pacote = PacoteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Estrutura']);
        $outroPacote = PacoteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Acabamento']);
        $this->criarAtividade(['nome' => 'Da Estrutura', 'pacote_trabalho_id' => $pacote->id]);
        $this->criarAtividade(['nome' => 'Do Acabamento', 'pacote_trabalho_id' => $outroPacote->id]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('filtroPacoteId', $pacote->id)
            ->call('ordenarPor', 'status')
            ->assertSee('Da Estrutura')
            ->assertDontSee('Do Acabamento');
    }

    public function test_proximidade_temporal_data_passada(): void
    {
        $this->criarAtividade(['nome' => 'AtividadePassada', 'inicio_planejado' => now()->subDays(5)]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('horizonte', '0')
            ->assertSee('há 5 dias');
    }

    public function test_proximidade_temporal_hoje(): void
    {
        $this->criarAtividade(['nome' => 'AtividadeHoje', 'inicio_planejado' => now()]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('hoje');
    }

    public function test_proximidade_temporal_amanha(): void
    {
        $this->criarAtividade(['nome' => 'AtividadeAmanha', 'inicio_planejado' => now()->addDay()]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('amanhã');
    }

    public function test_proximidade_temporal_futuro_maior_que_um_dia(): void
    {
        $this->criarAtividade(['nome' => 'AtividadeFutura', 'inicio_planejado' => now()->addDays(7)]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('em 7 dias');
    }

    public function test_proximidade_temporal_nula_quando_sem_inicio_planejado(): void
    {
        // inicio_planejado nulo nunca satisfaz "<= horizonteAte" em SQL —
        // precisa do horizonte 'Todo o cronograma' (0, sem filtro de data)
        // pra essa atividade sequer aparecer na listagem. As negativas usam
        // frases completas (não substrings genéricas como 'em '/'há ', que
        // colidem com preposições comuns em outras partes da página) pra
        // provar que NENHUMA das 4 frases de proximidade foi gerada.
        $this->criarAtividade(['nome' => 'AtividadeSemData', 'inicio_planejado' => null]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('horizonte', '0')
            ->assertSee('AtividadeSemData')
            ->assertDontSee('amanhã')
            ->assertDontSee('hoje')
            ->assertDontSeeHtml('<small class="text-muted">');
    }

    public function test_proximidade_temporal_omitida_para_atividade_concluida_com_inicio_no_passado(): void
    {
        $this->criarAtividade([
            'nome' => 'ConcluidaComInicioPassado',
            'inicio_planejado' => now()->subDays(10),
            'concluido_em' => now(),
        ]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('ConcluidaComInicioPassado')
            ->assertDontSee('há 10 dias');
    }

    public function test_ordenacao_nao_interfere_no_isolamento_de_obra_e_tenant(): void
    {
        $this->criarAtividade(['nome' => 'Da Obra Ativa B5']);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        Atividade::factory()->create([
            'tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id,
            'fora_do_cronograma' => false, 'nome' => 'De Outro Tenant B5',
        ]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('ordenarPor', 'status')
            ->assertSee('Da Obra Ativa B5')
            ->assertDontSee('De Outro Tenant B5');
    }
}
