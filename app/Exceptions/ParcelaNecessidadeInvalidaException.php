<?php

namespace App\Exceptions;

/**
 * Rastreabilidade Quantitativa, Etapa 1 — lançada por violações de
 * regra de negócio (RC/Pedido não Rascunho, material incompatível,
 * obra incompatível, parcela inexistente na RC de origem, quantidade
 * não positiva, duplicidade de par) ao gerenciar
 * `RequisicaoCompraItemParcela`/`PedidoCompraItemParcela`. Nunca usada
 * pra falta de saldo — ver `SaldoParcelaNecessidadeInsuficienteException`/
 * `SaldoParcelaPedidoInsuficienteException`.
 */
class ParcelaNecessidadeInvalidaException extends \RuntimeException
{
}
