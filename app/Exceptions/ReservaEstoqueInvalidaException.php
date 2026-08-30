<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.2 — validação de domínio de ReservaEstoque que não
 * é sobre saldo físico (cross-obra, Material/Local inativos, modo de
 * rastreabilidade incompatível, quantidade inválida, Destinação de
 * outro Material/obra). Mensagem sempre didática.
 */
class ReservaEstoqueInvalidaException extends Exception
{
}
