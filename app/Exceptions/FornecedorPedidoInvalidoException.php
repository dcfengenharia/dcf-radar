<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.5.CORREÇÃO — lançada na emissão de um Pedido/OC
 * quando o Fornecedor selecionado não está mais disponível (soft-
 * deletado, ou pertence a outra obra/tenant — defesa em profundidade,
 * já que a UI/domínio já bloqueiam isso na seleção). Mensagem sempre
 * didática, nunca expõe SQL/ID/deleted_at.
 */
class FornecedorPedidoInvalidoException extends \RuntimeException
{
}
