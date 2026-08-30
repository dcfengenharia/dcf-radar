<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.2 — over-reserva: quantidade solicitada superaria
 * o saldo físico DISPONÍVEL (físico − já reservado ativo) de
 * Material+Local ou de uma UnidadeEstoque específica.
 */
class SaldoFisicoInsuficienteException extends Exception
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
