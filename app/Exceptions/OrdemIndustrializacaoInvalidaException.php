<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.5 — erro de validação de uma OrdemIndustrializacao:
 * Fornecedor/Local terceiro/Pacote incoerentes (cross-obra/tenant, ou
 * Local não pertence ao Fornecedor), emissão sem nenhum produto, etc.
 */
class OrdemIndustrializacaoInvalidaException extends Exception
{
}
