<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.7 — guards de ciclo de vida do InventarioEstoque:
 * Local Terceiro, Local de outra obra/inativo, transição de status
 * inválida, cancelamento de inventário já concluído, item já existente,
 * contagem fora de status permitido, contagem com data futura.
 */
class InventarioEstoqueInvalidoException extends Exception
{
}
