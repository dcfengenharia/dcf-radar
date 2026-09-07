<?php

namespace App\Observers;

use App\Exceptions\ReaplicacaoLicaoImutavelException;
use App\Models\LicaoAprendidaReaplicacao;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 6) — mesmo padrão de `GrdObserver`/
 * `ItemTakeOffObserver`: bloqueia `updating()`/`deleting()`
 * INCONDICIONALMENTE, nunca só quando algum status específico. A
 * identidade de uma reaplicação (tenant/lição/obra/quem/quando/
 * observação inicial) nunca muda depois de criada — nunca é movida pra
 * outra obra/lição, nunca é excluída (soft ou force — `forceDelete()`
 * sempre delega pra `delete()`, que dispara `deleting()` antes de
 * `performDeleteOnModel()`, mesmo mecanismo já documentado em
 * `GrdObserver`).
 */
class LicaoAprendidaReaplicacaoObserver
{
    public function updating(LicaoAprendidaReaplicacao $reaplicacao): void
    {
        throw new ReaplicacaoLicaoImutavelException(
            'Uma reaplicação já registrada não pode ser alterada — corrija registrando uma nova avaliação ou uma nova reaplicação.'
        );
    }

    public function deleting(LicaoAprendidaReaplicacao $reaplicacao): void
    {
        throw new ReaplicacaoLicaoImutavelException(
            'Uma reaplicação já registrada não pode ser excluída — o histórico é sempre preservado.'
        );
    }
}
