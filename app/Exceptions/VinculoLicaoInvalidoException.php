<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.1 — a entidade referenciada num vínculo de lição
 * não existe, foi removida, ou pertence a outro tenant (nunca revelado
 * qual dos três casos, pra não vazar existência cross-tenant).
 */
class VinculoLicaoInvalidoException extends Exception
{
}
