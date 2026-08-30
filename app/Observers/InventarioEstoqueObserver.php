<?php

namespace App\Observers;

use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioEstoque;

/**
 * Ciclo 20, Etapa 20.7 — Cancelamento é SEMPRE status (Cancelado), nunca
 * DELETE (Seção 20 do pedido) — bloqueia deleting()/forceDelete()
 * incondicionalmente, mesmo padrão de GrdObserver/RequisicaoCompraObserver.
 *
 * Depois de Concluido/Cancelado, o registro fica congelado (Seção 21) —
 * updating() bloqueia qualquer save() adicional (as próprias transições
 * de status usam update(), então o guard olha o status ORIGINAL, nunca o
 * novo, senão a própria transição pra Concluido/Cancelado se bloquearia).
 */
class InventarioEstoqueObserver
{
    public function deleting(InventarioEstoque $inventario): void
    {
        throw new InventarioEstoqueInvalidoException(
            'Um Inventário nunca é excluído — cancele-o (status Cancelado) se necessário.'
        );
    }

    public function updating(InventarioEstoque $inventario): void
    {
        $statusOriginal = $inventario->getOriginal('status');
        $statusOriginal = $statusOriginal instanceof \App\Enums\StatusInventarioEstoque
            ? $statusOriginal
            : \App\Enums\StatusInventarioEstoque::from($statusOriginal);

        if ($statusOriginal->estaFinalizado()) {
            throw new InventarioEstoqueInvalidoException(
                'Este Inventário já está finalizado (Concluído/Cancelado) e não pode mais ser alterado.'
            );
        }
    }
}
