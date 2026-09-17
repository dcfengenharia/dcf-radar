<?php

namespace App\Policies;

use App\Models\Atividade;
use App\Models\User;

class AtividadePolicy
{
    public function viewAny(User $user, string $obraId): bool
    {
        return $user->temAcessoAObra($obraId);
    }

    public function view(User $user, Atividade $atividade): bool
    {
        return $user->temAcessoAObra($atividade->obra_id);
    }

    public function create(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'restricoes.lookahead', 'criar');
    }

    public function update(User $user, Atividade $atividade): bool
    {
        return $user->temPermissaoNaObra($atividade->obra_id, 'restricoes.lookahead', 'editar');
    }

    public function delete(User $user, Atividade $atividade): bool
    {
        return $user->temPermissaoNaObra($atividade->obra_id, 'restricoes.lookahead', 'excluir');
    }

    /**
     * Fase 2B, Seção 12/14 — 'comentar' é capacidade própria, separada de
     * 'editar' (exemplo literal do pedido — usava 'editar' antes desta
     * fase). Backfill garante que todo Perfil que hoje tem 'editar'
     * também ganhou 'comentar'; editar deixa de ser exigido pra comentar
     * daqui em diante.
     */
    public function comentar(User $user, Atividade $atividade): bool
    {
        return $user->temPermissaoNaObra($atividade->obra_id, 'restricoes.lookahead', 'comentar');
    }
}
