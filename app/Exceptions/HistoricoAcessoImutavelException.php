<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * FASE 2D — lançada por `App\Observers\HistoricoAcessoObserver` quando
 * algum código tenta alterar ou excluir um evento já registrado. Um
 * evento de histórico é um fato de governança; corrigir um erro é
 * sempre um evento NOVO, nunca a edição do antigo.
 */
class HistoricoAcessoImutavelException extends RuntimeException
{
}
