<?php

namespace App\Observers;

use App\Exceptions\RequisicaoCompraImutavelException;
use App\Models\RequisicaoCompra;

/**
 * Ciclo 19, Etapa 19.4 — mesmo padrão exato de `GrdObserver`/
 * `RequisicaoPlanejamentoObserver`: bloqueia `delete()`/`forceDelete()`
 * de uma RC que já não é Rascunho (Emitida OU Concluída — histórico é
 * imutável nos dois casos). `forceDelete()` sempre delega para
 * `delete()`, que dispara `deleting` antes de `performDeleteOnModel()`
 * — um único guard cobre as duas chamadas. Rascunho continua livre pra
 * ser excluído (soft ou force).
 */
class RequisicaoCompraObserver
{
    public function deleting(RequisicaoCompra $rc): void
    {
        if (! $rc->estaRascunho()) {
            throw new RequisicaoCompraImutavelException(
                'Esta Requisição de Compra já foi emitida e não pode ser excluída — histórico é imutável.'
            );
        }
    }
}
