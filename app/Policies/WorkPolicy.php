<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Work;

class WorkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Work $work): bool
    {
        return $user->is_platform_admin || $user->temAcessoAObra($work);
    }

    public function create(User $user): bool
    {
        return $user->temPermissaoEmAlgumaObraDoTenant('cadastros.obras', 'criar');
    }

    /**
     * Dono da plataforma (is_platform_admin) sempre pode editar
     * qualquer obra — inclui a aba Equipe, usada pra garantir que todo
     * tenant tenha pelo menos um Administrador (ver alterarPerfil()/
     * removerMembro() em ⚡obra-detalhe.blade.php).
     */
    public function update(User $user, Work $work): bool
    {
        return $user->is_platform_admin || $user->temPermissaoNaObra($work, 'obras.minhas_obras', 'editar');
    }

    public function delete(User $user, Work $work): bool
    {
        return $user->temPermissaoNaObra($work, 'obras.minhas_obras', 'excluir');
    }
}
