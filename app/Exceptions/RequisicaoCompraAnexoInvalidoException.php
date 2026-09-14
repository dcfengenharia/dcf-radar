<?php

namespace App\Exceptions;

/**
 * Etapa 2 (Dossiê Documental da RC) — validação de negócio de um anexo:
 * fornecedor cross-obra, tentativa de excluir uma evidência já
 * referenciada (substituída por uma versão mais nova, ou já suportando
 * uma Adjudicação). Mensagem sempre didática, nunca expõe SQL/caminho
 * de arquivo.
 */
class RequisicaoCompraAnexoInvalidoException extends \RuntimeException
{
}
