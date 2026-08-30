<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.6 — bloqueio de over-recebimento: a soma acumulada
 * de `RecebimentoPedido.quantidade_recebida` de um `PedidoCompraItem`
 * nunca pode superar `quantidade_pedida`. Lançada por
 * `App\Actions\Suprimentos\RegistrarRecebimentoPedido`, sempre dentro da
 * transação com o item já travado (`lockForUpdate()`), antes de qualquer
 * escrita.
 */
class SaldoPedidoInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeDesejada,
    ) {
        parent::__construct($message);
    }
}
