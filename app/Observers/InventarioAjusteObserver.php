<?php

namespace App\Observers;

use App\Exceptions\AjusteInventarioInvalidoException;
use App\Models\InventarioAjuste;

/**
 * Ciclo 20, Etapa 20.7 — evidência histórica de um evento que já alterou
 * o ledger físico (MovimentacaoEstoque) — nunca editado nem excluído,
 * mesmo padrão de GrdAceiteEntrega/TransferenciaEstoque.
 */
class InventarioAjusteObserver
{
    public function updating(InventarioAjuste $ajuste): void
    {
        throw new AjusteInventarioInvalidoException(
            'Um Ajuste de Inventário já aprovado nunca pode ser alterado.'
        );
    }

    public function deleting(InventarioAjuste $ajuste): void
    {
        throw new AjusteInventarioInvalidoException(
            'Um Ajuste de Inventário já aprovado nunca pode ser excluído.'
        );
    }
}
