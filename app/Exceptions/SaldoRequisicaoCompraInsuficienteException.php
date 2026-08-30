<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.5 — lançada quando um Pedido consumiria
 * `SUM(quantidade_pedida) > RequisicaoCompraItem.quantidade` (over-
 * pedido, só considerando Pedidos `Emitido`). Mesmo padrão de
 * `SaldoAlocacaoInsuficienteException`/`SaldoRequisicaoInsuficienteException`.
 */
class SaldoRequisicaoCompraInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
