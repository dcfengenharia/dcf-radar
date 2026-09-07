<?php

namespace App\Actions\LicoesAprendidas;

use App\Exceptions\LicaoAprendidaImutavelException;
use App\Models\LicaoAprendidaVinculo;

/**
 * Ciclo 23, Etapa 23.1 — remove um vínculo, só enquanto a lição não é
 * imutável.
 *
 * Ciclo 23, Etapa 23.2 (Seção 9) — o vínculo de ORIGEM nunca é
 * removível, mesmo com a lição ainda editável: "o vínculo de origem
 * deve continuar distinguível" só se sustenta se ele nunca puder
 * desaparecer sozinho, deixando a lição "sem origem" enquanto o
 * histórico de captura contextual continuaria implicitamente afirmando
 * que ela nasceu de um contexto. Vínculos complementares continuam
 * livremente removíveis, como já era desde a 23.1.
 */
class RemoverVinculoDaLicao
{
    public function execute(LicaoAprendidaVinculo $vinculo): void
    {
        if ($vinculo->licao->estaImutavel()) {
            throw new LicaoAprendidaImutavelException(
                'Esta lição já foi publicada/arquivada — vínculos não podem mais ser removidos.'
            );
        }

        if ($vinculo->e_origem) {
            throw new LicaoAprendidaImutavelException(
                'O vínculo de origem desta lição não pode ser removido.'
            );
        }

        $vinculo->delete();
    }
}
