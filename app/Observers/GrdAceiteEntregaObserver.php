<?php

namespace App\Observers;

use App\Exceptions\GrdAceiteImutavelException;
use App\Models\GrdAceiteEntrega;

/**
 * Ciclo 18, Etapa 18.5.9.CORREÇÃO — achado C da auditoria adversarial da
 * 18.5.9: `GrdAceiteEntrega` não usa `SoftDeletes` e não tinha nenhum guard
 * — `$aceite->delete()` removia a linha fisicamente, sem exceção, apesar do
 * próprio docblock do model afirmar "evidência histórica e imutável". Mesmo
 * padrão já usado por `App\Observers\GrdObserver` (que protege `Grd`
 * Emitida): um único guard no evento `deleting` bloqueia as DUAS chamadas —
 * `Model::forceDelete()` (mesmo em models SEM `SoftDeletes`, que têm o
 * `forceDelete()` "de segurança" definido no próprio `Model` base,
 * confirmado lendo `vendor/laravel/framework/.../Model.php`) sempre delega
 * pra `$this->delete()`, que dispara `deleting` ANTES de
 * `performDeleteOnModel()` — então bloquear `deleting` cobre as duas.
 *
 * **Invalidar != deletar**: um aceite já invalidado (`invalidado_em`
 * preenchido) continua sendo bloqueado igual a um ativo — a exclusão nunca
 * é permitida, independente do status. Nenhuma exceção condicional aqui.
 */
class GrdAceiteEntregaObserver
{
    public function deleting(GrdAceiteEntrega $aceite): void
    {
        throw new GrdAceiteImutavelException(
            'Registros de aceite de entrega são evidências históricas e não podem ser excluídos.'
        );
    }
}
