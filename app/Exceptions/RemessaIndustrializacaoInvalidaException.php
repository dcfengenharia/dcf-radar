<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.5 — erro de validação de uma RemessaIndustrializacao:
 * Ordem não emitida, saldo físico insuficiente (Envio) ou saldo em
 * terceiro insuficiente (RetornoSobra), Material/Local/Unidade
 * incoerentes.
 */
class RemessaIndustrializacaoInvalidaException extends Exception
{
}
