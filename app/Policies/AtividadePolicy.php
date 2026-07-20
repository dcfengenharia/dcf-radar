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

    public function comentar(User $user, Atividade $atividade): bool
    {
        return $user->temPermissaoNaObra($atividade->obra_id, 'restricoes.lookahead', 'editar');
    }
}
