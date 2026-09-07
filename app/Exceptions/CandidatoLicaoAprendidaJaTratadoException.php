<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.3 — um candidato só pode ser convertido OU
 * descartado uma única vez. Lançada quando o `UPDATE ... WHERE
 * status='pendente'` condicional afeta 0 linhas — outra ação (conversão
 * ou descarte, possivelmente concorrente) já tratou este candidato.
 */
class CandidatoLicaoAprendidaJaTratadoException extends Exception
{
}
