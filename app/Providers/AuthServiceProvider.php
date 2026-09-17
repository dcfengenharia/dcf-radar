<?php

namespace App\Providers;

use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\Client;
use App\Models\CronogramaImportacao;
use App\Models\CandidatoLicaoAprendida;
use App\Models\Disciplina;
use App\Models\InconsistenciaAvanco;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\PacoteTrabalho;
use App\Models\PlanoAcao;
use App\Models\Report;
use App\Models\RequisicaoPlanejamento;
use App\Models\Restricao;
use App\Models\Work;
use App\Policies\AtividadePolicy;
use App\Policies\CategoriaRestricaoPolicy;
use App\Policies\ClientPolicy;
use App\Policies\CronogramaImportacaoPolicy;
use App\Policies\DisciplinaPolicy;
use App\Policies\InconsistenciaAvancoPolicy;
use App\Policies\LicaoAprendidaPolicy;
use App\Policies\LicaoAprendidaReaplicacaoPolicy;
use App\Policies\PacoteTrabalhoPolicy;
use App\Policies\PlanoAcaoPolicy;
use App\Policies\ReportPolicy;
use App\Policies\RequisicaoPlanejamentoPolicy;
use App\Policies\RestricaoPolicy;
use App\Policies\WorkPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ImpersonationContext;
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
        CronogramaImportacao::class => CronogramaImportacaoPolicy::class,
        PlanoAcao::class => PlanoAcaoPolicy::class,
        InconsistenciaAvanco::class => InconsistenciaAvancoPolicy::class,
        RequisicaoPlanejamento::class => RequisicaoPlanejamentoPolicy::class,
        LicaoAprendida::class => LicaoAprendidaPolicy::class,
        // Ciclo 23, Etapa 23.3 — mesma Policy governa os dois models
        // (candidato é mecanismo de revisão do mesmo domínio de
        // governança, reaproveita o slug gestao.licoes-aprendidas).
        CandidatoLicaoAprendida::class => LicaoAprendidaPolicy::class,
        // Ciclo 23, Etapa 23.5.B — Policy própria (reaproveita os slugs/
        // ações `criar`/`editar` de gestao.licoes-aprendidas, nunca uma
        // ação nova no catálogo).
        LicaoAprendidaReaplicacao::class => LicaoAprendidaReaplicacaoPolicy::class,
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
        // autoconceda acesso a esta tela). Fase 2C — mesmo bypass já
        // usado por WorkPolicy::view()/update() (Pré-produção, Etapa 2):
        // o admin da plataforma só entra aqui durante impersonation
        // ATIVA e auditada daquele tenant específico ("Entrar como" já
        // grava `Impersonacao`) — nunca um bypass incondicional de
        // is_platform_admin. Sem isso, um Admin trancado (último Admin
        // de um tenant, ver GuardUltimoAdmin) nunca teria como ser
        // socorrido: a própria Matriz de Acessos/Perfis de Acesso exige
        // ser o criador do tenant pra sequer abrir a tela.
        Gate::define('gerenciar-perfis-acesso', function (User $user) {
            $tenantId = TenantContext::currentId();
            $tenant = $tenantId ? Tenant::find($tenantId) : null;

            if ($tenant === null) {
                return false;
            }

            return $user->podeGerenciarTenant($tenant)
                || ($user->is_platform_admin && ImpersonationContext::impersonandoTenant($tenant->id));
        });
    }
}
