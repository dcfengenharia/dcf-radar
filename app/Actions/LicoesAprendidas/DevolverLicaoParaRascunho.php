<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusLicaoAprendida;
use App\Exceptions\LicaoAprendidaTransicaoInvalidaException;
use App\Models\LicaoAprendida;

/** Ciclo 23, Etapa 23.1 — EmValidacao → Rascunho (devolução pra revisão). */
class DevolverLicaoParaRascunho
{
    public function execute(LicaoAprendida $licao): LicaoAprendida
    {
        if ($licao->status !== StatusLicaoAprendida::EmValidacao) {
            throw new LicaoAprendidaTransicaoInvalidaException(
                'Só é possível devolver para rascunho uma lição que esteja Em Validação.'
            );
        }

        $licao->update([
            'status' => StatusLicaoAprendida::Rascunho,
            'enviado_validacao_em' => null,
        ]);

        return $licao->fresh();
    }
}
