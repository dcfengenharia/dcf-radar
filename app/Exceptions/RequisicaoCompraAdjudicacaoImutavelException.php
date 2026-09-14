<?php

namespace App\Exceptions;

/**
 * Etapa 2 (Adjudicação) — mesmo padrão de `RequisicaoCompraImutavelException`/
 * `PedidoCompraImutavelException`: lançada quando algo tenta excluir uma
 * `RequisicaoCompraAdjudicacao` (nunca permitido — só `status =
 * Cancelada` desfaz uma decisão, sempre preservando o histórico).
 */
class RequisicaoCompraAdjudicacaoImutavelException extends \RuntimeException
{
}
