<?php

namespace App\Observers;

use App\Exceptions\EntregaProdutoIndustrializadoInvalidaException;
use App\Models\EntregaProdutoIndustrializado;

/**
 * Ciclo 20, Etapa 20.5 — ledger append-only. Só
 * `App\Actions\Estoque\RegistrarEntregaProdutoIndustrializado` escreve
 * aqui.
 */
class EntregaProdutoIndustrializadoObserver
{
    public function updating(EntregaProdutoIndustrializado $entrega): void
    {
        throw new EntregaProdutoIndustrializadoInvalidaException('Uma entrega já registrada nunca pode ser alterada.');
    }

    public function deleting(EntregaProdutoIndustrializado $entrega): void
    {
        throw new EntregaProdutoIndustrializadoInvalidaException('Uma entrega já registrada nunca pode ser excluída.');
    }
}
