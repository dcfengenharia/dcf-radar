<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.8 — guards de App\Support\Estoque\ResolverCodigoEstoque:
 * código vazio/malformado, prefixo desconhecido, entidade não encontrada
 * (cobre tanto "nunca existiu" quanto "existe mas é de outro tenant" — as
 * duas produzem a MESMA mensagem, por design, nunca vazando a diferença;
 * ver docblock do resolver), entidade de outra obra.
 *
 * Nunca lançada por causa de Material/LocalEstoque INATIVO — resolver
 * sempre resolve (Seção 16 do pedido: "Resolver != autorizar operação");
 * quem bloqueia uma operação sobre entidade inativa continua sendo,
 * exclusivamente, a Action de domínio já existente (RegistrarEntradaEstoque
 * etc.), nunca o resolver.
 */
class CodigoEstoqueInvalidoException extends Exception
{
}
