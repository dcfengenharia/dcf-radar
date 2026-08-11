<?php

namespace App\Policies;

use App\Models\PlanoAcao;
use App\Models\User;

/**
 * Slug independente `restricoes.plano_acao` (Fase 4.2, decisão do usuário —
 * não reaproveita `obras.importar_cronograma`, responsabilidades
 * diferentes). Isolamento de tenant vem de graça do global scope de
 * BelongsToTenant; isolamento de obra é o que `temPermissaoNaObra()`
 * garante aqui, mesmo padrão de `CronogramaImportacaoPolicy`/`ReportPolicy`.
 */
class PlanoAcaoPolicy
{
    public function view(User $user, PlanoAcao $acao): bool
    {
        return $user->temPermissaoNaObra($acao->obra_id, 'restricoes.plano_acao', 'ver');
    }

    /** Sem model ainda (ação sendo criada) — recebe o obra_id de destino diretamente. */
    public function create(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'restricoes.plano_acao', 'criar');
    }

    public function update(User $user, PlanoAcao $acao): bool
    {
        return $user->temPermissaoNaObra($acao->obra_id, 'restricoes.plano_acao', 'editar');
    }

    public function delete(User $user, PlanoAcao $acao): bool
    {
        return $user->temPermissaoNaObra($acao->obra_id, 'restricoes.plano_acao', 'excluir');
    }
}
