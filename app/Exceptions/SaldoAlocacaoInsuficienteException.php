<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.4 — lançada quando uma RC consumiria
 * `SUM(quantidade) > AlocacaoRequisicaoPacote.quantidade_alocada`
 * (over-RC). Mesmo padrão de `SaldoRequisicaoInsuficienteException`/
 * `SaldoTakeOffInsuficienteException`.
 */
class SaldoAlocacaoInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
