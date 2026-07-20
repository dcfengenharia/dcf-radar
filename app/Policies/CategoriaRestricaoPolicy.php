<?php

namespace App\Policies;

use App\Models\CategoriaRestricao;
use App\Models\User;

class CategoriaRestricaoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CategoriaRestricao $categoria): bool
    {
        return $user->tenant_id === $categoria->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'criar');
    }

    public function update(User $user, CategoriaRestricao $categoria): bool
    {
        return $user->tenant_id === $categoria->tenant_id
            && $user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'editar');
    }

    public function delete(User $user, CategoriaRestricao $categoria): bool
    {
        return $user->tenant_id === $categoria->tenant_id
            && $user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'excluir');
    }
}
