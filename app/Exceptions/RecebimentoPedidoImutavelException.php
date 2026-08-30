<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.6 — um `RecebimentoPedido` já registrado é fato
 * físico/auditável, append-only: nunca editado, nunca apagado (soft ou
 * force). Lançada por `App\Observers\RecebimentoPedidoObserver::deleting()`
 * incondicionalmente — mesmo padrão de `ListaEngenhariaObserver` (bloqueio
 * sempre, sem exceção de status, já que não existe estado "rascunho" pra
 * um evento de recebimento). Correção de quantidade errada fica pra uma
 * etapa futura de estorno/ajuste (dívida documentada, não implementada
 * nesta fase).
 */
class RecebimentoPedidoImutavelException extends \RuntimeException
{
}
