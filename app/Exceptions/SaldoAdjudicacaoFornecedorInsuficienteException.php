<?php

namespace App\Exceptions;

/**
 * Etapa 2 (Pedido × Adjudicação) — lançada quando um Pedido tentaria
 * consumir mais do que a quota efetivamente ADJUDICADA ao seu próprio
 * fornecedor para um RCItem/RequisicaoCompraItemParcela — o 3º teto
 * descrito na Seção 12 do pedido (além da quantidade do PedidoItem e da
 * quota da RC/parcela). Só se aplica quando o alvo TEM alguma
 * adjudicação Ativa registrada (compatibilidade — Seção 13: RC/Pedido
 * sem nenhuma adjudicação nunca disparam esta exceção).
 */
class SaldoAdjudicacaoFornecedorInsuficienteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $saldoDisponivel,
        public readonly float $quantidadeSolicitada,
    ) {
        parent::__construct($message);
    }
}
