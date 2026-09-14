<?php

namespace App\Exceptions;

/**
 * Etapa 2.CORREÇÃO — lançada na emissão do Pedido quando o alvo
 * (RCItem/RequisicaoCompraItemParcela) consumido por este Pedido TEM
 * adjudicação Ativa para o fornecedor do Pedido, mas a ponte explícita
 * `PedidoCompraItemAdjudicacao` não contabiliza (via
 * `RequisicaoCompraAdjudicacaoItem`) a quantidade inteira sendo emitida —
 * fecha o gap de proveniência histórica identificado na auditoria
 * adversarial (Fechamento Etapa 2, Seções 1-2): balanço agregado por
 * fornecedor nunca basta pra provar "este Pedido nasceu de QUAL decisão
 * de adjudicação" quando o mesmo fornecedor tem 2+ adjudicações sobre o
 * mesmo alvo. Só dispara quando o alvo TEM adjudicação Ativa (mesma
 * compatibilidade de sempre — RC/Pedido sem nenhuma adjudicação nunca
 * disparam esta exceção).
 */
class AtribuicaoAdjudicacaoObrigatoriaException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $quantidadeNaoAtribuida,
    ) {
        parent::__construct($message);
    }
}
