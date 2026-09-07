<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.1 — transição de status pedida não é permitida a
 * partir do status atual da lição (ex.: tentar publicar uma que ainda
 * está em Rascunho, ou arquivar uma que nunca foi publicada). Nunca um
 * `update(['status' => ...])` genérico — só as Actions dedicadas.
 */
class LicaoAprendidaTransicaoInvalidaException extends Exception
{
}
