<?php

namespace App\Observers;

use App\Exceptions\GrdRecolhimentoInvalidoException;
use App\Models\GrdRecolhimento;

/**
 * Auditoria Pré-Produção A1, DB-03 — o docblock de GrdRecolhimento já
 * afirmava "evento append-only... nunca editado nem apagado", mas nenhum
 * Observer garantia isso (achado real: `$recolhimento->update([...])`/
 * `->delete()` passavam sem exceção). Mesmo padrão exato de
 * ContagemInventarioObserver/RecebimentoPedidoParcelaObserver — barreira
 * SEMPRE a nível de APLICAÇÃO (Eloquent Observer): nunca intercepta
 * `GrdRecolhimento::where(...)->update()`/`->delete()` via Query Builder
 * nem `DB::table('grd_recolhimentos')->...` cru — isso continua sendo API
 * proibida por convenção, nunca fechada por trigger/constraint de banco
 * (mesma limitação estrutural já documentada pra outros ledgers do
 * projeto). O único escritor de produção é
 * App\Actions\Engenharia\RegistrarRecolhimento, que só faz `create()`.
 */
class GrdRecolhimentoObserver
{
    public function updating(GrdRecolhimento $recolhimento): void
    {
        throw new GrdRecolhimentoInvalidoException(
            'Um recolhimento já registrado nunca pode ser alterado — registre um novo evento.'
        );
    }

    public function deleting(GrdRecolhimento $recolhimento): void
    {
        throw new GrdRecolhimentoInvalidoException(
            'Um recolhimento já registrado nunca pode ser excluído.'
        );
    }
}
