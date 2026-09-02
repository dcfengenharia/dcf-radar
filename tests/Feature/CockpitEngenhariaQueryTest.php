<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\RegistrarAceiteEntrega;
use App\Actions\Engenharia\RegistrarRecolhimento;
use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
use App\Enums\EstadoProntidaoEngenharia;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\ResultadoRecolhimento;
use App\Enums\StatusAtividade;
use App\Enums\TipoAceiteGrd;
use App\Enums\TipoLocalEstoque;
use App\Models\Atividade;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Gestao\CockpitEngenhariaQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 22, Etapa 22.2 — Cockpit de Engenharia. Cenários A-R do pedido
 * (menos os puramente de UI, cobertos em `CockpitEngenhariaPageTest`) +
 * consistência com as fontes autoritativas + performance.
 */
class CockpitEngenhariaQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (espelham InteligenciaEngenhariaQueryTest) ----

    private function at(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id,
            'fora_do_cronograma' => false, 'status' => StatusAtividade::Planejado,
            'inicio_planejado' => now()->addDays(2),
        ], $overrides));
    }

    private function doc(?Work $obra = null): DocumentoEngenharia
    {
        return DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id,
            'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x',
        ]);
    }

    private function rev(DocumentoEngenharia $d, string $texto = 'R1'): DocumentoEngenhariaRevisao
    {
        return $d->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => $texto, 'descricao' => 'x'])->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $r): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $this->user);
    }

    private function vincular(Atividade $at, DocumentoEngenharia $doc): void
    {
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
    }

    private function destinatario(?Work $obra = null): Destinatario
    {
        return Destinatario::create(['tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Dest ' . uniqid()]);
    }

    private function grdEmitidaComDistribuicao(DocumentoEngenhariaRevisao $revisao, int $quantidade = 2): array
    {
        $dest = $this->destinatario();
        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisao->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $dest);
        $dist = $acoes->marcarDistribuicao($grd, $item, $gd, $quantidade);
        $grd = (new EmitirGrd())->execute($grd, $this->user);

        return compact('grd', 'item', 'gd', 'dist');
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
    }

    private function criarFornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(Fornecedor $fornecedor): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
    }

    // =========================================================================
    // A-H — Prontidão/matriz/buckets
    // =========================================================================

    public function test_a_atividade_amanha_documento_nao_liberado_aparece_no_topo(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertGreaterThan(0, $resumo->resumoExecutivo['atividades_bloqueadas']);
        $this->assertNotEmpty($resumo->acaoPrioritaria);
        $this->assertSame(1, $resumo->acaoPrioritaria->first()['diasParaRelevante']);
    }

    public function test_b_documento_liberado_sai_do_bloqueio(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        $this->vincular($at, $doc);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertSame(0, $resumo->resumoExecutivo['atividades_bloqueadas']);
        $this->assertEmpty($resumo->acaoPrioritaria);
    }

    public function test_c_cinco_documentos_quatro_liberados_mostra_4_de_5_e_bloqueante(): void
    {
        $at = $this->at();
        foreach (range(1, 5) as $i) {
            $doc = $this->doc();
            $r = $this->rev($doc, "R{$i}");
            if ($i <= 4) {
                $this->liberar($r);
            }
            $this->vincular($at, $doc);
        }

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);
        $linha = $resumo->matrizAtividades->first(fn ($l) => $l->atividadeId === $at->id);

        $this->assertSame(4, $linha->totalLiberados());
        $this->assertSame(5, $linha->totalDocumentos());
        $this->assertCount(1, $linha->documentosBloqueantes());
        $this->assertSame(EstadoProntidaoEngenharia::Parcial, $linha->estado);
    }

    public function test_d_nenhuma_documentacao_e_informacao_insuficiente(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertGreaterThan(0, $resumo->resumoExecutivo['atividades_informacao_insuficiente']);
        $this->assertTrue($resumo->informacaoInsuficiente->contains(fn ($l) => $l->atividadeId === $at->id));
        $this->assertSame(0, $resumo->resumoExecutivo['atividades_bloqueadas'], 'informação insuficiente nunca conta como bloqueada.');
    }

    public function test_e_atividade_fora_de_14_dentro_de_28_aparece_no_bucket_correto(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDays(20)]);
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        $this->vincular($at, $doc);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertSame(0, $resumo->prontidaoPorHorizonte[2]->total, 'não deveria aparecer no bucket de 2 semanas (14 dias).');
        $this->assertSame(1, $resumo->prontidaoPorHorizonte[4]->total, 'deveria aparecer no bucket de 4 semanas (28 dias).');
        $this->assertSame(1, $resumo->prontidaoPorHorizonte[8]->total);
    }

    public function test_f_atividade_concluida_nao_gera_urgencia_falsa(): void
    {
        $at = $this->at(['status' => StatusAtividade::Concluido, 'inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertFalse($resumo->matrizAtividades->contains(fn ($l) => $l->atividadeId === $at->id));
        $this->assertFalse($resumo->informacaoInsuficiente->contains(fn ($l) => $l->atividadeId === $at->id));
    }

    public function test_g_atividade_fora_do_cronograma_nao_gera_bloqueio(): void
    {
        $at = $this->at(['fora_do_cronograma' => true, 'inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertFalse($resumo->matrizAtividades->contains(fn ($l) => $l->atividadeId === $at->id));
    }

    public function test_h_r1_liberada_r2_criada_ui_reflete_semantica_real(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1);
        $this->vincular($at, $doc);
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()]);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);
        $linha = $resumo->matrizAtividades->first(fn ($l) => $l->atividadeId === $at->id);
        $docDto = $linha->documentos->first();

        $this->assertSame(EstadoProntidaoEngenharia::Bloqueada, $linha->estado);
        $this->assertSame(2, $docDto->totalRevisoesDocumento, 'precisa saber que existe mais de 1 revisão pra frasear corretamente.');
        $this->assertFalse($docDto->liberado);
    }

    // =========================================================================
    // I-L — GRD/aceite/cópias obsoletas
    // =========================================================================

    public function test_i_grd_aguardando_aceite(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        $this->grdEmitidaComDistribuicao($r);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertSame(1, $resumo->resumoExecutivo['grds_aguardando_aceite']);
        $this->assertCount(1, $resumo->grdAguardandoAceite);
    }

    public function test_j_grd_aceita_resolve(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        ['gd' => $gd] = $this->grdEmitidaComDistribuicao($r);
        (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Recebedor', $this->user);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertSame(0, $resumo->resumoExecutivo['grds_aguardando_aceite']);
    }

    public function test_k_copia_obsoleta_pendente(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1);
        $this->grdEmitidaComDistribuicao($r1, 3);
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()]);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertSame(1, $resumo->resumoExecutivo['copias_obsoletas_pendentes']);
    }

    public function test_l_copia_recolhida_desaparece(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1);
        ['dist' => $dist] = $this->grdEmitidaComDistribuicao($r1, 3);
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()]);
        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::Recolhido, 3, $this->user);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertSame(0, $resumo->resumoExecutivo['copias_obsoletas_pendentes']);
    }

    // =========================================================================
    // M — Industrialização, linguagem neutra
    // =========================================================================

    public function test_m_industrializacao_congelada_linguagem_neutra(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $material = $this->criarMaterial();
        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $local, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $material, 10, $this->user, $r1);
        (new EmitirOrdemIndustrializacao())->execute($ordem, $this->user);
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()]);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertCount(1, $resumo->industrializacaoComMudancaRevisao);
        $descricao = mb_strtolower($resumo->industrializacaoComMudancaRevisao->first()->descricao);
        $this->assertStringNotContainsString('inválid', $descricao);
        $this->assertStringNotContainsString('errado', $descricao);
        $this->assertStringNotContainsString('retrabalho', $descricao);
        $this->assertStringNotContainsString('atrasad', $descricao);
    }

    // =========================================================================
    // N-O — Suprimentos / dupla restrição
    // =========================================================================

    public function test_n_item_suprimentos_vinculado_a_documento_nao_liberado(): void
    {
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote X']);
        $pacote->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertSame(1, $resumo->resumoExecutivo['suprimento_bloqueado']);
    }

    public function test_o_atividade_com_documento_e_material_bloqueante_marca_dupla_restricao_sem_duplicar_regra(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        // Material insuficiente: Pacote vinculado à MESMA atividade, sem
        // nenhuma alocação de RP — CoberturaMaterialAtividadeQuery (Ciclo
        // 21.1) já classifica isso como estado acionável (AguardandoCompra
        // via SituacoesGerenciaisQuery::materialCritico(), Ciclo 21.2) —
        // NUNCA recalculado aqui, só consumido.
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Material']);
        $at->itensSuprimento()->attach($pacote->id, ['tenant_id' => $this->tenant->id]);

        $material = $this->criarMaterial();
        $itemTakeOff = $this->criarItemTakeOffComMaterial($material);
        $rpItem = $this->emitirRpComItem($itemTakeOff, 10);
        (new \App\Actions\Suprimentos\AlocarRequisicaoAoPacote())->alocar($rpItem, $pacote, 10);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);
        $linhaAcao = $resumo->acaoPrioritaria->first(fn ($l) => $l['tipo'] === 'documento_bloqueante');

        $this->assertNotNull($linhaAcao);
        $this->assertTrue($linhaAcao['duplaRestricao'], 'atividade com documento E material bloqueante deveria marcar dupla restrição.');
    }

    private function criarItemTakeOffComMaterial(Material $material): \App\Models\ItemTakeOff
    {
        $doc = $this->doc();
        $rev = $this->rev($doc, 'R1');
        $lista = \App\Models\ListaEngenharia::create([
            'documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM-' . uniqid(),
        ]);

        return \App\Models\ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'IT-' . uniqid(), 'descricao' => 'x',
            'quantidade' => 100, 'material_id' => $material->id,
        ]);
    }

    private function emitirRpComItem(\App\Models\ItemTakeOff $item, float $quantidade): \App\Models\RequisicaoPlanejamentoItem
    {
        $rp = (new \App\Actions\Suprimentos\CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new \App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new \App\Actions\Suprimentos\EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    // =========================================================================
    // P-Q — Multi-obra / cross-tenant
    // =========================================================================

    public function test_p_multiobra_isolamento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atB = $this->at(['inicio_planejado' => now()->addDay()], $obraB);
        $docB = $this->doc($obraB);
        $this->rev($docB, 'R1');
        $atB->documentosEngenharia()->attach($docB->id, ['tenant_id' => $this->tenant->id]);

        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        $this->vincular($at, $doc);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertFalse($resumo->matrizAtividades->contains(fn ($l) => $l->atividadeId === $atB->id));
    }

    public function test_q_cross_tenant_nunca_vaza(): void
    {
        $outroTenant = Tenant::factory()->create();

        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $atOutro = Atividade::factory()->create([
                'tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'fora_do_cronograma' => false,
                'status' => StatusAtividade::Planejado, 'inicio_planejado' => now()->addDay(),
            ]);
            $docOutro = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'codigo' => 'DOC-OUTRO', 'descricao' => 'x']);
            $docOutro->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
            $atOutro->documentosEngenharia()->attach($docOutro->id, ['tenant_id' => $outroTenant->id]);
        });

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        $this->assertEmpty($resumo->matrizAtividades);
    }

    // =========================================================================
    // Consistência (Seção 33)
    // =========================================================================

    public function test_consistencia_com_inteligenciaengenhariaquery_e_scopeprontas(): void
    {
        $atBloqueada = $this->at(['inicio_planejado' => now()->addDay()]);
        $docNaoLiberado = $this->doc();
        $this->rev($docNaoLiberado, 'R1');
        $this->vincular($atBloqueada, $docNaoLiberado);

        $atLiberada = $this->at(['inicio_planejado' => now()->addDay()]);
        $docLiberado = $this->doc();
        $r = $this->rev($docLiberado, 'R1');
        $this->liberar($r);
        $this->vincular($atLiberada, $docLiberado);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        foreach ($resumo->matrizAtividades as $linha) {
            $atividade = Atividade::findOrFail($linha->atividadeId);
            $estaPronta = $atividade->estaPronta();
            $bloqueadaOuParcial = in_array($linha->estado, [EstadoProntidaoEngenharia::Bloqueada, EstadoProntidaoEngenharia::Parcial], true);
            $this->assertSame(! $bloqueadaOuParcial, $estaPronta);
        }
    }

    // =========================================================================
    // Performance (Seção 28)
    // =========================================================================

    public function test_performance_delta_10_100_atividades(): void
    {
        $medir = function (int $n): array {
            $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
            for ($i = 0; $i < $n; $i++) {
                $at = $this->at(['inicio_planejado' => now()->addDay()], $obra);
                $doc = $this->doc($obra);
                $r = $this->rev($doc, 'R1');
                if ($i % 2 === 0) {
                    $this->liberar($r);
                }
                $this->vincular($at, $doc);
            }

            DB::enableQueryLog();
            $inicio = microtime(true);
            CockpitEngenhariaQuery::resumo($obra, 28);
            $tempo = (microtime(true) - $inicio) * 1000;
            $log = DB::getQueryLog();
            $count = count($log);
            $piorQuery = collect($log)->max('time') ?? 0;
            DB::disableQueryLog();
            DB::flushQueryLog();

            return [$count, $tempo, $piorQuery];
        };

        [$q10, $t10, $p10] = $medir(10);
        [$q100, $t100, $p100] = $medir(100);

        fwrite(STDERR, sprintf(
            "\n[DELTA CockpitEngenhariaQuery] 10 atividades -> %d queries, %.1fms, pior=%.2fms | 100 atividades -> %d queries, %.1fms, pior=%.2fms\n",
            $q10, $t10, $p10, $q100, $t100, $p100
        ));

        $this->assertLessThan($q10 * 3, $q100, 'Query count não pode escalar proporcionalmente ao número de atividades.');
    }
}
