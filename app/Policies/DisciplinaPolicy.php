<?php

namespace App\Policies;

use App\Models\Disciplina;
use App\Models\User;

class DisciplinaPolicy
{
    // Disciplina é configuração de tenant — qualquer obra do tenant pode ver
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Disciplina $disciplina): bool
    {
        return $user->tenant_id === $disciplina->tenant_id;
    }

    // Disciplina não tem tela própria — reaproveita a permissão de Tipos
    // de Restrição, já que nasce do mesmo fluxo de classificação.
    public function create(User $user): bool
    {
        return $user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'criar');
    }

    public function update(User $user, Disciplina $disciplina): bool
    {
        return $user->tenant_id === $disciplina->tenant_id
            && $user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'editar');
    }

    public function delete(User $user, Disciplina $disciplina): bool
    {
        return $this->update($user, $disciplina);
    }
}
