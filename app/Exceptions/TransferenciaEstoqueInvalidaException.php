<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.6 — validação de negócio de
 * App\Actions\Estoque\RegistrarTransferenciaEstoque: data futura,
 * Material/Local inativo, Local origem=destino, Local Terceiro
 * envolvido, cross-obra, Unidade incompatível, saldo físico
 * insuficiente. Mensagem sempre didática, nunca SQL cru.
 */
class TransferenciaEstoqueInvalidaException extends Exception
{
}
