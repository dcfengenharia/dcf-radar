<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.2 — over-destinação: soma das
 * DestinacaoPlanejadaMaterial de um Pacote+Material superaria a
 * demanda formal (SUM de AlocacaoRequisicaoPacote.quantidade_alocada
 * pra aquele par).
 */
class SaldoDestinacaoInsuficienteException extends Exception
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
