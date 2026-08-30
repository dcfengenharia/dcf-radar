<?php

namespace App\Observers;

use App\Exceptions\ConsumoIndustrializacaoInvalidaException;
use App\Models\ProdutoIndustrializadoConsumo;

/**
 * Ciclo 20, Etapa 20.5 — genealogia append-only: nunca reescrita
 * silenciosamente (decisão do usuário, "preservar histórico"). Só
 * `App\Actions\Estoque\RegistrarConsumoIndustrializacao` escreve aqui.
 */
class ProdutoIndustrializadoConsumoObserver
{
    public function updating(ProdutoIndustrializadoConsumo $consumo): void
    {
        throw new ConsumoIndustrializacaoInvalidaException('Um consumo de matéria-prima já registrado nunca pode ser alterado.');
    }

    public function deleting(ProdutoIndustrializadoConsumo $consumo): void
    {
        throw new ConsumoIndustrializacaoInvalidaException('Um consumo de matéria-prima já registrado nunca pode ser excluído.');
    }
}
