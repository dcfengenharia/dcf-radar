<?php

namespace App\Observers;

use App\Exceptions\PlanoAcaoReconciliacaoImutavelException;
use App\Models\PlanoAcaoReconciliacao;

/**
 * Auditoria Pré-Produção A1, DB-03 — o docblock de PlanoAcaoReconciliacao
 * já afirmava "log append-only... nunca editado depois de criado", mas
 * nenhum Observer garantia isso (achado real: `update()`/`delete()`
 * passavam sem exceção). Mesmo padrão exato de GrdRecolhimentoObserver —
 * barreira SEMPRE a nível de APLICAÇÃO (Eloquent Observer): nunca
 * intercepta `PlanoAcaoReconciliacao::where(...)->update()`/`->delete()`
 * via Query Builder nem `DB::table('plano_acao_reconciliacoes')->...` cru
 * — isso continua sendo API proibida por convenção, nunca fechada por
 * trigger/constraint de banco. O único escritor de produção é
 * App\Support\HealthCheck\PlanoAcao\PlanoAcaoReconciliador, que só faz
 * `create()`.
 */
class PlanoAcaoReconciliacaoObserver
{
    public function updating(PlanoAcaoReconciliacao $reconciliacao): void
    {
        throw new PlanoAcaoReconciliacaoImutavelException(
            'Um evento de reconciliação já registrado nunca pode ser alterado.'
        );
    }

    public function deleting(PlanoAcaoReconciliacao $reconciliacao): void
    {
        throw new PlanoAcaoReconciliacaoImutavelException(
            'Um evento de reconciliação já registrado nunca pode ser excluído.'
        );
    }
}
