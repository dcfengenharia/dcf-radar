<?php

namespace App\Exceptions;

/**
 * Etapa 2.CORREÇÃO — validações estruturais de
 * `App\Actions\Suprimentos\AtualizarProvenienciaAdjudicacaoPedidoCompra`
 * (alvo/granularidade/fornecedor/status incompatíveis entre o consumo do
 * Pedido e a `RequisicaoCompraAdjudicacaoItem` de origem).
 */
class ProvenienciaAdjudicacaoInvalidaException extends \RuntimeException
{
}
