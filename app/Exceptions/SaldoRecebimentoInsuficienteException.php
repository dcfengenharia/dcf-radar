<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.1 — over-entrada: a soma das entradas em estoque já
 * geradas a partir de um RecebimentoPedido nunca pode superar a
 * quantidade_recebida daquele recebimento (mesmo padrão quantitativo de
 * toda a cadeia do Ciclo 19 — SaldoPedidoInsuficienteException,
 * SaldoRequisicaoCompraInsuficienteException etc.).
 */
class SaldoRecebimentoInsuficienteException extends Exception
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeInformada,
    ) {
        parent::__construct($message);
    }
}
