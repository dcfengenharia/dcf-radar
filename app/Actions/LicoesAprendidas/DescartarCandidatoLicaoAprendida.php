<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusCandidatoLicaoAprendida;
use App\Exceptions\CandidatoLicaoAprendidaJaTratadoException;
use App\Models\CandidatoLicaoAprendida;
use App\Models\User;

/**
 * Ciclo 23, Etapa 23.3 (Seção 21) — descarte: "não é uma lição
 * relevante". Nunca deleta — grava `descartado_por`/`descartado_em`/
 * `motivo_descarte` (opcional) na MESMA linha, terminal (nunca volta a
 * Pendente, nunca é convertido depois).
 *
 * `UPDATE ... WHERE status='pendente'` condicional — mesmo idioma de
 * `ConverterCandidatoEmLicao`/`LiberarReservaEstoque` — 0 linhas
 * afetadas significa que outra ação (conversão ou descarte concorrente)
 * já tratou este candidato.
 */
class DescartarCandidatoLicaoAprendida
{
    public function execute(CandidatoLicaoAprendida $candidato, User $usuario, ?string $motivo): void
    {
        $linhasAtualizadas = CandidatoLicaoAprendida::whereKey($candidato->id)
            ->where('status', StatusCandidatoLicaoAprendida::Pendente->value)
            ->update([
                'status' => StatusCandidatoLicaoAprendida::Descartado->value,
                'descartado_por' => $usuario->id,
                'descartado_em' => now(),
                'motivo_descarte' => $motivo,
            ]);

        if ($linhasAtualizadas === 0) {
            throw new CandidatoLicaoAprendidaJaTratadoException(
                'Este candidato já foi tratado (convertido ou descartado) por outra ação.'
            );
        }
    }
}
