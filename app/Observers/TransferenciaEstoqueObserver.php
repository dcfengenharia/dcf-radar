<?php

namespace App\Observers;

use App\Exceptions\TransferenciaEstoqueInvalidaException;
use App\Models\TransferenciaEstoque;

/**
 * Ciclo 20, Etapa 20.6 — ledger append-only, mesmo padrão de
 * `RemessaIndustrializacaoObserver`/`MovimentacaoEstoqueObserver`:
 * bloqueia `updating()`/`deleting()` incondicionalmente. Só
 * `App\Actions\Estoque\RegistrarTransferenciaEstoque` escreve aqui.
 * Correção futura de uma Transferência já registrada = uma NOVA
 * Transferência de estorno (Local origem/destino invertidos), nunca um
 * update/delete desta linha.
 */
class TransferenciaEstoqueObserver
{
    public function updating(TransferenciaEstoque $transferencia): void
    {
        throw new TransferenciaEstoqueInvalidaException('Uma Transferência já registrada nunca pode ser alterada.');
    }

    public function deleting(TransferenciaEstoque $transferencia): void
    {
        throw new TransferenciaEstoqueInvalidaException('Uma Transferência já registrada nunca pode ser excluída.');
    }
}
