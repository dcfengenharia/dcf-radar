<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.5 — erro de validação de um LocalEstoque:
 * incoerência entre `tipo` e `fornecedor_id` (Terceiro sem fornecedor,
 * ou não-Terceiro com fornecedor), ou tentativa de reinterpretar
 * tipo/fornecedor de um Local que já tem movimentações.
 */
class LocalEstoqueInvalidoException extends Exception
{
}
