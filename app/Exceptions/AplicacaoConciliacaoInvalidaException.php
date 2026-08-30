<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.4 — erro de validação de uma Aplicação (conciliação
 * de onde uma Saída física foi efetivamente utilizada): Frente/Pacote
 * incoerente com a Saída, cross-obra/cross-tenant, quantidade inválida
 * ou excedendo o saldo pendente. Nunca usada pro caso "Saída já 100%
 * conciliada" — isso é `AplicacaoConciliacaoFechadaException`.
 */
class AplicacaoConciliacaoInvalidaException extends Exception
{
}
