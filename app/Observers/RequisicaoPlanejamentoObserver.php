<?php

namespace App\Observers;

use App\Exceptions\RequisicaoPlanejamentoImutavelException;
use App\Models\RequisicaoPlanejamento;

/**
 * Ciclo 19, Etapa 19.2 — mesmo padrão exato de App\Observers\GrdObserver:
 * bloqueia `delete()`/`forceDelete()` de uma RP Emitida (histórico é
 * imutável). `forceDelete()` sempre delega para `delete()`, que dispara
 * `deleting` antes de `performDeleteOnModel()` — um único guard cobre as
 * duas chamadas. Rascunho continua livre pra ser excluído (soft ou
 * force).
 */
class RequisicaoPlanejamentoObserver
{
    public function deleting(RequisicaoPlanejamento $rp): void
    {
        if ($rp->estaEmitida()) {
            throw new RequisicaoPlanejamentoImutavelException(
                'Esta Requisição do Planejamento já foi emitida e não pode ser excluída — histórico é imutável.'
            );
        }
    }
}
