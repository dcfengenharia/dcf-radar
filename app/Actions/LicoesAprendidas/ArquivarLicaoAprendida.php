<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusLicaoAprendida;
use App\Exceptions\LicaoAprendidaTransicaoInvalidaException;
use App\Models\LicaoAprendida;
use App\Models\User;

/**
 * Ciclo 23, Etapa 23.1 — Publicada → Arquivada. Retirada da consulta
 * operacional padrão, mas preservada pra sempre (nunca hard/soft delete
 * — `App\Observers\LicaoAprendidaObserver` já bloqueia exclusão a
 * partir daqui).
 */
class ArquivarLicaoAprendida
{
    public function execute(LicaoAprendida $licao, User $arquivador): LicaoAprendida
    {
        if ($licao->status !== StatusLicaoAprendida::Publicada) {
            throw new LicaoAprendidaTransicaoInvalidaException(
                'Só é possível arquivar uma lição que esteja Publicada.'
            );
        }

        $licao->update([
            'status' => StatusLicaoAprendida::Arquivada,
            'arquivado_por_id' => $arquivador->id,
            'arquivado_em' => now(),
        ]);

        return $licao->fresh();
    }
}
