<?php

namespace App\Exceptions;

/**
 * Auditoria Pré-Produção A1, DB-03 — lançada por
 * App\Observers\PlanoAcaoReconciliacaoObserver quando código de produção
 * tenta alterar ou excluir um evento de reconciliação já registrado
 * (log append-only, mesmo espírito de DocumentoEngenhariaReprogramacao).
 */
class PlanoAcaoReconciliacaoImutavelException extends \RuntimeException
{
}
