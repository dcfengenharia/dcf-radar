<?php

namespace App\Exceptions;

/**
 * Rastreabilidade Quantitativa, Etapa 1 — lançada quando o detalhamento
 * por Atividade de um Pedido excede (A) a própria
 * `PedidoCompraItem.quantidade_pedida` ou (B) a "quota" que a
 * `RequisicaoCompraItemParcela` de origem realmente oferece pra Pedidos
 * (nunca inferida por proporcionalidade — sempre a quantidade explícita
 * já detalhada na RC).
 */
class SaldoParcelaPedidoInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
