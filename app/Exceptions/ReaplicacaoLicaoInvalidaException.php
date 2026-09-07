<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.5.B — invariantes de domínio de
 * `App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao`: lição não
 * está Publicada, obra de destino é a própria obra de origem, ou obra
 * de destino não pertence ao mesmo tenant da lição.
 */
class ReaplicacaoLicaoInvalidaException extends Exception
{
}
