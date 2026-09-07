<?php

namespace App\Observers;

use App\Exceptions\AvaliacaoReaplicacaoImutavelException;
use App\Models\LicaoAprendidaReaplicacaoAvaliacao;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 10/13) — avaliações são append-only,
 * sempre, sem exceção: bloqueia `updating()`/`deleting()`
 * incondicionalmente. Correção de uma avaliação é sempre uma NOVA
 * avaliação (`App\Actions\LicoesAprendidas\AvaliarReaplicacaoLicao`),
 * nunca editar/apagar a anterior.
 */
class LicaoAprendidaReaplicacaoAvaliacaoObserver
{
    public function updating(LicaoAprendidaReaplicacaoAvaliacao $avaliacao): void
    {
        throw new AvaliacaoReaplicacaoImutavelException(
            'Uma avaliação já registrada não pode ser alterada — registre uma nova avaliação para corrigir.'
        );
    }

    public function deleting(LicaoAprendidaReaplicacaoAvaliacao $avaliacao): void
    {
        throw new AvaliacaoReaplicacaoImutavelException(
            'Uma avaliação já registrada não pode ser excluída — o histórico é sempre preservado.'
        );
    }
}
