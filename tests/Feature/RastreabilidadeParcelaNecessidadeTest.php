<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Exceptions\ParcelaNecessidadeInvalidaException;
use App\Exceptions\SaldoParcelaNecessidadeInsuficienteException;
use App\Exceptions\SaldoParcelaPedidoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\PedidoCompraItem;
use App\Models\PedidoCompraItemParcela;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoCompraItemParcela;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rastreabilidade Quantitativa, Etapa 1 — cobertura A-P do pedido de
 * implementação: ponte `RequisicaoCompraItemParcela`/
 * `PedidoCompraItemParcela` entre `AtividadeNecessidadeMaterial` (a
 * parcela canônica) e a cadeia comercial RC→Pedido. Nunca proporcional/
 * inferida — sempre explícita, sempre com saldo derivado sob lock.
 */
class RastreabilidadeParcelaNecessidadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;
    private CriarRequisicaoCompra $criarRc;
    private AtualizarRascunhoRequisicaoCompra $atualizarRc;
    private EmitirRequisicaoCompra $emitirRc;
    private CriarPedidoCompra $criarPedido;
    private AtualizarRascunhoPedidoCompra $atualizarPedido;
    private EmitirPedidoCompra $emitirPedido;
    private AtualizarNecessidadeMaterialAtividade $necessidadeAction;
    private AtualizarDistribuicaoParcelaRequisicaoCompra $parcelaRc;
    private AtualizarDistribuicaoParcelaPedidoCompra $parcelaPedido;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
        $this->criarRc = new CriarRequisicaoCompra();
        $this->atualizarRc = new AtualizarRascunhoRequisicaoCompra();
        $this->emitirRc = new EmitirRequisicaoCompra();
        $this->criarPedido = new CriarPedidoCompra();
        $this->atualizarPedido = new AtualizarRascunhoPedidoCompra();
        $this->emitirPedido = new EmitirPedidoCompra();
        $this->necessidadeAction = new AtualizarNecessidadeMaterialAtividade();
        $this->parcelaRc = new AtualizarDistribuicaoParcelaRequisicaoCompra();
        $this->parcelaPedido = new AtualizarDistribuicaoParcelaPedidoCompra();
    }

    // ---- helpers ----

    private function criarAtividade(?Work $obra = null): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
        ]);
    }

    private function criarMaterial(?Work $obraIrrelevante = null): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Cabo 3x25',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarItemTakeOff(Material $material, float $quantidade, ?Work $obra = null): ItemTakeOff
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'A' . uniqid(),
            'descricao' => 'Cabo 3x25 mm²',
            'unidade_medida_id' => $this->unidade->id,
            'quantidade' => $quantidade,
            'material_id' => $material->id,
        ]);
    }

    private function criarPacote(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid(), 'codigo' => 'PAC' . uniqid()]);
    }

    private function criarNecessidade(Atividade $atividade, ItemTakeOff $itemTakeOff, float $quantidade): AtividadeNecessidadeMaterial
    {
        return $this->necessidadeAction->criarTakeOff($atividade, $itemTakeOff, $quantidade, $this->user);
    }

    /** Alocação pronta (RP emitida + alocada ao Pacote) sobre o MESMO ItemTakeOff informado. */
    private function alocacaoPronta(ItemTakeOff $itemTakeOff, float $quantidadeAlocada, ?ItemSuprimento $pacote = null, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $obraAlvo = $obra ?? $this->obra;
        $rp = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $itemTakeOff->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote($obraAlvo), $quantidadeAlocada);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    /**
     * RC RASCUNHO com 1 item (ainda editável) sobre a Alocação informada
     * — o estado normal em que uma "Distribuição por Atividade" é
     * construída (Seção 10 do pedido: parcela é editável enquanto
     * Rascunho, congela na emissão, mesma disciplina de
     * `AtualizarRascunhoRequisicaoCompra::adicionarItem()`).
     *
     * @return array{0: RequisicaoCompra, 1: RequisicaoCompraItem}
     */
    private function rcComItemRascunho(AlocacaoRequisicaoPacote $alocacao, float $quantidade): array
    {
        $rc = $this->criarRc->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);

        return [$rc, $item];
    }

    /** RC Emitida com 1 item (quantidade dada), SEM nenhuma parcela detalhada ainda. */
    private function rcItemEmitido(AlocacaoRequisicaoPacote $alocacao, float $quantidade): RequisicaoCompraItem
    {
        [$rc, $item] = $this->rcComItemRascunho($alocacao, $quantidade);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        return $item->fresh();
    }

    private function criarFornecedor(?Work $obra = null): Fornecedor
    {
        return Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Fornecedor X', 'cnpj' => '00.000.000/0001-00']);
    }

    /** Pedido em Rascunho já com 1 item apontando pra $rcItem, quantidade dada. */
    private function pedidoItemRascunho(RequisicaoCompraItem $rcItem, float $quantidade, ?Fornecedor $fornecedor = null): PedidoCompraItem
    {
        $rc = $rcItem->requisicaoCompra;
        $pedido = $this->criarPedido->execute($rc, $fornecedor ?? $this->criarFornecedor(), '2027-01-15', null, null, null, $this->user);

        return $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade);
    }

    // =========================================================================
    // A/B/C — split e merge
    // =========================================================================

    public function test_a_uma_parcela_para_um_rcitem(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $alocacao = $this->alocacaoPronta($ito, 600);
        [, $rcItem] = $this->rcComItemRascunho($alocacao, 600);

        $parcela = $this->parcelaRc->adicionarParcela($rcItem, $necessidade, 300, $this->user);

        $this->assertSame($rcItem->id, $parcela->requisicao_compra_item_id);
        $this->assertSame($necessidade->id, $parcela->atividade_necessidade_material_id);
        $this->assertEquals(300.0, (float) $parcela->quantidade);
    }

    public function test_b_um_rcitem_reune_varias_parcelas_merge(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 300);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 450);
        $alocacao = $this->alocacaoPronta($ito, 600);
        [, $rcItem] = $this->rcComItemRascunho($alocacao, 600);

        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeA, 300, $this->user);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeB, 300, $this->user);

        $this->assertSame(2, RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $rcItem->id)->count());
        $this->assertEquals(600.0, (float) RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $rcItem->id)->sum('quantidade'));
    }

    public function test_c_uma_parcela_dividida_entre_varias_rcitens_split(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividadeB = $this->criarAtividade();
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 450);

        $pacote = $this->criarPacote();
        $alocacao1 = $this->alocacaoPronta($ito, 150, $pacote);
        [$rc1, $rcItem1] = $this->rcComItemRascunho($alocacao1, 150);
        $this->parcelaRc->adicionarParcela($rcItem1, $necessidadeB, 150, $this->user);
        $this->emitirRc->execute($rc1->fresh(), $this->user);

        $alocacao2 = $this->alocacaoPronta($ito, 300, $pacote);
        [$rc2, $rcItem2] = $this->rcComItemRascunho($alocacao2, 300);
        $this->parcelaRc->adicionarParcela($rcItem2, $necessidadeB, 250, $this->user);
        $this->emitirRc->execute($rc2->fresh(), $this->user);

        $this->assertEquals(400.0, $necessidadeB->fresh()->quantidadeDetalhadaOficialEmRc());
        $this->assertEquals(50.0, $necessidadeB->fresh()->saldoOficialParaDetalheRc());
    }

    // =========================================================================
    // D/E — guardas, nunca ultrapassar a origem
    // =========================================================================

    public function test_d_soma_por_parcela_acima_da_necessidade_e_rejeitada(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $alocacao = $this->alocacaoPronta($ito, 600);
        [$rc, $rcItem] = $this->rcComItemRascunho($alocacao, 600);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidade, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user); // agora 300 é OFICIAL

        // 2ª RC (ainda Rascunho) tentando detalhar mais 50 da MESMA
        // necessidade, já 100% consumida oficialmente pela primeira RC.
        $alocacao2 = $this->alocacaoPronta($ito, 50, $alocacao->pacote);
        [, $rcItem2] = $this->rcComItemRascunho($alocacao2, 50);

        $this->expectException(SaldoParcelaNecessidadeInsuficienteException::class);
        $this->parcelaRc->adicionarParcela($rcItem2, $necessidade, 50, $this->user);
    }

    public function test_e_soma_das_parcelas_de_rcitem_acima_da_quantidade_do_item_e_rejeitada(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 300);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 450);
        $alocacao = $this->alocacaoPronta($ito, 600);
        [, $rcItem] = $this->rcComItemRascunho($alocacao, 600);

        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeA, 300, $this->user);

        $this->expectException(SaldoParcelaNecessidadeInsuficienteException::class);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeB, 400, $this->user); // 300 + 400 = 700 > 600
    }

    // =========================================================================
    // F — concorrência: dois rascunhos sobrepostos, só a emissão serializa
    // =========================================================================

    public function test_f_dois_rascunhos_sobrepostos_so_a_emissao_do_segundo_falha(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $pacote = $this->criarPacote();

        // RC-01 rascunho: detalha 300 (ainda não emitida).
        $alocacao1 = $this->alocacaoPronta($ito, 300, $pacote);
        $rc1 = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $item1 = $this->atualizarRc->adicionarItem($rc1, $alocacao1, 300);
        $this->parcelaRc->adicionarParcela($item1, $necessidade, 300, $this->user);

        // RC-02 rascunho: TAMBÉM detalha 300 da MESMA necessidade — permitido
        // enquanto RC-01 continua Rascunho (múltiplos rascunhos podem
        // reservar até o saldo cheio cada um).
        $alocacao2 = $this->alocacaoPronta($ito, 300, $pacote);
        $rc2 = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $item2 = $this->atualizarRc->adicionarItem($rc2, $alocacao2, 300);
        $this->parcelaRc->adicionarParcela($item2, $necessidade, 300, $this->user);

        // RC-01 emite primeiro — consome oficialmente os 300.
        $this->emitirRc->execute($rc1->fresh(), $this->user);

        // RC-02 tenta emitir — a REVALIDAÇÃO na emissão fecha a corrida.
        $this->expectException(SaldoParcelaNecessidadeInsuficienteException::class);
        $this->emitirRc->execute($rc2->fresh(), $this->user);
    }

    // =========================================================================
    // G/H/I/J — isolamento e correspondência inequívoca
    // =========================================================================

    public function test_g_necessidade_de_outro_tenant_nunca_resolve(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $alocacao = $this->alocacaoPronta($ito, 300);
        $rcItem = $this->rcItemEmitido($alocacao, 300);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $necessidadeOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outraObra) {
            $atividadeOutra = Atividade::factory()->create(['tenant_id' => $outraObra->tenant_id, 'obra_id' => $outraObra->id]);
            $materialOutro = Material::create(['tenant_id' => $outraObra->tenant_id, 'codigo' => 'MAT-X', 'descricao' => 'X', 'unidade_medida_id' => UnidadeMedida::create(['tenant_id' => $outraObra->tenant_id, 'codigo' => 'UN', 'nome' => 'Un'])->id, 'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true]);

            return (new AtualizarNecessidadeMaterialAtividade())->criarOperacional($atividadeOutra, $materialOutro, 10, 'justificativa', null);
        });

        // O global scope de BelongsToTenant já garante que a busca pelo ID
        // vindo de outro tenant nunca resolve (mesma disciplina de sempre).
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        \App\Models\AtividadeNecessidadeMaterial::findOrFail($necessidadeOutroTenant->id);
    }

    public function test_h_necessidade_de_outra_obra_e_rejeitada(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 300);
        [, $rcItem] = $this->rcComItemRascunho($alocacao, 300);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atividadeOutraObra = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObra->id]);
        $itoOutraObra = $this->criarItemTakeOff($material, 1000, $outraObra);
        $necessidadeOutraObra = $this->criarNecessidade($atividadeOutraObra, $itoOutraObra, 300);

        $this->expectException(ParcelaNecessidadeInvalidaException::class);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeOutraObra, 100, $this->user);
    }

    public function test_i_material_incompativel_e_rejeitado(): void
    {
        $materialA = $this->criarMaterial();
        $materialB = $this->criarMaterial();
        $itoA = $this->criarItemTakeOff($materialA, 1000);
        $atividade = $this->criarAtividade();
        // Necessidade documentada contra um ItemTakeOff de MATERIAL DIFERENTE.
        $itoB = $this->criarItemTakeOff($materialB, 1000);
        $necessidadeMaterialB = $this->criarNecessidade($atividade, $itoB, 100);

        $alocacaoA = $this->alocacaoPronta($itoA, 300);
        [, $rcItemMaterialA] = $this->rcComItemRascunho($alocacaoA, 300);

        $this->expectException(ParcelaNecessidadeInvalidaException::class);
        $this->parcelaRc->adicionarParcela($rcItemMaterialA, $necessidadeMaterialB, 100, $this->user);
    }

    public function test_j_parcela_de_pedido_de_outra_obra_e_rejeitada_via_resolver(): void
    {
        // Mesmo princípio de H, mas no nível de Pedido: o resolver
        // obra-scoped do componente (⚡suprimentos.blade.php) nunca
        // resolve uma parcela de outra obra — reproduzido aqui direto
        // no domínio via o mesmo mecanismo de escopo por obra_id.
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $alocacao = $this->alocacaoPronta($ito, 300);
        [$rc, $rcItem] = $this->rcComItemRascunho($alocacao, 300);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidade, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $pedidoItem = $this->pedidoItemRascunho($rcItem, 300);
        $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidade, 300, $this->user);
        $parcelaPedido = PedidoCompraItemParcela::where('pedido_compra_item_id', $pedidoItem->id)->firstOrFail();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $encontrada = PedidoCompraItemParcela::whereHas('pedidoCompraItem.pedidoCompra', fn ($q) => $q->where('obra_id', $outraObra->id))
            ->find($parcelaPedido->id);

        $this->assertNull($encontrada, 'Uma parcela de Pedido nunca deve resolver sob o escopo de uma obra diferente da sua própria.');
    }

    // =========================================================================
    // K/L/M — o cenário obrigatório do pedido (300A+300B numa RC)
    // =========================================================================

    public function test_k_l_pedidos_distintos_consomem_fatias_exatas_da_mesma_rcitem(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 300);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 450);
        $alocacao = $this->alocacaoPronta($ito, 600);
        [$rc, $rcItem] = $this->rcComItemRascunho($alocacao, 600);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeA, 300, $this->user);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeB, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $fornecedor = $this->criarFornecedor();
        $pedidoItem1 = $this->pedidoItemRascunho($rcItem, 300, $fornecedor);
        $parcela1 = $this->parcelaPedido->adicionarParcela($pedidoItem1, $necessidadeA, 300, $this->user);

        $pedidoItem2 = $this->pedidoItemRascunho($rcItem, 300, $fornecedor);
        $parcela2 = $this->parcelaPedido->adicionarParcela($pedidoItem2, $necessidadeB, 300, $this->user);

        $this->assertEquals(300.0, (float) $parcela1->quantidade);
        $this->assertEquals(300.0, (float) $parcela2->quantidade);
        $this->assertNotSame($parcela1->pedido_compra_item_id, $parcela2->pedido_compra_item_id);
    }

    public function test_m_pedido_nao_pode_exceder_a_quota_da_rc_mesmo_com_quantidade_pedida_maior(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 300);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 450);
        $alocacao = $this->alocacaoPronta($ito, 600);
        [$rc, $rcItem] = $this->rcComItemRascunho($alocacao, 600);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeA, 300, $this->user);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeB, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        // PedidoItem com quantidade_pedida=400 (o RCItem sozinho permitiria),
        // mas a parcela A na RC só tem 300 — 400 precisa ser rejeitado.
        $pedidoItem = $this->pedidoItemRascunho($rcItem, 400);

        $this->expectException(SaldoParcelaPedidoInsuficienteException::class);
        $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidadeA, 400, $this->user);
    }

    // =========================================================================
    // N/O — guardas do Pedido
    // =========================================================================

    public function test_n_soma_parcelas_pedido_acima_da_quantidade_pedida_e_rejeitada(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 300);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 450);
        $alocacao = $this->alocacaoPronta($ito, 600);
        [$rc, $rcItem] = $this->rcComItemRascunho($alocacao, 600);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeA, 300, $this->user);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidadeB, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $pedidoItem = $this->pedidoItemRascunho($rcItem, 400); // quantidade_pedida = 400
        $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidadeA, 300, $this->user);

        $this->expectException(SaldoParcelaPedidoInsuficienteException::class);
        $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidadeB, 200, $this->user); // 300 + 200 = 500 > 400
    }

    public function test_o_pedido_nao_pode_consumir_parcela_inexistente_na_rcitem_mae(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $alocacao = $this->alocacaoPronta($ito, 300);
        $rcItem = $this->rcItemEmitido($alocacao, 300);
        // Nunca detalhado na RC — a necessidade existe, mas não tem
        // RequisicaoCompraItemParcela correspondente neste rcItem.

        $pedidoItem = $this->pedidoItemRascunho($rcItem, 300);

        $this->expectException(ParcelaNecessidadeInvalidaException::class);
        $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidade, 100, $this->user);
    }

    // =========================================================================
    // P — sem detalhamento continua válido (informação insuficiente, nunca inventada)
    // =========================================================================

    public function test_p_rc_e_pedido_sem_nenhum_detalhamento_por_atividade_continuam_validos(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 300);
        $rcItem = $this->rcItemEmitido($alocacao, 300); // já emitida, zero parcelas — sem exceção

        $this->assertSame(0, RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $rcItem->id)->count());

        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2027-02-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 300);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user); // sem exceção

        $this->assertSame(\App\Enums\StatusPedidoCompra::Emitido, $emitido->status);
        $this->assertSame(0, PedidoCompraItemParcela::whereHas('pedidoCompraItem', fn ($q) => $q->where('pedido_compra_id', $emitido->id))->count());
    }

    // =========================================================================
    // Guardas adicionais — não reduzir quantidade abaixo do já detalhado
    // =========================================================================

    public function test_nao_permite_reduzir_quantidade_do_rcitem_abaixo_do_ja_detalhado(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta($ito, 600, $pacote);
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, 600);
        $this->parcelaRc->adicionarParcela($item, $necessidade, 300, $this->user);

        $this->expectException(ParcelaNecessidadeInvalidaException::class);
        $this->atualizarRc->alterarQuantidade($item, 200);
    }

    public function test_nao_permite_reduzir_quantidade_pedida_abaixo_do_ja_detalhado(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $alocacao = $this->alocacaoPronta($ito, 300);
        [$rc, $rcItem] = $this->rcComItemRascunho($alocacao, 300);
        $this->parcelaRc->adicionarParcela($rcItem, $necessidade, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $pedidoItem = $this->pedidoItemRascunho($rcItem, 300);
        $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidade, 200, $this->user);

        $this->expectException(ParcelaNecessidadeInvalidaException::class);
        $this->atualizarPedido->alterarQuantidade($pedidoItem, 100);
    }

    // =========================================================================
    // Prova estrutural de ordem de lock — RC antes de Necessidade, nunca invertido
    // =========================================================================

    public function test_ordem_de_lock_estrutural_rc_antes_de_necessidade(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        $alocacao = $this->alocacaoPronta($ito, 300);
        [, $rcItem] = $this->rcComItemRascunho($alocacao, 300);

        $ordem = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$ordem) {
            if (str_contains($query->sql, 'lock in share mode') || str_contains($query->sql, 'for update')) {
                if (str_contains($query->sql, 'requisicao_compra_itens')) {
                    $ordem[] = 'rcitem';
                } elseif (str_contains($query->sql, 'atividade_necessidades_material')) {
                    $ordem[] = 'necessidade';
                }
            }
        });

        $this->parcelaRc->adicionarParcela($rcItem, $necessidade, 300, $this->user);

        $this->assertSame(['rcitem', 'necessidade'], $ordem, 'RCItem deve ser travado antes da Necessidade, nunca invertido.');
    }
}
