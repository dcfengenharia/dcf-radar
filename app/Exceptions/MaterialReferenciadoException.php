<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — lançada por App\Observers\MaterialObserver
 * quando uma exclusão (soft ou force) é tentada sobre um Material
 * referenciado por ItemTakeOff/UnidadeEstoque/MovimentacaoEstoque.
 */
class MaterialReferenciadoException extends Exception
{
}
