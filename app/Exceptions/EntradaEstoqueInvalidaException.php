<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.1 — validação de negócio de
 * App\Actions\Estoque\RegistrarEntradaEstoque: data futura, item sem
 * Material associado, Material/LocalEstoque inativo, modo de
 * rastreabilidade exigindo lote/serial ausente, ou serial com
 * quantidade diferente de 1. Mensagem sempre didática, nunca SQL cru.
 */
class EntradaEstoqueInvalidaException extends Exception
{
}
