<?php

namespace App\Exceptions;

use Exception;

/**
 * Fechamento Adversarial Etapa 3 — este `RecebimentoPedido` já está
 * 100% distribuído por necessidade (`SUM(distribuições) >= quantidade
 * recebida`) — criar, editar ou excluir qualquer distribuição dele é
 * bloqueado incondicionalmente, mesmo pra "corrigir" um erro (mesma
 * decisão já usada em `AplicacaoConciliacaoFechadaException`, Ciclo
 * 20.4: reabrir uma conciliação fechada excluindo uma linha nunca é
 * permitido — correção de conciliação já fechada fica fora de escopo).
 */
class RecebimentoConciliacaoFechadaException extends Exception
{
}
