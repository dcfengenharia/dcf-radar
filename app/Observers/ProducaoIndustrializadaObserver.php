<?php

namespace App\Observers;

use App\Exceptions\ProducaoIndustrializadaInvalidaException;
use App\Models\ProducaoIndustrializada;

/**
 * Ciclo 20, Etapa 20.5 — ledger append-only. Só
 * `App\Actions\Estoque\RegistrarProducaoIndustrializada` escreve aqui.
 */
class ProducaoIndustrializadaObserver
{
    public function updating(ProducaoIndustrializada $producao): void
    {
        throw new ProducaoIndustrializadaInvalidaException('Um evento de produção já registrado nunca pode ser alterado.');
    }

    public function deleting(ProducaoIndustrializada $producao): void
    {
        throw new ProducaoIndustrializadaInvalidaException('Um evento de produção já registrado nunca pode ser excluído.');
    }
}
