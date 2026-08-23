<?php

namespace App\Observers;

use App\Exceptions\GrdImutavelException;
use App\Models\Grd;

/**
 * Ciclo 18, Etapa 18.5.1.HARDENING — impede que uma Grd Emitida
 * desapareça por `delete()`/`forceDelete()`, mesmo chamado direto no
 * model (nunca confiar só em "a UI/Action futura não vai chamar isso" —
 * a invariância precisa viver abaixo da UI). Mesmo padrão já usado por
 * `App\Observers\AtividadeObserver::updating()` (lança exceção de
 * domínio pra bloquear uma mutação, registrado via `Model::observe()`).
 *
 * `Illuminate\Database\Eloquent\SoftDeletes::forceDelete()` chama
 * internamente `$this->delete()` (confirmado lendo o trait do
 * framework), e `Model::delete()` dispara o evento `deleting` ANTES de
 * `performDeleteOnModel()` — então este único guard, no evento
 * `deleting`, bloqueia as DUAS chamadas (`delete()` soft e
 * `forceDelete()` hard) sem precisar de dois listeners.
 *
 * Rascunho continua livre pra ser excluído (soft ou force) — nenhuma
 * restrição aqui além do status.
 */
class GrdObserver
{
    public function deleting(Grd $grd): void
    {
        if ($grd->estaEmitida()) {
            throw new GrdImutavelException('Esta GRD já foi emitida e não pode ser excluída — histórico é imutável.');
        }
    }
}
