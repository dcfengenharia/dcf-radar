<?php

namespace App\Policies;

use App\Models\Restricao;
use App\Models\User;

class RestricaoPolicy
{
    public function viewAny(User $user, string $obraId): bool
    {
        return $user->temAcessoAObra($obraId);
    }

    public function view(User $user, Restricao $restricao): bool
    {
        return $user->temAcessoAObra($restricao->atividade->obra_id);
    }

    public function create(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'restricoes.quadro', 'criar');
    }

    public function update(User $user, Restricao $restricao): bool
    {
        $obraId = $restricao->atividade->obra_id;

        // O responsável pode atualizar sua própria restrição; quem tem
        // permissão de editar a página pode sempre.
        if ($restricao->responsavel_id === $user->id) {
            return $user->temAcessoAObra($obraId);
        }

        return $user->temPermissaoNaObra($obraId, 'restricoes.quadro', 'editar');
    }

    public function delete(User $user, Restricao $restricao): bool
    {
        return $user->temPermissaoNaObra($restricao->atividade->obra_id, 'restricoes.quadro', 'excluir');
    }

    /**
     * Fase 2B, Seção 17/18 — 'resolver' é capacidade própria, separada de
     * 'editar' (usava o fallback 'editar' antes desta fase). O atalho do
     * responsável pela restrição continua intocado, independente de
     * Perfil.
     */
    public function resolver(User $user, Restricao $restricao): bool
    {
        $obraId = $restricao->atividade->obra_id;

        // O responsável pela restrição pode resolvê-la
        if ($restricao->responsavel_id === $user->id) {
            return $user->temAcessoAObra($obraId);
        }

        return $user->temPermissaoNaObra($obraId, 'restricoes.quadro', 'resolver');
    }

    /**
     * Fase 2B, Seção 17/18 — 'reabrir' é capacidade própria, separada de
     * 'editar' (usava o fallback 'editar' antes desta fase).
     */
    public function reabrir(User $user, Restricao $restricao): bool
    {
        $obraId = $restricao->atividade->obra_id;

        // O responsável pela restrição pode reabri-la
        if ($restricao->responsavel_id === $user->id) {
            return $user->temAcessoAObra($obraId);
        }

        return $user->temPermissaoNaObra($obraId, 'restricoes.quadro', 'reabrir');
    }

    /**
     * Fase 2B, Seção 12/14 — 'comentar' é capacidade própria, separada de
     * 'criar' (que autorizava isso antes desta fase). Backfill garante
     * que todo Perfil que hoje tem 'criar' também ganhou 'comentar'.
     */
    public function comentar(User $user, Restricao $restricao): bool
    {
        return $user->temPermissaoNaObra($restricao->atividade->obra_id, 'restricoes.quadro', 'comentar');
    }

    public function notificar(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'restricoes.quadro', 'editar');
    }
}
