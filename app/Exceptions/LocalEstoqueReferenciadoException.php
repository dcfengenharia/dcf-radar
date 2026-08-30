<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — lançada por App\Observers\LocalEstoqueObserver
 * quando uma exclusão (soft ou force) é tentada sobre um LocalEstoque
 * referenciado por UnidadeEstoque/MovimentacaoEstoque.
 */
class LocalEstoqueReferenciadoException extends Exception
{
}
