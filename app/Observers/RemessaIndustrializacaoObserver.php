<?php

namespace App\Observers;

use App\Exceptions\RemessaIndustrializacaoInvalidaException;
use App\Models\RemessaIndustrializacao;

/**
 * Ciclo 20, Etapa 20.5 — ledger append-only, mesmo padrão de
 * `MovimentacaoEstoqueObserver`/`RecebimentoPedidoObserver`: bloqueia
 * `updating()`/`deleting()` incondicionalmente. Só
 * `App\Actions\Estoque\RegistrarRemessaIndustrializacao` escreve aqui.
 */
class RemessaIndustrializacaoObserver
{
    public function updating(RemessaIndustrializacao $remessa): void
    {
        throw new RemessaIndustrializacaoInvalidaException('Uma Remessa de Industrialização já registrada nunca pode ser alterada.');
    }

    public function deleting(RemessaIndustrializacao $remessa): void
    {
        throw new RemessaIndustrializacaoInvalidaException('Uma Remessa de Industrialização já registrada nunca pode ser excluída.');
    }
}
