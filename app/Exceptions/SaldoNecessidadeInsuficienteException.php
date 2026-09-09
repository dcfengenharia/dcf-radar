<?php

namespace App\Exceptions;

use Exception;

/**
 * Melhoria "Posto Operacional" — espelha exatamente
 * `App\Exceptions\SaldoDestinacaoInsuficienteException` (Ciclo 20.2):
 * lançada quando a distribuição de um `ItemTakeOff` entre atividades
 * excederia `ItemTakeOff.quantidade` (saldo a distribuir).
 */
class SaldoNecessidadeInsuficienteException extends Exception
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
