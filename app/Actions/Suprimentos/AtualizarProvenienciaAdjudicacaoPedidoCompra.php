<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusPedidoCompra;
use App\Exceptions\AdjudicacaoConsumidaPorPedidoException;
use App\Exceptions\PedidoCompraImutavelException;
use App\Exceptions\ProvenienciaAdjudicacaoInvalidaException;
use App\Exceptions\SaldoAdjudicacaoInsuficienteException;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItem;
use App\Models\PedidoCompraItemAdjudicacao;
use App\Models\PedidoCompraItemParcela;
use App\Models\RequisicaoCompraAdjudicacaoItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 2.CORREÇÃO — único ponto de escrita da ponte explícita de
 * proveniência entre um consumo de Pedido (item ou parcela) e a
 * `RequisicaoCompraAdjudicacaoItem` específica de onde ele veio. Fecha o
 * gap de proveniência histórica identificado na auditoria adversarial do
 * Fechamento da Etapa 2: sem esta ponte, "1 adjudicação -> N Pedidos" só
 * era reconstruível por agregação quantitativa (RC+fornecedor+item/
 * parcela+quantidade), nunca deterministicamente por decisão específica.
 *
 * **Ordem de lock — ESTENDE o total order já estabelecido**
 * (`RequisicaoCompraItem → RequisicaoCompra → RequisicaoCompraItemParcela
 * → RequisicaoCompraAdjudicacao`, Etapa 2): `PedidoCompraItem →
 * PedidoCompra [garantirRascunho] → [PedidoCompraItemParcela, se dado] →
 * RequisicaoCompraAdjudicacaoItem` — o recurso da Adjudicação (já o mais
 * "novo" da árvore RC) continua sendo travado por ÚLTIMO, mesmo do lado
 * do Pedido — nunca inverte a ordem já estabelecida em nenhuma direção.
 *
 * **Proveniência só é mutável enquanto o Pedido é Rascunho** (mesmo
 * padrão de `AtualizarRascunhoPedidoCompra`/
 * `AtualizarDistribuicaoParcelaPedidoCompra`) — um Pedido Emitido nunca
 * tem sua atribuição de origem reescrita.
 *
 * **1 PedidoItem pode consumir N adjudicações do MESMO fornecedor**
 * (Seção 5 do pedido de fechamento — decisão explícita): nada aqui
 * impõe cardinalidade 1:1 — várias linhas de `PedidoCompraItemAdjudicacao`
 * podem apontar pro MESMO `pedido_compra_item_id`/`pedido_compra_item_parcela_id`,
 * cada uma referenciando uma `RequisicaoCompraAdjudicacaoItem` diferente,
 * desde que a soma nunca ultrapasse a quantidade do alvo do Pedido nem o
 * saldo não-consumido de cada adjudicação de origem.
 */
class AtualizarProvenienciaAdjudicacaoPedidoCompra
{
    public function adicionarConsumo(
        PedidoCompraItem $pedidoItem,
        ?PedidoCompraItemParcela $parcelaPedido,
        RequisicaoCompraAdjudicacaoItem $adjudicacaoItem,
        float $quantidade,
        User $usuario,
    ): PedidoCompraItemAdjudicacao {
        return DB::transaction(function () use ($pedidoItem, $parcelaPedido, $adjudicacaoItem, $quantidade, $usuario) {
            $pedidoItem = PedidoCompraItem::whereKey($pedidoItem->id)->lockForUpdate()->firstOrFail();
            $pedido = PedidoCompra::whereKey($pedidoItem->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $parcelaTravada = $parcelaPedido
                ? PedidoCompraItemParcela::whereKey($parcelaPedido->id)->lockForUpdate()->firstOrFail()
                : null;

            $adjudicacaoItem = RequisicaoCompraAdjudicacaoItem::whereKey($adjudicacaoItem->id)->lockForUpdate()->firstOrFail();

            $this->garantirParcelaDoPedidoItem($pedidoItem, $parcelaTravada);
            $this->garantirMesmoRcItem($pedidoItem, $adjudicacaoItem);
            $this->garantirGranularidadeCompativel($parcelaTravada, $adjudicacaoItem);
            $this->garantirFornecedorCompativel($pedido, $adjudicacaoItem);
            $this->garantirAdjudicacaoAtiva($adjudicacaoItem);
            $this->garantirQuantidadePositiva($quantidade);
            $this->garantirSaldoAdjudicacaoItem($adjudicacaoItem, $quantidade, excluirConsumoId: null);
            $this->garantirNaoUltrapassaAlvoPedido($pedidoItem, $parcelaTravada, $quantidade, excluirConsumoId: null);

            return PedidoCompraItemAdjudicacao::create([
                'pedido_compra_item_id' => $pedidoItem->id,
                'pedido_compra_item_parcela_id' => $parcelaTravada?->id,
                'requisicao_compra_adjudicacao_item_id' => $adjudicacaoItem->id,
                'quantidade' => $quantidade,
                'created_by_id' => $usuario->id,
            ]);
        });
    }

    public function alterarQuantidade(PedidoCompraItemAdjudicacao $consumo, float $novaQuantidade): void
    {
        DB::transaction(function () use ($consumo, $novaQuantidade) {
            $pedidoItem = PedidoCompraItem::whereKey($consumo->pedido_compra_item_id)->lockForUpdate()->firstOrFail();
            $pedido = PedidoCompra::whereKey($pedidoItem->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $parcelaTravada = $consumo->pedido_compra_item_parcela_id
                ? PedidoCompraItemParcela::whereKey($consumo->pedido_compra_item_parcela_id)->lockForUpdate()->firstOrFail()
                : null;

            $adjudicacaoItem = RequisicaoCompraAdjudicacaoItem::whereKey($consumo->requisicao_compra_adjudicacao_item_id)->lockForUpdate()->firstOrFail();
            $consumo = PedidoCompraItemAdjudicacao::whereKey($consumo->id)->lockForUpdate()->firstOrFail();

            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->garantirSaldoAdjudicacaoItem($adjudicacaoItem, $novaQuantidade, excluirConsumoId: $consumo->id);
            $this->garantirNaoUltrapassaAlvoPedido($pedidoItem, $parcelaTravada, $novaQuantidade, excluirConsumoId: $consumo->id);

            $consumo->update(['quantidade' => $novaQuantidade]);
        });
    }

    public function removerConsumo(PedidoCompraItemAdjudicacao $consumo): void
    {
        DB::transaction(function () use ($consumo) {
            $pedidoItem = PedidoCompraItem::whereKey($consumo->pedido_compra_item_id)->lockForUpdate()->firstOrFail();
            $pedido = PedidoCompra::whereKey($pedidoItem->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $consumo = PedidoCompraItemAdjudicacao::whereKey($consumo->id)->lockForUpdate()->firstOrFail();

            $consumo->delete();
        });
    }

    private function garantirRascunho(PedidoCompra $pedido): void
    {
        if ($pedido->status !== StatusPedidoCompra::Rascunho) {
            throw new PedidoCompraImutavelException(
                'Este Pedido/Ordem de Compra já foi emitido — não é possível alterar sua atribuição de origem (adjudicação).'
            );
        }
    }

    private function garantirParcelaDoPedidoItem(PedidoCompraItem $pedidoItem, ?PedidoCompraItemParcela $parcela): void
    {
        if ($parcela && $parcela->pedido_compra_item_id !== $pedidoItem->id) {
            throw new ProvenienciaAdjudicacaoInvalidaException('Esta parcela não pertence a este item do Pedido.');
        }
    }

    private function garantirMesmoRcItem(PedidoCompraItem $pedidoItem, RequisicaoCompraAdjudicacaoItem $adjudicacaoItem): void
    {
        if ($pedidoItem->requisicao_compra_item_id !== $adjudicacaoItem->requisicao_compra_item_id) {
            throw new ProvenienciaAdjudicacaoInvalidaException(
                'Esta adjudicação não corresponde ao mesmo item da Requisição de Compra deste Pedido.'
            );
        }
    }

    /**
     * Mesma regra de mutual-exclusividade da Etapa 2: item detalhado por
     * Atividade exige uma adjudicação por parcela (correspondente à MESMA
     * necessidade); item sem detalhamento exige uma adjudicação direta no
     * item (parcela nula dos dois lados).
     */
    private function garantirGranularidadeCompativel(?PedidoCompraItemParcela $parcelaPedido, RequisicaoCompraAdjudicacaoItem $adjudicacaoItem): void
    {
        if ($parcelaPedido) {
            if (! $adjudicacaoItem->requisicao_compra_item_parcela_id) {
                throw new ProvenienciaAdjudicacaoInvalidaException(
                    'Esta adjudicação foi feita diretamente sobre o item, sem detalhamento por Atividade — não pode ser atribuída a uma parcela do Pedido.'
                );
            }

            $mesmaNecessidade = $adjudicacaoItem->parcela?->atividade_necessidade_material_id === $parcelaPedido->atividade_necessidade_material_id;

            if (! $mesmaNecessidade) {
                throw new ProvenienciaAdjudicacaoInvalidaException(
                    'Esta adjudicação foi feita para uma Atividade/necessidade diferente da desta parcela do Pedido.'
                );
            }

            return;
        }

        if ($adjudicacaoItem->requisicao_compra_item_parcela_id) {
            throw new ProvenienciaAdjudicacaoInvalidaException(
                'Esta adjudicação foi detalhada por Atividade — selecione a parcela correspondente do Pedido, nunca o item sem detalhamento.'
            );
        }
    }

    private function garantirFornecedorCompativel(PedidoCompra $pedido, RequisicaoCompraAdjudicacaoItem $adjudicacaoItem): void
    {
        $fornecedorAdjudicacao = $adjudicacaoItem->adjudicacao?->fornecedor_id;

        if ($fornecedorAdjudicacao !== $pedido->fornecedor_id) {
            throw new ProvenienciaAdjudicacaoInvalidaException(
                'Esta adjudicação pertence a um fornecedor diferente do fornecedor deste Pedido.'
            );
        }
    }

    private function garantirAdjudicacaoAtiva(RequisicaoCompraAdjudicacaoItem $adjudicacaoItem): void
    {
        if (! $adjudicacaoItem->adjudicacao?->estaAtiva()) {
            throw new ProvenienciaAdjudicacaoInvalidaException(
                'Esta adjudicação foi cancelada — não é possível atribuir consumo de Pedido a ela.'
            );
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new ProvenienciaAdjudicacaoInvalidaException('A quantidade atribuída precisa ser maior que zero.');
        }
    }

    private function garantirSaldoAdjudicacaoItem(RequisicaoCompraAdjudicacaoItem $adjudicacaoItem, float $quantidadeDesejada, ?string $excluirConsumoId): void
    {
        $saldo = $adjudicacaoItem->saldoNaoConsumidoViaBridge($excluirConsumoId);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            throw new SaldoAdjudicacaoInsuficienteException(
                "Quantidade atribuída ({$quantidadeDesejada}) excede o saldo ainda não consumido ({$saldo}) desta linha de adjudicação.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }

    /**
     * A soma de tudo atribuído a este alvo do Pedido (podendo vir de N
     * adjudicações distintas — Seção 5) nunca pode ultrapassar a
     * quantidade real do alvo (`quantidade_pedida` do item, ou
     * `quantidade` da parcela).
     */
    private function garantirNaoUltrapassaAlvoPedido(PedidoCompraItem $pedidoItem, ?PedidoCompraItemParcela $parcela, float $quantidadeDesejada, ?string $excluirConsumoId): void
    {
        $query = $parcela
            ? PedidoCompraItemAdjudicacao::where('pedido_compra_item_parcela_id', $parcela->id)
            : PedidoCompraItemAdjudicacao::where('pedido_compra_item_id', $pedidoItem->id)->whereNull('pedido_compra_item_parcela_id');

        if ($excluirConsumoId) {
            $query->where('id', '!=', $excluirConsumoId);
        }

        $jaAtribuido = (float) $query->sum('quantidade');
        $tetoAlvo = $parcela ? (float) $parcela->quantidade : (float) $pedidoItem->quantidade_pedida;

        if ($jaAtribuido + $quantidadeDesejada > $tetoAlvo + 0.0005) {
            throw new AdjudicacaoConsumidaPorPedidoException(
                "A soma das atribuições de origem ({$jaAtribuido} + {$quantidadeDesejada}) excede a quantidade deste alvo do Pedido ({$tetoAlvo})."
            );
        }
    }
}
