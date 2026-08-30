<?php

namespace App\Observers;

use App\Exceptions\PedidoCompraImutavelException;
use App\Models\PedidoCompra;

/**
 * Ciclo 19, Etapa 19.5 — mesmo padrão exato de `RequisicaoCompraObserver`/
 * `GrdObserver`: bloqueia `delete()`/`forceDelete()` de um Pedido que já
 * não é Rascunho (Emitido — histórico é imutável, "não apagar
 * compromisso comercial", item 36 do pedido). `forceDelete()` sempre
 * delega para `delete()`, que dispara `deleting` antes de
 * `performDeleteOnModel()` — um único guard cobre as duas chamadas.
 * Rascunho continua livre pra ser excluído (soft ou force).
 *
 * Cancelamento de Pedido Emitido NÃO foi implementado nesta etapa (item
 * 37 do pedido — sem requisito confirmado; delete nunca é usado como
 * cancelamento).
 */
class PedidoCompraObserver
{
    public function deleting(PedidoCompra $pedido): void
    {
        if (! $pedido->estaRascunho()) {
            throw new PedidoCompraImutavelException(
                'Este Pedido/Ordem de Compra já foi emitido e não pode ser excluído — histórico é imutável.'
            );
        }
    }
}
