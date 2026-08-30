<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.2 — lançada quando uma requisição (rascunho ou
 * emissão) faria `quantidade_requisitada_total > quantidade_prevista`
 * pra algum ItemTakeOff (over-requisition, seção 9 do pedido). Carrega
 * os números pra a UI mostrar uma mensagem didática, nunca uma exceção
 * crua.
 */
class SaldoTakeOffInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
