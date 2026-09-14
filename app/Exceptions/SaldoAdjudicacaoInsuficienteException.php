<?php

namespace App\Exceptions;

/**
 * Etapa 2 (Adjudicação) — lançada quando a soma das adjudicações Ativas
 * sobre um RCItem/RequisicaoCompraItemParcela excederia a quantidade
 * disponível naquele alvo (over-adjudication). Mesmo padrão de
 * `SaldoAlocacaoInsuficienteException`/`SaldoParcelaNecessidadeInsuficienteException`.
 */
class SaldoAdjudicacaoInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
