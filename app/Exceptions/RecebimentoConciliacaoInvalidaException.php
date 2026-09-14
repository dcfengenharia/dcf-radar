<?php

namespace App\Exceptions;

use Exception;

/**
 * Fechamento Adversarial Etapa 3 — erro de validação de uma
 * distribuição de recebimento por parcela/necessidade: parcela de outro
 * PedidoCompraItem/Pedido, cross-obra/cross-tenant, quantidade inválida,
 * ou excedendo o saldo pendente do recebimento OU o saldo da própria
 * parcela. Nunca usada pro caso "recebimento já 100% distribuído" —
 * isso é `RecebimentoConciliacaoFechadaException`.
 */
class RecebimentoConciliacaoInvalidaException extends Exception
{
}
