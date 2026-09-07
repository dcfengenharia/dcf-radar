<?php

namespace App\Observers;

use App\Exceptions\ReaplicacaoLicaoImutavelException;
use App\Models\LicaoAprendidaReaplicacaoContexto;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 5/6) — o contexto operacional nasce
 * junto da reaplicação, na mesma transação, e nunca é editado depois —
 * mesma rigidez de identidade já aplicada à própria reaplicação
 * (`LicaoAprendidaReaplicacaoObserver`). Exclusão via `forceDelete()`
 * cru continua fisicamente possível só por cascade quando a
 * reaplicação-pai é removida (nunca acontece em produção — bloqueada
 * pelo Observer irmão) — aqui só se bloqueia `update()`/`delete()`
 * direto do próprio contexto.
 */
class LicaoAprendidaReaplicacaoContextoObserver
{
    public function updating(LicaoAprendidaReaplicacaoContexto $contexto): void
    {
        throw new ReaplicacaoLicaoImutavelException(
            'O contexto de uma reaplicação já registrada não pode ser alterado.'
        );
    }

    public function deleting(LicaoAprendidaReaplicacaoContexto $contexto): void
    {
        throw new ReaplicacaoLicaoImutavelException(
            'O contexto de uma reaplicação já registrada não pode ser excluído.'
        );
    }
}
