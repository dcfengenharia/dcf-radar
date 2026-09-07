<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Work;
use App\Support\ImpersonationContext;

class WorkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Pré-produção, Etapa 2 (seção 10/11) — achado real da auditoria:
     * `$user->is_platform_admin` sozinho concedia leitura de QUALQUER obra
     * de QUALQUER tenant, sem nenhuma trilha de auditoria (rota
     * `gestao.obra.show` era alcançável direto, sem nunca clicar em
     * "Entrar como"). Agora o bypass só vale enquanto a sessão tem uma
     * impersonation ATIVA pro tenant DONO desta obra especificamente —
     * "Entrar como" já grava um registro auditado em `Impersonacao`
     * (identidade + motivo + timestamp + IP) antes de conceder o acesso,
     * então todo uso do bypass agora é, por construção, precedido de um
     * evento auditado. Acesso continua controlado, nunca removido.
     */
    public function view(User $user, Work $work): bool
    {
        return $user->temAcessoAObra($work)
            || ($user->is_platform_admin && ImpersonationContext::impersonandoTenant($work->tenant_id));
    }

    public function create(User $user): bool
    {
        return $user->temPermissaoEmAlgumaObraDoTenant('cadastros.obras', 'criar');
    }

    /**
     * Dono da plataforma (is_platform_admin) continua podendo editar
     * qualquer obra — inclui a aba Equipe, usada pra garantir que todo
     * tenant tenha pelo menos um Administrador (ver alterarPerfil()/
     * removerMembro() em ⚡obra-detalhe.blade.php) — mas só DEPOIS de
     * "Entrar como" naquele tenant (mesmo raciocínio de view() acima,
     * Etapa 2 seção 10/11) — nunca mais um bypass incondicional.
     */
    public function update(User $user, Work $work): bool
    {
        return $user->temPermissaoNaObra($work, 'obras.minhas_obras', 'editar')
            || ($user->is_platform_admin && ImpersonationContext::impersonandoTenant($work->tenant_id));
    }

    public function delete(User $user, Work $work): bool
    {
        return $user->temPermissaoNaObra($work, 'obras.minhas_obras', 'excluir');
    }
}
