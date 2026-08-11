<?php

namespace App\Providers;

use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\Client;
use App\Models\Disciplina;
use App\Models\PacoteTrabalho;
use App\Models\PlanoAcao;
use App\Models\Report;
use App\Models\Restricao;
use App\Models\Work;
use App\Policies\AtividadePolicy;
use App\Policies\CategoriaRestricaoPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DisciplinaPolicy;
use App\Policies\PacoteTrabalhoPolicy;
use App\Policies\PlanoAcaoPolicy;
use App\Policies\ReportPolicy;
use App\Policies\RestricaoPolicy;
use App\Policies\WorkPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Client::class => ClientPolicy::class,
        Work::class => WorkPolicy::class,
        Atividade::class => AtividadePolicy::class,
        Restricao::class => RestricaoPolicy::class,
        PacoteTrabalho::class => PacoteTrabalhoPolicy::class,
        Disciplina::class => DisciplinaPolicy::class,
        CategoriaRestricao::class => CategoriaRestricaoPolicy::class,
        Report::class => ReportPolicy::class,
        PlanoAcao::class => PlanoAcaoPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('acessar-admin-plataforma', fn (User $user) => $user->is_platform_admin === true);

        Gate::define('gerenciar-empresa-ativa', function (User $user) {
            $tenantId = TenantContext::currentId();
            $tenant = $tenantId ? Tenant::find($tenantId) : null;

            return $tenant !== null && $user->podeGerenciarTenant($tenant);
        });

        // Perfis/permissões definem QUEM PODE O QUÊ — só o criador do
        // tenant mexe nisso, nunca um perfil (evita que um perfil se
        // autoconceda acesso a esta tela).
        Gate::define('gerenciar-perfis-acesso', function (User $user) {
            $tenantId = TenantContext::currentId();
            $tenant = $tenantId ? Tenant::find($tenantId) : null;

            return $tenant !== null && $user->podeGerenciarTenant($tenant);
        });
    }
}
