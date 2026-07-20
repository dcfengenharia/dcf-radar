<?php

namespace App\Policies;

use App\Models\PacoteTrabalho;
use App\Models\User;

class PacoteTrabalhoPolicy
{
    public function viewAny(User $user, string $obraId): bool
    {
        return $user->temAcessoAObra($obraId);
    }

    public function view(User $user, PacoteTrabalho $pacote): bool
    {
        return $user->temAcessoAObra($pacote->obra_id);
    }

    public function create(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'obras.linhas_base', 'criar');
    }

    public function update(User $user, PacoteTrabalho $pacote): bool
    {
        return $user->temPermissaoNaObra($pacote->obra_id, 'obras.linhas_base', 'editar');
    }

    public function delete(User $user, PacoteTrabalho $pacote): bool
    {
        return $user->temPermissaoNaObra($pacote->obra_id, 'obras.linhas_base', 'excluir');
    }
}
