<?php

namespace App\Exceptions;

/**
 * Rastreabilidade Quantitativa, Etapa 1 — lançada quando o detalhamento
 * por Atividade consumiria mais do que a origem permite, nos dois
 * níveis possíveis: (A) `SUM(parcelas de uma RCItem) > RCItem.quantidade`
 * ou (B) `SUM(parcelas de uma necessidade, em RCs comercialmente
 * válidas) > AtividadeNecessidadeMaterial.quantidade_necessaria`. Mesmo
 * padrão de `SaldoAlocacaoInsuficienteException`.
 */
class SaldoParcelaNecessidadeInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
