<?php

namespace App\Observers;

use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioItem;

/**
 * Ciclo 20, Etapa 20.7 — InventarioItem nasce completo (snapshot
 * congelado) e nunca é reescrito nem excluído — toda evolução vive em
 * ContagemInventario (1:N) e InventarioAjuste (0:1), ambos append-only.
 */
class InventarioItemObserver
{
    public function updating(InventarioItem $item): void
    {
        throw new InventarioEstoqueInvalidoException(
            'Um item de Inventário nunca é alterado após criado — o snapshot é histórico e imutável.'
        );
    }

    public function deleting(InventarioItem $item): void
    {
        throw new InventarioEstoqueInvalidoException(
            'Um item de Inventário nunca é excluído.'
        );
    }
}
