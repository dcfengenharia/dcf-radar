<?php

namespace App\Observers;

use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Models\ReservaEstoque;

/**
 * Ciclo 20, Etapa 20.2 — Seção 22/43: "não usar delete como liberação" /
 * "Reserva histórica: não delete". Mesmo padrão exato de
 * App\Observers\ListaEngenhariaObserver (bloqueia SEMPRE,
 * incondicionalmente, mesmo Ativa) — liberação é a única transição de
 * domínio válida (App\Actions\Estoque\LiberarReservaEstoque, um UPDATE),
 * nunca DELETE. Cobre `delete()` e `forceDelete()` (que sempre delega
 * pra `delete()`).
 *
 * **Ciclo 20, Etapa 20.2.CORREÇÃO — fecha o Achado C2 da auditoria
 * adversarial**: `updating()` bloqueia SEMPRE, incondicionalmente,
 * qualquer `save()`/`update()` de INSTÂNCIA (nenhum campo é exceção —
 * uma Reserva é um fato de compromisso, nunca uma row "corrigível").
 * Isso é seguro e nunca quebra a liberação oficial porque
 * `App\Actions\Estoque\LiberarReservaEstoque::execute()` usa
 * `ReservaEstoque::where('id', ...)->where('status', 'ativa')->update([...])`
 * — um mass-update via Query Builder, que o Eloquent NUNCA traduz em
 * eventos de model (`saving`/`updating`/`updated`/`saved`) — só
 * `$model->save()`/`$model->update()` chamados sobre uma instância já
 * hidratada disparam esses eventos. Por isso o único writer oficial de
 * transição de status é estruturalmente IMUNE a este Observer, sem
 * precisar de nenhuma exceção/flag especial nele — nunca enfraquecemos
 * a atomicidade do `WHERE status = 'ativa'` só pra fazer o Observer
 * "deixar passar".
 */
class ReservaEstoqueObserver
{
    public function updating(ReservaEstoque $reserva): void
    {
        throw new ReservaEstoqueInvalidaException(
            'Uma Reserva de Estoque é um fato de compromisso imutável — nenhum campo pode ser reescrito depois de criada. '
            . 'Libere esta reserva e crie uma nova, se necessário.'
        );
    }

    public function deleting(ReservaEstoque $reserva): void
    {
        throw new ReservaEstoqueInvalidaException(
            'Uma Reserva de Estoque nunca é excluída — libere-a em vez de excluir, preservando o histórico.'
        );
    }
}
