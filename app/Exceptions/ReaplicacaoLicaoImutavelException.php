<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 6) — a identidade de uma reaplicação
 * (tenant/lição/obra/quem registrou/quando registrou/observação
 * inicial) é imutável desde a criação — nunca movida pra outra
 * lição/obra, nunca excluída (histórico sempre preservado). Lançada por
 * `App\Observers\LicaoAprendidaReaplicacaoObserver`.
 */
class ReaplicacaoLicaoImutavelException extends Exception
{
}
