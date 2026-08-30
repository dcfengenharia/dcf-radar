<?php

namespace App\Observers;

use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\ContagemInventario;

/**
 * Ciclo 20, Etapa 20.7 — ledger append-only de contagens (mesmo padrão
 * de GrdRecolhimento/RecebimentoPedido) — nunca editado nem excluído;
 * recontagem é sempre uma linha NOVA.
 */
class ContagemInventarioObserver
{
    public function updating(ContagemInventario $contagem): void
    {
        throw new InventarioEstoqueInvalidoException(
            'Uma contagem já registrada nunca pode ser alterada — registre uma recontagem.'
        );
    }

    public function deleting(ContagemInventario $contagem): void
    {
        throw new InventarioEstoqueInvalidoException(
            'Uma contagem já registrada nunca pode ser excluída.'
        );
    }
}
