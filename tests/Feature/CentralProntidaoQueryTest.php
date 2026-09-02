<?php

namespace Tests\Feature;

use App\Enums\StatusItemSuprimento;
use App\Enums\StatusPlanoAcao;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\CronogramaImportacao;
use App\Models\DocumentoEngenharia;
use App\Models\FrenteTrabalho;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use App\Support\CentralProntidao\StatusOperacionalProntidao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 15, Etapa B.1 — Query/Serviço consolidado da Central de
 * Prontidão. Cobre a matriz de testes aprovada no prompt: paridade de
 * prontidão com Atividade::estaPronta(), classificação (Pronta/Não
 * pronta/Atenção/Concluída), origem de Restrição por domínio, checklist,
 * PlanoAcao (associação por external_uid + isolamento entre obras com o
 * MESMO uid), Suprimento/Engenharia como contexto (nunca alteram
 * `pronta`), atividade fora do cronograma, atividade sem nenhum dado
 * relacionado, e ausência de N+1.
 */
class CentralProntidaoQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CentralProntidaoQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->query = new CentralProntidaoQuery();
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
        ], $overrides));
    }

    private function criarItemProntidao(?Work $obra = null, string $nome = 'Projeto executivo'): ItemProntidao
    {
        return ItemProntidao::create([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => $nome,
            'ordem' => 0,
        ]);
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

    private function criarItemSuprimento(array $overrides = [], ?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Aço estrutural',
            'status' => StatusItemSuprimento::EmAndamento->value,
        ], $overrides));
    }

    private function criarDocumentoEngenharia(array $overrides = [], ?Work $obra = null): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'codigo' => 'DOC-001',
            'descricao' => 'Projeto estrutural',
            'data_planejada' => null,
        ], $overrides));
    }

    private function viewDe(Atividade $atividade, ?Work $obra = null)
    {
        return $this->query->paraObra($obra ?? $this->obra)
            ->first(fn ($v) => $v->atividadeId === $atividade->id);
    }

    // =========================================================================
    // 1) PARIDADE DE PRONTIDÃO
    // =========================================================================

    public function test_paridade_atividade_sem_restricao_e_sem_checklist_esta_pronta(): void
    {
        $at = $this->criarAtividade();

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertTrue($view->pronta);
    }

    public function test_paridade_restricao_bloqueante_aberta_bate_com_estapronta(): void
    {
        $at = $this->criarAtividade();
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertFalse($view->pronta);
        $this->assertCount(1, $view->restricoesBloqueantes);
    }

    public function test_paridade_restricao_nao_bloqueante_nao_afeta_prontidao(): void
    {
        $at = $this->criarAtividade();
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => false]);

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertTrue($view->pronta);
        $this->assertCount(1, $view->restricoesNaoBloqueantes);
    }

    public function test_paridade_checklist_incompleto_bate_com_estapronta(): void
    {
        $at = $this->criarAtividade();
        $this->criarItemProntidao();

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertFalse($view->pronta);
    }

    public function test_paridade_pronta_com_planoacao_aberta(): void
    {
        $at = $this->criarAtividade(['external_uid' => '100']);
        $this->criarPlanoAcao(['uids_referencia' => ['100']]);

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertTrue($view->pronta);
        $this->assertCount(1, $view->planoAcoesAbertas);
    }

    public function test_paridade_pronta_com_alerta_de_suprimento(): void
    {
        $at = $this->criarAtividade();
        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::EmRisco->value]);
        $item->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertTrue($view->pronta);
        $this->assertCount(1, $view->suprimentos);
    }

    public function test_paridade_pronta_com_engenharia_atrasada(): void
    {
        $at = $this->criarAtividade();
        $item = $this->criarItemSuprimento();
        $item->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
        $doc = $this->criarDocumentoEngenharia(['data_planejada' => now()->subDays(5)]);
        $item->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertTrue($view->pronta);
        $this->assertCount(1, $view->engenharia);
        $this->assertTrue($view->engenharia[0]->atrasado);
        $this->assertFalse($view->engenharia[0]->emitido);
    }

    // =========================================================================
    // 2) CLASSIFICAÇÃO
    // =========================================================================

    public function test_classificacao_pronta_sem_alertas(): void
    {
        $at = $this->criarAtividade();

        $view = $this->viewDe($at);

        $this->assertSame(StatusOperacionalProntidao::Pronta, $view->statusOperacional);
        $this->assertSame([], $view->resumoMotivos);
    }

    public function test_classificacao_pronta_com_contexto_vira_atencao(): void
    {
        $at = $this->criarAtividade();
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => false]);

        $view = $this->viewDe($at);

        $this->assertSame(StatusOperacionalProntidao::Atencao, $view->statusOperacional);
        $this->assertTrue($view->pronta);
    }

    public function test_classificacao_nao_pronta(): void
    {
        $at = $this->criarAtividade();
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);

        $view = $this->viewDe($at);

        $this->assertSame(StatusOperacionalProntidao::NaoPronta, $view->statusOperacional);
    }

    public function test_classificacao_concluida_tem_prioridade_sobre_nao_pronta(): void
    {
        $at = $this->criarAtividade(['concluido_em' => now()]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);

        $view = $this->viewDe($at);

        $this->assertSame(StatusOperacionalProntidao::Concluida, $view->statusOperacional);
    }

    // =========================================================================
    // 3) ORIGEM DE RESTRIÇÃO
    // =========================================================================

    public function test_origem_manual(): void
    {
        $at = $this->criarAtividade();
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id]);

        $view = $this->viewDe($at);

        $this->assertSame(\App\Support\CentralProntidao\OrigemRestricaoProntidao::Manual, $view->restricoesBloqueantes[0]->origem);
    }

    public function test_origem_suprimento(): void
    {
        $at = $this->criarAtividade();
        $item = $this->criarItemSuprimento();
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $at->id,
            'origem_suprimento_item_id' => $item->id,
        ]);

        $view = $this->viewDe($at);

        $this->assertSame(\App\Support\CentralProntidao\OrigemRestricaoProntidao::Suprimento, $view->restricoesBloqueantes[0]->origem);
    }

    public function test_origem_plano_acao(): void
    {
        $at = $this->criarAtividade(['external_uid' => '200']);
        $acao = $this->criarPlanoAcao(['uids_referencia' => ['200']]);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $at->id,
            'bloqueante' => false,
            'origem_plano_acao_id' => $acao->id,
        ]);

        $view = $this->viewDe($at);

        $this->assertSame(\App\Support\CentralProntidao\OrigemRestricaoProntidao::PlanoAcao, $view->restricoesNaoBloqueantes[0]->origem);
    }

    // =========================================================================
    // 4) CHECKLIST
    // =========================================================================

    public function test_checklist_sem_nenhum_item_na_obra(): void
    {
        $at = $this->criarAtividade();

        $view = $this->viewDe($at);

        $this->assertSame(0, $view->checklistTotal);
        $this->assertSame([], $view->checklistPendentes);
    }

    public function test_checklist_completo(): void
    {
        $at = $this->criarAtividade();
        $item1 = $this->criarItemProntidao(nome: 'Projeto');
        $item2 = $this->criarItemProntidao(nome: 'Materiais');
        AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item1->id, 'concluido' => true]);
        AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item2->id, 'concluido' => true]);

        $view = $this->viewDe($at);

        $this->assertSame(2, $view->checklistTotal);
        $this->assertSame(2, $view->checklistConcluido);
        $this->assertSame([], $view->checklistPendentes);
    }

    public function test_checklist_parcialmente_concluido_e_ausencia_de_registro_conta_como_pendente(): void
    {
        $at = $this->criarAtividade();
        $item1 = $this->criarItemProntidao(nome: 'Projeto');
        $this->criarItemProntidao(nome: 'Materiais'); // nunca ganha registro em AtividadeItemProntidao
        AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item1->id, 'concluido' => true]);

        $view = $this->viewDe($at);

        $this->assertSame(2, $view->checklistTotal);
        $this->assertSame(1, $view->checklistConcluido);
        $this->assertSame(['Materiais'], $view->checklistPendentes);
    }

    // =========================================================================
    // 5) PLANO DE AÇÃO — associação por external_uid + isolamento entre obras
    // =========================================================================

    public function test_planoacao_associado_pelo_external_uid_correto(): void
    {
        $atAlvo = $this->criarAtividade(['external_uid' => '300']);
        $atOutra = $this->criarAtividade(['external_uid' => '301']);
        $this->criarPlanoAcao(['uids_referencia' => ['300']]);

        $viewAlvo = $this->viewDe($atAlvo);
        $viewOutra = $this->viewDe($atOutra);

        $this->assertCount(1, $viewAlvo->planoAcoesAbertas);
        $this->assertCount(0, $viewOutra->planoAcoesAbertas);
    }

    public function test_planoacao_de_outra_obra_com_mesmo_external_uid_nunca_e_associado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $atObraA = $this->criarAtividade(['external_uid' => 'UID-COMPARTILHADO'], $this->obra);
        $this->criarAtividade(['external_uid' => 'UID-COMPARTILHADO'], $obraB);

        // PlanoAcao pertence à Obra B, mas referencia o MESMO external_uid
        // que também existe na Obra A.
        $this->criarPlanoAcao(['uids_referencia' => ['UID-COMPARTILHADO']], $obraB);

        $viewObraA = $this->viewDe($atObraA, $this->obra);

        $this->assertCount(0, $viewObraA->planoAcoesAbertas);
    }

    // =========================================================================
    // 5b) DEDUPLICAÇÃO DE PLANOACAO (Ciclo 15, correção pós-auditoria B.1)
    // =========================================================================

    public function test_uid_duplicado_dentro_da_mesma_planoacao_aparece_uma_unica_vez(): void
    {
        $at = $this->criarAtividade(['external_uid' => 'UID-001']);
        $this->criarPlanoAcao(['uids_referencia' => ['UID-001', 'UID-001']]);

        $view = $this->viewDe($at);

        $this->assertCount(1, $view->planoAcoesAbertas);
    }

    public function test_planoacao_com_dois_uids_distintos_aparece_uma_vez_para_cada_atividade(): void
    {
        $at1 = $this->criarAtividade(['external_uid' => 'UID-001']);
        $at2 = $this->criarAtividade(['external_uid' => 'UID-002']);
        $this->criarPlanoAcao(['uids_referencia' => ['UID-001', 'UID-002']]);

        $view1 = $this->viewDe($at1);
        $view2 = $this->viewDe($at2);

        $this->assertCount(1, $view1->planoAcoesAbertas);
        $this->assertCount(1, $view2->planoAcoesAbertas);
    }

    public function test_duas_planoacoes_distintas_com_mesmo_external_uid_continuam_como_duas_acoes(): void
    {
        $at = $this->criarAtividade(['external_uid' => 'UID-001']);
        $acao1 = $this->criarPlanoAcao(['regra_id' => 'SLACK-001', 'uids_referencia' => ['UID-001']]);
        $acao2 = $this->criarPlanoAcao(['regra_id' => 'STRUCT-005', 'uids_referencia' => ['UID-001']]);

        $view = $this->viewDe($at);

        $this->assertCount(2, $view->planoAcoesAbertas);
        $idsRetornados = array_map(fn ($resumo) => $resumo->id, $view->planoAcoesAbertas);
        $this->assertContains($acao1->id, $idsRetornados);
        $this->assertContains($acao2->id, $idsRetornados);
    }

    public function test_deduplicacao_de_planoacao_nao_altera_pronta_nem_statusoperacional(): void
    {
        $at = $this->criarAtividade(['external_uid' => 'UID-001']);
        $this->criarPlanoAcao(['uids_referencia' => ['UID-001', 'UID-001']]);

        $view = $this->viewDe($at);

        $this->assertSame($at->estaPronta(), $view->pronta);
        $this->assertTrue($view->pronta);
        $this->assertSame(StatusOperacionalProntidao::Atencao, $view->statusOperacional);
    }

    // =========================================================================
    // 6) SUPRIMENTO — contexto, nunca altera pronta
    // =========================================================================

    public function test_suprimento_em_risco_aparece_como_contexto_sem_alterar_pronta(): void
    {
        $at = $this->criarAtividade();
        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::Atrasado->value]);
        $item->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertTrue($view->pronta);
        $this->assertSame(StatusOperacionalProntidao::Atencao, $view->statusOperacional);
        $this->assertSame(StatusItemSuprimento::Atrasado, $view->suprimentos[0]->status);
    }

    public function test_suprimento_no_inicio_nao_gera_alerta(): void
    {
        $at = $this->criarAtividade();
        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::NoInicio->value]);
        $item->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertSame([], $view->suprimentos);
        $this->assertSame(StatusOperacionalProntidao::Pronta, $view->statusOperacional);
    }

    // =========================================================================
    // 7) ENGENHARIA — contexto, nunca altera pronta
    // =========================================================================

    public function test_documento_nao_emitido_sem_atraso_aparece_como_contexto(): void
    {
        $at = $this->criarAtividade();
        $item = $this->criarItemSuprimento();
        $item->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
        $doc = $this->criarDocumentoEngenharia(['data_planejada' => now()->addDays(10)]);
        $item->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertTrue($view->pronta);
        $this->assertCount(1, $view->engenharia);
        $this->assertFalse($view->engenharia[0]->atrasado);
        $this->assertFalse($view->engenharia[0]->emitido);
    }

    // =========================================================================
    // 8) FORA DO CRONOGRAMA
    // =========================================================================

    public function test_atividade_fora_do_cronograma_nao_aparece(): void
    {
        $at = $this->criarAtividade(['fora_do_cronograma' => true]);

        $views = $this->query->paraObra($this->obra);

        $this->assertFalse($views->contains(fn ($v) => $v->atividadeId === $at->id));
    }

    // =========================================================================
    // 9) CONCLUÍDA
    // =========================================================================

    public function test_atividade_concluida_e_classificada_como_concluida(): void
    {
        $at = $this->criarAtividade(['concluido_em' => now()]);

        $view = $this->viewDe($at);

        $this->assertSame(StatusOperacionalProntidao::Concluida, $view->statusOperacional);
    }

    // =========================================================================
    // 10) AUSÊNCIA DE DADOS RELACIONADOS
    // =========================================================================

    public function test_atividade_sem_nenhum_dado_relacionado_e_pronta_sem_erro(): void
    {
        $at = $this->criarAtividade();

        $view = $this->viewDe($at);

        $this->assertTrue($view->pronta);
        $this->assertSame(StatusOperacionalProntidao::Pronta, $view->statusOperacional);
        $this->assertSame([], $view->restricoesBloqueantes);
        $this->assertSame([], $view->restricoesNaoBloqueantes);
        $this->assertSame([], $view->planoAcoesAbertas);
        $this->assertSame([], $view->suprimentos);
        $this->assertSame([], $view->engenharia);
    }

    // =========================================================================
    // 11) N+1
    // =========================================================================

    /**
     * Mede o número de queries pra montar a Central com N atividades, cada
     * uma com Restricao + checklist + PlanoAcao + Suprimento + Documento
     * de Engenharia vinculados — o cenário mais pesado possível pra 1
     * atividade. Cada chamada usa sua PRÓPRIA variável `$queryCount` local
     * (mesmo cuidado documentado em PlanoAcaoPainelTest — DB::listen()
     * empilha listeners globalmente sem removê-los entre chamadas).
     */
    private function contarQueries(int $quantidadeAtividades): int
    {
        $item = $this->criarItemProntidao();

        for ($i = 0; $i < $quantidadeAtividades; $i++) {
            $at = $this->criarAtividade(['external_uid' => "n1-{$i}"]);
            AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item->id, 'concluido' => true]);
            Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => false]);
            $this->criarPlanoAcao(['uids_referencia' => ["n1-{$i}"]]);

            $suprimento = $this->criarItemSuprimento(['status' => StatusItemSuprimento::EmRisco->value]);
            $suprimento->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
            $doc = $this->criarDocumentoEngenharia(['data_planejada' => now()->subDay()]);
            $suprimento->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->query->paraObra($this->obra);

        return $queryCount;
    }

    public function test_quantidade_de_queries_nao_escala_proporcionalmente_ao_numero_de_atividades(): void
    {
        $queriesCom5 = $this->contarQueries(5);

        // Novo cenário isolado (novo tenant/obra) — o teste mede o CUSTO
        // ABSOLUTO de cada chamada, não um acumulado sobre o mesmo dataset.
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $queriesCom20 = $this->contarQueries(20);

        // O número de queries é O(1) em relação à quantidade de atividades
        // (eager-load + whereIn, nunca 1 query por atividade) — a diferença
        // entre 5 e 20 atividades deve ser desprezível, nunca proporcional
        // ao salto de 4x na quantidade de dados.
        $this->assertLessThan(
            $queriesCom5 + 5,
            $queriesCom20,
            "esperava contagem de queries praticamente constante. Com 5 atividades: {$queriesCom5}, com 20 atividades: {$queriesCom20}"
        );
    }

    // =========================================================================
    // 12) TENANT / OBRA
    // =========================================================================

    public function test_atividade_de_outra_obra_do_mesmo_tenant_nunca_aparece(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atObraB = $this->criarAtividade([], $obraB);

        $views = $this->query->paraObra($this->obra);

        $this->assertFalse($views->contains(fn ($v) => $v->atividadeId === $atObraB->id));
    }

    public function test_atividade_de_outro_tenant_nunca_aparece(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $atOutroTenant = Atividade::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'fora_do_cronograma' => false,
        ]);

        $views = $this->query->paraObra($this->obra);

        $this->assertFalse($views->contains(fn ($v) => $v->atividadeId === $atOutroTenant->id));
    }

    // =========================================================================
    // Horizonte (parâmetro opcional, sem UI nesta etapa)
    // =========================================================================

    public function test_horizonte_filtra_por_inicio_planejado_via_sql(): void
    {
        $dentro = $this->criarAtividade(['inicio_planejado' => now()->addDays(5)]);
        $fora = $this->criarAtividade(['inicio_planejado' => now()->addDays(40)]);

        $views = $this->query->paraObra($this->obra, now()->addDays(30));

        $this->assertTrue($views->contains(fn ($v) => $v->atividadeId === $dentro->id));
        $this->assertFalse($views->contains(fn ($v) => $v->atividadeId === $fora->id));
    }

    public function test_sem_horizonte_retorna_todas_as_atividades_do_cronograma(): void
    {
        $this->criarAtividade(['inicio_planejado' => now()->addDays(400)]);

        $views = $this->query->paraObra($this->obra);

        $this->assertCount(1, $views);
    }

    // =========================================================================
    // Ciclo 15, Etapa B.4 — frenteNome/responsavelNome (extensão aditiva do
    // DTO, autorizada explicitamente pelo usuário durante a B.4 pra suportar
    // as colunas "Frente"/"Responsável" na exportação).
    // =========================================================================

    public function test_frente_e_responsavel_da_atividade_aparecem_na_view(): void
    {
        $frente = FrenteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Frente Norte']);
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ana', 'last_name' => 'Souza']);

        $at = $this->criarAtividade(['frente_trabalho_id' => $frente->id, 'responsavel_id' => $responsavel->id]);

        $view = $this->query->paraObra($this->obra)->firstWhere('atividadeId', $at->id);

        $this->assertSame('Frente Norte', $view->frenteNome);
        $this->assertSame('Ana Souza', $view->responsavelNome);
    }

    public function test_atividade_sem_frente_ou_responsavel_retorna_null(): void
    {
        $at = $this->criarAtividade(['frente_trabalho_id' => null, 'responsavel_id' => null]);

        $view = $this->query->paraObra($this->obra)->firstWhere('atividadeId', $at->id);

        $this->assertNull($view->frenteNome);
        $this->assertNull($view->responsavelNome);
    }

    // =========================================================================
    // 9) EAGER-LOAD DE NECESSIDADE (Achado C, Ciclo 21 — Etapa 21.7.CORREÇÃO)
    //
    // `carregarAtividades()` eager-carrega `itensSuprimento.atividades` com
    // select limitado — precisa incluir TODA coluna que
    // `ItemSuprimento::necessidade()` lê (`inicio_planejado` E
    // `fora_do_cronograma`), senão a coluna omitida chega como `null`
    // (falsy) e o `reject(fn($a) => $a->fora_do_cronograma)` interno nunca
    // rejeita nada — uma atividade arquivada passa a contar na
    // necessidade do Pacote.
    // =========================================================================

    public function test_c_necessidade_ignora_atividade_arquivada_mais_cedo(): void
    {
        $atividadeArquivadaCedo = $this->criarAtividade([
            'inicio_planejado' => now()->addDays(3),
            'fora_do_cronograma' => true,
        ]);
        $atividadeAtivaPosterior = $this->criarAtividade([
            'inicio_planejado' => now()->addDays(10),
            'fora_do_cronograma' => false,
        ]);

        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::Atrasado->value]);
        $item->atividades()->attach($atividadeArquivadaCedo->id, ['tenant_id' => $this->tenant->id]);
        $item->atividades()->attach($atividadeAtivaPosterior->id, ['tenant_id' => $this->tenant->id]);

        // A atividade ativa posterior precisa estar visível na Central
        // pra `itensSuprimento` ser resolvido dentro do eager-load real —
        // usamos ela mesma como o "nó" cuja view carrega o Pacote.
        $view = $this->viewDe($atividadeAtivaPosterior);

        $this->assertNotEmpty($view->suprimentos);
        $this->assertTrue(
            $atividadeAtivaPosterior->inicio_planejado->isSameDay($view->suprimentos[0]->necessidade),
            'A necessidade nunca pode ser antecipada por uma atividade fora do cronograma.'
        );
        $this->assertFalse(
            $atividadeArquivadaCedo->inicio_planejado->isSameDay($view->suprimentos[0]->necessidade),
            'A atividade arquivada não pode determinar a necessidade do Pacote.'
        );
    }

    public function test_c2_necessidade_somente_atividade_ativa(): void
    {
        $ativa = $this->criarAtividade(['inicio_planejado' => now()->addDays(5), 'fora_do_cronograma' => false]);
        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::Atrasado->value]);
        $item->atividades()->attach($ativa->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($ativa);

        $this->assertTrue($ativa->inicio_planejado->isSameDay($view->suprimentos[0]->necessidade));
    }

    public function test_c3_necessidade_multiplas_atividades_ativas_usa_menor_data(): void
    {
        $maisTarde = $this->criarAtividade(['inicio_planejado' => now()->addDays(20), 'fora_do_cronograma' => false]);
        $maisCedo = $this->criarAtividade(['inicio_planejado' => now()->addDays(2), 'fora_do_cronograma' => false]);
        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::Atrasado->value]);
        $item->atividades()->attach($maisTarde->id, ['tenant_id' => $this->tenant->id]);
        $item->atividades()->attach($maisCedo->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($maisTarde);

        $this->assertTrue($maisCedo->inicio_planejado->isSameDay($view->suprimentos[0]->necessidade));
    }

    public function test_c4_necessidade_todas_fora_do_cronograma_preserva_comportamento_atual(): void
    {
        // Não inventa semântica nova: prova o comportamento ATUAL de
        // `necessidade()` (min() sobre coleção vazia após reject() = null).
        $arquivadaA = $this->criarAtividade(['inicio_planejado' => now()->addDays(3), 'fora_do_cronograma' => true]);
        $arquivadaB = $this->criarAtividade(['inicio_planejado' => now()->addDays(8), 'fora_do_cronograma' => true]);
        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::Atrasado->value]);
        $item->atividades()->attach($arquivadaA->id, ['tenant_id' => $this->tenant->id]);
        $item->atividades()->attach($arquivadaB->id, ['tenant_id' => $this->tenant->id]);

        // Nenhuma das duas atividades passa pelo filtro
        // `where('fora_do_cronograma', false)` da query principal — o
        // Pacote nunca aparece vinculado a nenhuma view. Confirma direto
        // sobre o model, com a relação completa (sem select limitado).
        $this->assertNull($item->fresh()->necessidade());
    }

    public function test_c5_convergencia_necessidade_entre_relacao_completa_e_central_prontidao(): void
    {
        $arquivadaCedo = $this->criarAtividade(['inicio_planejado' => now()->addDays(1), 'fora_do_cronograma' => true]);
        $ativaPosterior = $this->criarAtividade(['inicio_planejado' => now()->addDays(15), 'fora_do_cronograma' => false]);
        $item = $this->criarItemSuprimento(['status' => StatusItemSuprimento::Atrasado->value]);
        $item->atividades()->attach($arquivadaCedo->id, ['tenant_id' => $this->tenant->id]);
        $item->atividades()->attach($ativaPosterior->id, ['tenant_id' => $this->tenant->id]);

        // 1) Relação completa, sem select limitado — sempre a fonte de
        // verdade (nenhuma fórmula reimplementada aqui).
        $necessidadeCompleta = $item->fresh()->necessidade();

        // 2) Resultado consumido pela CentralProntidaoQuery (mesmo select
        // limitado real do produto, agora corrigido).
        $necessidadeCentral = $this->viewDe($ativaPosterior)->suprimentos[0]->necessidade;

        // 3) Mesma leitura, via um eager-load SEM nenhuma limitação de
        // coluna (idêntico ao padrão já usado em CockpitSuprimentosQuery,
        // que nunca foi afetado pelo Achado C) — terceiro ponto de
        // convergência, sem tocar nenhum arquivo de Gestão/Cockpit.
        $itemSemLimite = ItemSuprimento::with('atividades')->findOrFail($item->id);
        $necessidadeSemLimite = $itemSemLimite->necessidade();

        $this->assertNotNull($necessidadeCompleta);
        $this->assertTrue($necessidadeCompleta->isSameDay($necessidadeCentral));
        $this->assertTrue($necessidadeCompleta->isSameDay($necessidadeSemLimite));
        $this->assertTrue($necessidadeCompleta->isSameDay($ativaPosterior->inicio_planejado));
    }
}
