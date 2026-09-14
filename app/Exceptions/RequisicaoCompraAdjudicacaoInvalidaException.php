<?php

namespace App\Exceptions;

/**
 * Etapa 2 (Adjudicação) — validações estruturais de uma adjudicação:
 * RC ainda Rascunho, fornecedor cross-obra/soft-deletado, item/parcela
 * não pertencente à RC, granularidade incoerente (item com distribuição
 * por Atividade recebendo adjudicação sem parcela, ou vice-versa).
 * Mesmo padrão de `PedidoCompraEmissaoInvalidaException`.
 */
class RequisicaoCompraAdjudicacaoInvalidaException extends \RuntimeException
{
}
