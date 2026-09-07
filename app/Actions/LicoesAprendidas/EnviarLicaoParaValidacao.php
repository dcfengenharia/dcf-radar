<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusLicaoAprendida;
use App\Exceptions\LicaoAprendidaTransicaoInvalidaException;
use App\Models\LicaoAprendida;

/** Ciclo 23, Etapa 23.1 — Rascunho → EmValidacao. */
class EnviarLicaoParaValidacao
{
    public function execute(LicaoAprendida $licao): LicaoAprendida
    {
        if ($licao->status !== StatusLicaoAprendida::Rascunho) {
            throw new LicaoAprendidaTransicaoInvalidaException(
                'Só é possível enviar para validação uma lição que esteja em Rascunho.'
            );
        }

        $licao->update([
            'status' => StatusLicaoAprendida::EmValidacao,
            'enviado_validacao_em' => now(),
        ]);

        return $licao->fresh();
    }
}
