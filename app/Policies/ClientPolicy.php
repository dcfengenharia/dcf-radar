<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        return $user->tenant_id === $client->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->temPermissaoEmAlgumaObraDoTenant('cadastros.clientes', 'criar');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->tenant_id === $client->tenant_id
            && $user->temPermissaoEmAlgumaObraDoTenant('cadastros.clientes', 'editar');
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->tenant_id === $client->tenant_id
            && $user->temPermissaoEmAlgumaObraDoTenant('cadastros.clientes', 'excluir');
    }
}
