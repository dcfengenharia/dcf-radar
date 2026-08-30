<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.6 — Pedido/Ordem de Compra em Rascunho nunca pode
 * receber material (só `Emitido`). Também usada por
 * `App\Actions\Suprimentos\RegistrarRecebimentoPedido` para quantidade
 * inválida (<= 0).
 */
class RecebimentoPedidoInvalidoException extends \RuntimeException
{
}
