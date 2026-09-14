<?php

namespace App\Exceptions;

/**
 * Etapa 3 — validações de `App\Actions\Suprimentos\
 * AtualizarPrevisaoEntregaPedidoCompra` (motivo obrigatório em revisão,
 * data inválida) e guarda de imutabilidade de
 * `App\Observers\PedidoCompraPrevisaoEntregaObserver`.
 */
class PrevisaoEntregaInvalidaException extends \RuntimeException
{
}
