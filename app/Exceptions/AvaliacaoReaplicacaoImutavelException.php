<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 10/13) — avaliações de reaplicação são
 * append-only: uma vez criada, uma avaliação nunca é editada nem
 * apagada — correção é sempre uma NOVA avaliação. Lançada por
 * `App\Observers\LicaoAprendidaReaplicacaoAvaliacaoObserver`.
 */
class AvaliacaoReaplicacaoImutavelException extends Exception
{
}
