<?php

namespace App\Exceptions;

use Exception;

/**
 * Melhoria "Posto Operacional" — mensagem didática pras validações de
 * `App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade`, nunca
 * SQL/exceção crua chegando à UI.
 */
class NecessidadeMaterialAtividadeInvalidaException extends Exception
{
}
