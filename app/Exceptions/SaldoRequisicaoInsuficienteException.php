<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.3 — lançada quando uma alocação faria
 * `SUM(quantidade_alocada) > RequisicaoPlanejamentoItem.quantidade_requisitada`
 * (over-allocation). Mesmo padrão de `SaldoTakeOffInsuficienteException`.
 */
class SaldoRequisicaoInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
