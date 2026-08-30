<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.4 — a Saída física já está 100% conciliada
 * (SUM(aplicações) >= quantidade da Saída) — criar, editar ou excluir
 * qualquer Aplicação dela é bloqueado incondicionalmente, mesmo pra
 * "corrigir" um erro (decisão do usuário: reabrir uma conciliação
 * fechada excluindo uma linha nunca é permitido nesta etapa — correção
 * de uma conciliação já fechada fica fora de escopo, fica para uma
 * fase futura de estorno/reclassificação auditável).
 */
class AplicacaoConciliacaoFechadaException extends Exception
{
}
