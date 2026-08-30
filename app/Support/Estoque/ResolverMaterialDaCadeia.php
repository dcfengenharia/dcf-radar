<?php

namespace App\Support\Estoque;

use App\Models\AlocacaoRequisicaoPacote;
use App\Models\ItemTakeOff;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoPlanejamentoItem;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.1 — navega a cadeia histórica completa do Ciclo 19
 * (RecebimentoPedido -> PedidoCompraItem -> RequisicaoCompraItem ->
 * AlocacaoRequisicaoPacote -> RequisicaoPlanejamentoItem -> ItemTakeOff)
 * pra resolver de qual ItemTakeOff (e, por extensão, de qual Material)
 * um recebimento físico se origina.
 *
 * Sempre via queries explícitas encadeadas (::find()/::where()->value()),
 * NUNCA travessia de relação (->relacao) — os models aqui nunca vêm com
 * nada eager-loaded quando chamados de dentro de uma Action/transação, e
 * lazy loading está bloqueado fora de produção (mesmo cuidado já
 * documentado em EmitirPedidoCompra/RegistrarRecebimentoPedido).
 *
 * 5 queries pequenas, sempre pra resolver 1 único RecebimentoPedido por
 * chamada — nunca uma operação em lote (uso pontual dentro de
 * RegistrarEntradaEstoque, nunca em listagem).
 */
class ResolverMaterialDaCadeia
{
    public static function itemTakeOff(RecebimentoPedido $recebimento): ?ItemTakeOff
    {
        $pedidoCompraItem = PedidoCompraItem::find($recebimento->pedido_compra_item_id);
        if (! $pedidoCompraItem) {
            return null;
        }

        $requisicaoCompraItem = RequisicaoCompraItem::find($pedidoCompraItem->requisicao_compra_item_id);
        if (! $requisicaoCompraItem) {
            return null;
        }

        $alocacao = AlocacaoRequisicaoPacote::find($requisicaoCompraItem->alocacao_requisicao_pacote_id);
        if (! $alocacao) {
            return null;
        }

        $requisicaoItem = RequisicaoPlanejamentoItem::find($alocacao->requisicao_planejamento_item_id);
        if (! $requisicaoItem) {
            return null;
        }

        return ItemTakeOff::find($requisicaoItem->item_take_off_id);
    }

    /**
     * Ciclo 20, Etapa 20.3.CORREÇÃO — versão em LOTE de itemTakeOff(),
     * pra fechar o Achado B de N+1 real em
     * ⚡estoque.blade.php::recebimentosPendentes() (auditoria adversarial
     * 20.3, Seção 23/24: ~166 queries pra renderizar com 20 materiais).
     * `itemTakeOff()` acima NUNCA é alterado — continua sendo usado por
     * App\Actions\Estoque\RegistrarEntradaEstoque (uso pontual de 1
     * linha, dentro de uma transação, onde 5 queries pequenas são
     * aceitáveis e nunca escalam). Este método é EXCLUSIVO de listagens.
     *
     * Sempre 5 queries TOTAIS (uma por hop da cadeia, via `whereIn`),
     * nunca 5×N — mesmo padrão de lote já usado em
     * App\Support\Suprimentos\ConciliacaoDestinacao::porPares().
     *
     * @param  Collection<int, RecebimentoPedido>  $recebimentos
     * @return Collection<string, ?ItemTakeOff> chave = recebimento_pedido_id
     */
    public static function itemTakeOffEmLote(Collection $recebimentos): Collection
    {
        if ($recebimentos->isEmpty()) {
            return collect();
        }

        $pedidoCompraItens = PedidoCompraItem::whereIn('id', $recebimentos->pluck('pedido_compra_item_id')->filter()->unique())
            ->get()->keyBy('id');

        $requisicaoCompraItens = RequisicaoCompraItem::whereIn('id', $pedidoCompraItens->pluck('requisicao_compra_item_id')->filter()->unique())
            ->get()->keyBy('id');

        $alocacoes = AlocacaoRequisicaoPacote::whereIn('id', $requisicaoCompraItens->pluck('alocacao_requisicao_pacote_id')->filter()->unique())
            ->get()->keyBy('id');

        $requisicaoItens = RequisicaoPlanejamentoItem::whereIn('id', $alocacoes->pluck('requisicao_planejamento_item_id')->filter()->unique())
            ->get()->keyBy('id');

        $itensTakeOff = ItemTakeOff::whereIn('id', $requisicaoItens->pluck('item_take_off_id')->filter()->unique())
            ->get()->keyBy('id');

        return $recebimentos->mapWithKeys(function (RecebimentoPedido $recebimento) use (
            $pedidoCompraItens, $requisicaoCompraItens, $alocacoes, $requisicaoItens, $itensTakeOff
        ) {
            $pedidoCompraItem = $pedidoCompraItens->get($recebimento->pedido_compra_item_id);
            $requisicaoCompraItem = $pedidoCompraItem ? $requisicaoCompraItens->get($pedidoCompraItem->requisicao_compra_item_id) : null;
            $alocacao = $requisicaoCompraItem ? $alocacoes->get($requisicaoCompraItem->alocacao_requisicao_pacote_id) : null;
            $requisicaoItem = $alocacao ? $requisicaoItens->get($alocacao->requisicao_planejamento_item_id) : null;
            $itemTakeOff = $requisicaoItem ? $itensTakeOff->get($requisicaoItem->item_take_off_id) : null;

            return [$recebimento->id => $itemTakeOff];
        });
    }
}
