<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AnexarDocumentoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarProvenienciaAdjudicacaoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoDocumentoRequisicaoCompra;
use App\Exceptions\AdjudicacaoConsumidaPorPedidoException;
use App\Exceptions\AtribuicaoAdjudicacaoObrigatoriaException;
use App\Exceptions\PedidoCompraEmissaoInvalidaException;
use App\Exceptions\ProvenienciaAdjudicacaoInvalidaException;
use App\Exceptions\RequisicaoCompraAdjudicacaoInvalidaException;
use App\Exceptions\RequisicaoCompraImutavelException;
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
use App\Models\PedidoCompraItemAdjudicacao;
use App\Models\RequisicaoCompra;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FECHAMENTO ADVERSARIAL — ETAPA 2 / ADJUDICAÇÃO + PEDIDO + DOSSIÊ DA RC.
 *
 * Prova, com cenários A-H do pedido de fechamento, que a correção
 * (`PedidoCompraItemAdjudicacao`, a ponte explícita de proveniência)
 * resolve o gap identificado pela auditoria adversarial: "1 adjudicação
 * -> N Pedidos" agora é deterministicamente reconstruível — inclusive
 * quando o MESMO fornecedor tem 2+ adjudicações sobre o MESMO alvo,
 * cenário em que a agregação por fornecedor sozinha nunca conseguia
 * distinguir qual decisão originou qual Pedido.
 */
class AdjudicacaoProvenienciaFechamentoTest extends TestCase
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
    private CriarAdjudicacaoRequisicaoCompra $criarAdjudicacao;
    private AtualizarAdjudicacaoRequisicaoCompra $atualizarAdjudicacao;
    private AtualizarProvenienciaAdjudicacaoPedidoCompra $atualizarProveniencia;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

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
        $this->criarAdjudicacao = new CriarAdjudicacaoRequisicaoCompra();
        $this->atualizarAdjudicacao = new AtualizarAdjudicacaoRequisicaoCompra();
        $this->atualizarProveniencia = new AtualizarProvenienciaAdjudicacaoPedidoCompra();
    }

    // ---- helpers (mesmo padrão de AdjudicacaoRequisicaoCompraTest) ----

    private function criarAtividade(): Atividade
    {
        return Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Cabo 3x25',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarItemTakeOff(Material $material, float $quantidade): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
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

    private function criarPacote(): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid(), 'codigo' => 'PAC' . uniqid()]);
    }

    private function criarNecessidade(Atividade $atividade, ItemTakeOff $ito, float $quantidade): AtividadeNecessidadeMaterial
    {
        return $this->necessidadeAction->criarTakeOff($atividade, $ito, $quantidade, $this->user);
    }

    private function alocacaoPronta(ItemTakeOff $ito, float $quantidade, ?ItemSuprimento $pacote = null): AlocacaoRequisicaoPacote
    {
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $ito->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote(), $quantidade);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    private function criarFornecedor(string $nome = 'Fornecedor'): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => $nome . ' ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
    }

    /** RC Emitida com 1 item detalhado por Atividade (1 parcela). */
    private function rcEmitidaComParcela(ItemTakeOff $ito, AtividadeNecessidadeMaterial $necessidade, float $quantidadeRc, float $quantidadeParcela): array
    {
        $alocacao = $this->alocacaoPronta($ito, $quantidadeRc);
        $rc = $this->criarRc->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidadeRc);
        $parcela = $this->parcelaRc->adicionarParcela($item, $necessidade, $quantidadeParcela, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        return [$rc->fresh(), $item->fresh(), $parcela->fresh()];
    }

    /** RC Emitida com 1 item SEM nenhuma distribuição por Atividade. */
    private function rcEmitidaSemParcela(AlocacaoRequisicaoPacote $alocacao, float $quantidade): array
    {
        $rc = $this->criarRc->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        return [$rc->fresh(), $item->fresh()];
    }

    // =========================================================================
    // A/B — 2 adjudicações do MESMO fornecedor para a MESMA parcela +
    // proveniência precisa via bridge (a questão central do fechamento)
    // =========================================================================

    public function test_a_duas_adjudicacoes_do_mesmo_fornecedor_sao_distinguiveis_pela_bridge(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 100, 100);
        $fornecedorX = $this->criarFornecedor('X');

        // Adjudicação A: X=40. Consumida totalmente por P1=40.
        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedorX, 'Decisão A', null, null, $this->user);
        $itemAdjA = $this->atualizarAdjudicacao->adicionarItem($adjA, $item, $parcela, 40, $this->user);

        $pedido1 = $this->criarPedido->execute($rc, $fornecedorX, '2027-01-15', null, null, null, $this->user);
        $pedidoItem1 = $this->atualizarPedido->adicionarItem($pedido1, $item->fresh(), 40);
        $parcelaPedido1 = $this->parcelaPedido->adicionarParcela($pedidoItem1, $necessidade, 40, $this->user);
        $bridge1 = $this->atualizarProveniencia->adicionarConsumo($pedidoItem1->fresh(), $parcelaPedido1->fresh(), $itemAdjA->fresh(), 40, $this->user);
        $this->emitirPedido->execute($pedido1->fresh(), $this->user);

        // Adjudicação A permanece Ativa (já consumida por Pedido Emitido,
        // nunca poderia ser cancelada — mesma regra de `test_d` abaixo).
        // Uma 2ª decisão INDEPENDENTE, do MESMO fornecedor X, sobre o
        // restante da parcela (60 disponíveis) — cenário real do
        // fechamento: 2 adjudicações Ativas simultâneas do mesmo
        // fornecedor sobre o mesmo alvo.
        $adjB = $this->criarAdjudicacao->execute($rc->fresh(), $fornecedorX, 'Decisão B', null, null, $this->user);
        $itemAdjB = $this->atualizarAdjudicacao->adicionarItem($adjB, $item->fresh(), $parcela->fresh(), 40, $this->user);

        $pedido2 = $this->criarPedido->execute($rc->fresh(), $fornecedorX, '2027-02-01', null, null, null, $this->user);
        $pedidoItem2 = $this->atualizarPedido->adicionarItem($pedido2, $item->fresh(), 40);
        $parcelaPedido2 = $this->parcelaPedido->adicionarParcela($pedidoItem2, $necessidade, 40, $this->user);
        $bridge2 = $this->atualizarProveniencia->adicionarConsumo($pedidoItem2->fresh(), $parcelaPedido2->fresh(), $itemAdjB->fresh(), 40, $this->user);
        $this->emitirPedido->execute($pedido2->fresh(), $this->user);

        // Prova determinística: P1 aponta pra A, P2 aponta pra B — nunca
        // resolvido por inferência (FIFO/mais recente/proporcionalidade).
        $this->assertSame($itemAdjA->id, $bridge1->fresh()->requisicao_compra_adjudicacao_item_id);
        $this->assertSame($itemAdjB->id, $bridge2->fresh()->requisicao_compra_adjudicacao_item_id);
        $this->assertNotSame($bridge1->requisicao_compra_adjudicacao_item_id, $bridge2->requisicao_compra_adjudicacao_item_id);

        // A quantidade oficialmente consumida de CADA adjudicação (via a
        // bridge, nunca por agregação por fornecedor) bate exatamente.
        $this->assertEquals(40.0, $itemAdjA->fresh()->quantidadeConsumidaViaBridge());
        $this->assertEquals(40.0, $itemAdjB->fresh()->quantidadeConsumidaViaBridge());
    }

    public function test_b_pedido_preserva_qual_adjudicacao_especifica_o_originou(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedor, 'A', null, null, $this->user);
        $itemAdjA = $this->atualizarAdjudicacao->adicionarItem($adjA, $item, null, 100, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 100);
        $bridge = $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdjA->fresh(), 100, $this->user);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        // A pergunta "este Pedido veio de qual adjudicação?" agora tem
        // resposta determinística via a ponte — nunca "desconhecida".
        $consumos = PedidoCompraItemAdjudicacao::where('pedido_compra_item_id', $pedidoItem->id)->get();
        $this->assertCount(1, $consumos);
        $this->assertSame($itemAdjA->id, $consumos->first()->requisicao_compra_adjudicacao_item_id);
        $this->assertSame($bridge->id, $consumos->first()->id);
    }

    // =========================================================================
    // C/D — adjudicação parcialmente consumida: reduzir/cancelar bloqueado
    // com precisão de linha, nunca por agregação
    // =========================================================================

    public function test_c_adjudicacao_parcialmente_consumida_nao_pode_reduzir_abaixo_do_consumido(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 100, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 60);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdj->fresh(), 60, $this->user);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        // 100 adjudicados, 60 consumidos via bridge — reduzir pra 40 seria
        // deixar 20 do já-consumido sem cobertura. Bloqueado.
        $this->expectException(AdjudicacaoConsumidaPorPedidoException::class);
        $this->atualizarAdjudicacao->alterarQuantidadeItem($itemAdj->fresh(), 40, $this->user);
    }

    public function test_c2_adjudicacao_parcialmente_consumida_pode_reduzir_ate_o_consumido(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 100, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 60);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdj->fresh(), 60, $this->user);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        // Reduzir exatamente até o consumido (60) é permitido — nunca
        // abaixo dele.
        $this->atualizarAdjudicacao->alterarQuantidadeItem($itemAdj->fresh(), 60, $this->user);
        $this->assertEquals(60.0, $itemAdj->fresh()->quantidade);
    }

    public function test_d_tentativa_de_cancelar_a_decisao_inteira_com_consumo_parcial_e_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 100, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 60);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdj->fresh(), 60, $this->user);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        // Cancelar a decisão INTEIRA (header) enquanto 60 já está
        // formalmente contratado é uma contradição semântica — bloqueado,
        // "Status=Cancelada" nunca convive com consumo real já feito.
        $this->expectException(AdjudicacaoConsumidaPorPedidoException::class);
        $this->atualizarAdjudicacao->cancelar($adjudicacao->fresh(), $this->user, 'Tentativa de reconsiderar tudo');

        // A decisão continua Ativa, o consumo permanece intacto.
        $this->assertTrue($adjudicacao->fresh()->estaAtiva());
        $this->assertEquals(60.0, $itemAdj->fresh()->quantidadeConsumidaViaBridge());
    }

    // =========================================================================
    // E/F — transição item-level <-> parcela após adjudicação/Pedido:
    // prova de que é estruturalmente impossível (RC nunca volta a Rascunho)
    // =========================================================================

    public function test_e_apos_adjudicacao_rc_nunca_pode_ganhar_parcela_nova_pois_ja_nao_e_rascunho(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $atividade = $this->criarAtividade();
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        // Adjudicação item-level (sem parcela) já registrada.
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 100, $this->user);

        // Tentar criar uma RCItemParcela AGORA (RC já Emitida, nunca mais
        // Rascunho) é bloqueado pela própria fronteira de lifecycle da
        // Etapa 1 — nunca uma checagem nova desta correção, só a prova
        // formal de que o cenário temido (item adjudicado sem parcela,
        // depois ganhando parcela) é INALCANÇÁVEL.
        $this->expectException(RequisicaoCompraImutavelException::class);
        $this->parcelaRc->adicionarParcela($item->fresh(), $necessidade, 100, $this->user);
    }

    public function test_f_apos_adjudicacao_por_parcela_rc_nunca_permite_remover_a_parcela(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 100, 100);
        $fornecedor = $this->criarFornecedor();

        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcela, 100, $this->user);

        // Remover o detalhamento por Atividade agora (RC já Emitida) é
        // bloqueado pela mesma fronteira — nunca alcançável a ambiguidade
        // "adjudicação por parcela cuja parcela desapareceu depois".
        $this->expectException(RequisicaoCompraImutavelException::class);
        $this->parcelaRc->removerParcela($parcela->fresh());
    }

    // =========================================================================
    // G — anexo de RC A não pode fundamentar adjudicação de RC B
    // (server-side, explícito)
    // =========================================================================

    public function test_g_anexo_de_outra_rc_e_rejeitado_ao_fundamentar_adjudicacao(): void
    {
        $materialA = $this->criarMaterial();
        $itoA = $this->criarItemTakeOff($materialA, 500);
        $alocacaoA = $this->alocacaoPronta($itoA, 100);
        [$rcA] = $this->rcEmitidaSemParcela($alocacaoA, 100);

        $materialB = $this->criarMaterial();
        $itoB = $this->criarItemTakeOff($materialB, 500);
        $alocacaoB = $this->alocacaoPronta($itoB, 100);
        [$rcB] = $this->rcEmitidaSemParcela($alocacaoB, 100);

        // Anexo pertence à RC B.
        $anexoDeB = (new AnexarDocumentoRequisicaoCompra())->execute(
            $rcB,
            ['tipo_documento' => TipoDocumentoRequisicaoCompra::Proposta->value],
            UploadedFile::fake()->create('proposta.pdf', 100),
            null,
            $this->user
        );

        $fornecedor = $this->criarFornecedor();

        // Tentar fundamentar uma adjudicação da RC A com o anexo da RC B
        // é rejeitado server-side — nunca aceito silenciosamente.
        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->criarAdjudicacao->execute($rcA->fresh(), $fornecedor, 'Tentando usar anexo de outra RC', null, $anexoDeB, $this->user);
    }

    // =========================================================================
    // H — metadados de Proposta V1 nunca alteram o Pedido automaticamente
    // =========================================================================

    public function test_h_metadados_da_proposta_nunca_alteram_pedido_automaticamente(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $anexoProposta = (new AnexarDocumentoRequisicaoCompra())->execute(
            $rc,
            [
                'tipo_documento' => TipoDocumentoRequisicaoCompra::Proposta->value,
                'descricao' => 'Proposta com valor/prazo de referência',
                'valor_total_referencia' => 9999.99,
                'prazo_referencia' => 45,
                'validade_ate' => now()->addDays(30)->toDateString(),
            ],
            UploadedFile::fake()->create('proposta.pdf', 100),
            $fornecedor,
            $this->user
        );

        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'Baseado na proposta', null, $anexoProposta, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 100, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 100);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdj->fresh(), 100, $this->user);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        // data_prevista_entrega continua exatamente a informada
        // manualmente na criação do Pedido — nunca sobrescrita pelo
        // prazo_referencia (45 dias) do anexo de Proposta.
        $this->assertSame('2027-01-15', $emitido->data_prevista_entrega->toDateString());
        // Nenhum campo de preço/valor existe no domínio de Pedido — o
        // valor de referência da proposta nunca "vaza" pra lugar nenhum
        // fora do próprio anexo.
        $this->assertArrayNotHasKey('valor_total', $emitido->getAttributes());
        $this->assertArrayNotHasKey('valor_total_referencia', $emitido->getAttributes());
    }

    // =========================================================================
    // Emissão bloqueada quando atribuição de proveniência está incompleta
    // (fecha o gap sozinho, sem precisar de reconstrução heurística)
    // =========================================================================

    public function test_emissao_bloqueada_quando_atribuicao_de_origem_esta_incompleta(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 100, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 100);
        // Atribui só 60 dos 100 pedidos — proveniência incompleta.
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdj->fresh(), 60, $this->user);

        $this->expectException(AtribuicaoAdjudicacaoObrigatoriaException::class);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
    }

    public function test_emissao_permitida_quando_atribuicao_vem_de_duas_adjudicacoes_do_mesmo_fornecedor(): void
    {
        // Seção 5 do fechamento — 1 PedidoItem consolidando 2 adjudicações
        // do MESMO fornecedor (40+60=100) deve funcionar, preservando os
        // dois vínculos separadamente.
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedor, 'A=40', null, null, $this->user);
        $itemAdjA = $this->atualizarAdjudicacao->adicionarItem($adjA, $item, null, 40, $this->user);
        $adjB = $this->criarAdjudicacao->execute($rc->fresh(), $fornecedor, 'B=60', null, null, $this->user);
        $itemAdjB = $this->atualizarAdjudicacao->adicionarItem($adjB, $item->fresh(), null, 60, $this->user);

        $pedido = $this->criarPedido->execute($rc->fresh(), $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 100);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdjA->fresh(), 40, $this->user);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdjB->fresh(), 60, $this->user);

        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(\App\Enums\StatusPedidoCompra::Emitido, $emitido->status);
        $this->assertEquals(40.0, $itemAdjA->fresh()->quantidadeConsumidaViaBridge());
        $this->assertEquals(60.0, $itemAdjB->fresh()->quantidadeConsumidaViaBridge());
    }

    public function test_bridge_rejeita_adjudicacao_de_fornecedor_diferente_do_pedido(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedorA = $this->criarFornecedor('A');
        $fornecedorB = $this->criarFornecedor('B');

        // Duas adjudicações independentes sobre o MESMO item, uma por
        // fornecedor — cada Pedido só cabe na quota do seu próprio
        // fornecedor no draft, então o guard de fornecedor da bridge
        // precisa ser exercitado isoladamente, nunca mascarado pelo teto
        // agregado (que aqui passa normalmente pros 50 de B).
        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedorA, 'A', null, null, $this->user);
        $itemAdjA = $this->atualizarAdjudicacao->adicionarItem($adjA, $item, null, 50, $this->user);
        $adjB = $this->criarAdjudicacao->execute($rc->fresh(), $fornecedorB, 'B', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjB, $item->fresh(), null, 50, $this->user);

        // Pedido é do fornecedor B (dentro da própria quota de B), mas a
        // adjudicação referenciada na bridge é a do fornecedor A.
        $pedido = $this->criarPedido->execute($rc->fresh(), $fornecedorB, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 50);

        $this->expectException(ProvenienciaAdjudicacaoInvalidaException::class);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdjA->fresh(), 50, $this->user);
    }

    public function test_bridge_e_livre_de_atribuicao_quando_alvo_nunca_teve_adjudicacao(): void
    {
        // Compatibilidade — RC/Pedido sem nenhuma adjudicação continuam
        // emitindo normalmente, sem exigir nenhuma bridge.
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 100);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(\App\Enums\StatusPedidoCompra::Emitido, $emitido->status);
    }

    /**
     * TETO 4/5 (Fechamento Probatório Final, Seção 5/6) — dois Pedidos
     * distintos, cada um em Rascunho, atribuem bridge INTEGRAL contra a
     * MESMA linha de adjudicação (A=40) — a checagem de saldo na criação
     * da bridge só conta consumo de Pedido JÁ EMITIDO, então os dois
     * rascunhos concorrentes conseguem reservar os 40 cada um (mesmo
     * padrão de compatibilidade já documentado pro teto agregado — "só a
     * emissão revalida de verdade e serializa"). Achado objetivo real
     * (confirmado empiricamente ANTES da correção: o 2º Pedido emitia
     * com sucesso, estourando a capacidade de A) — corrigido com a
     * revalidação por linha de adjudicação em `EmitirPedidoCompra`.
     */
    public function test_i_dois_pedidos_nao_podem_estourar_a_mesma_linha_de_adjudicacao_na_emissao(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 100);
        $fornecedor = $this->criarFornecedor();

        // A=40 + B=60 — agregado ao fornecedor = 100, batendo com o
        // RCItem inteiro (o teto agregado por fornecedor NUNCA disparado
        // sozinho, de propósito, pra isolar o teto POR LINHA).
        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedor, 'A=40', null, null, $this->user);
        $itemAdjA = $this->atualizarAdjudicacao->adicionarItem($adjA, $item, null, 40, $this->user);
        $adjB = $this->criarAdjudicacao->execute($rc->fresh(), $fornecedor, 'B=60', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjB, $item->fresh(), null, 60, $this->user);

        // P1 = 40, atribuído integralmente a A.
        $pedido1 = $this->criarPedido->execute($rc->fresh(), $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem1 = $this->atualizarPedido->adicionarItem($pedido1, $item->fresh(), 40);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem1->fresh(), null, $itemAdjA->fresh(), 40, $this->user);

        // P2 = 40, TAMBÉM atribuído integralmente a A — criado enquanto
        // P1 ainda é Rascunho, então a checagem de saldo da bridge (só
        // conta Pedido Emitido) permite os dois.
        $pedido2 = $this->criarPedido->execute($rc->fresh(), $fornecedor, '2027-01-20', null, null, null, $this->user);
        $pedidoItem2 = $this->atualizarPedido->adicionarItem($pedido2, $item->fresh(), 40);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem2->fresh(), null, $itemAdjA->fresh(), 40, $this->user);

        $this->emitirPedido->execute($pedido1->fresh(), $this->user);

        // Depois de P1 Emitido, A já tem 40/40 oficialmente consumidos —
        // P2 emitir também consumiria mais 40 da MESMA linha, estourando
        // a capacidade real de A (40), mesmo com o teto agregado por
        // fornecedor (100) longe de esgotar.
        $this->expectException(\App\Exceptions\SaldoAdjudicacaoInsuficienteException::class);
        $this->emitirPedido->execute($pedido2->fresh(), $this->user);
    }
}
