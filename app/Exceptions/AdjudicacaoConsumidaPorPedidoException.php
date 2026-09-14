<?php

namespace App\Exceptions;

/**
 * Etapa 2 (Adjudicação) — lançada ao tentar reduzir abaixo do consumido,
 * remover, ou cancelar uma adjudicação (ou item dela) que já tem
 * quantidade consumida oficialmente (Pedido `Emitido`) do fornecedor
 * adjudicado. Mesmo padrão de `AlocacaoConsumidaPorRequisicaoCompraException`
 * — histórico de decisão comercial nunca desaparece.
 */
class AdjudicacaoConsumidaPorPedidoException extends \RuntimeException
{
}
