<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// SITE
Route::get('/', function () {
    return view('site.index');
});

// CLIENTE — acesso público (sem login) a um Report específico, só via
// link assinado gerado em ⚡relatorio-detalhe.blade.php::gerarLinkCliente().
Route::get('/cliente/relatorio/{report}', [\App\Http\Controllers\ClienteRelatorioPublicoController::class, 'show'])
    ->middleware('signed')
    ->name('cliente.relatorio.publico');

// WEBHOOKS — o Mercado Pago bate aqui direto (visitante não-autenticado,
// sem token CSRF); autenticidade é validada via HMAC no próprio
// controller (ver App\Http\Middleware\VerifyCsrfToken::$except).
Route::post('/webhooks/mercadopago', [\App\Http\Controllers\MercadoPagoWebhookController::class, 'handle'])
    ->name('webhooks.mercadopago');

// PLATAFORMA

Route::middleware(['auth', 'verified', 'assinatura.ativa'])->prefix('app')->group(function () {

    // página inicial da plataforma
    Route::get('/home', function () {
        return view('app.index');
    })->name('app.home');

    // notificações
    Route::get('/notificacoes', fn() => view('app.notificacoes.index'))->name('notificacoes.index');

    // configuração inicial (onboarding) — não fica no menu comum,
    // só acessível pelo botão do menu lateral ou pelo aviso da navbar
    Route::get('/onboarding', function () {
        return view('app.onboarding');
    })->name('app.onboarding');

    // EMPRESA (tenant ativo) — dados/perfil e troca entre empresas da conta
    Route::get('/empresa', function () {
        $tenantId = \App\Support\TenantContext::currentId();
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : null;

        abort_unless($tenant && auth()->user()->podeGerenciarTenant($tenant), 403);

        return view('app.empresa.show', ['tenant' => $tenant]);
    })->name('app.empresa.show');

    Route::post('/empresa/trocar/{tenant}', function (\App\Models\Tenant $tenant) {
        abort_unless(auth()->user()->tenants()->where('tenants.id', $tenant->id)->exists(), 403);

        \App\Support\TenantSwitchContext::set($tenant);

        return redirect()->route('app.home');
    })->name('app.empresa.trocar');

    // ASSINATURA (autoatendimento) — mesmo tenant ativo/mesma permissão
    // da página Dados da Empresa.
    Route::get('/empresa/assinatura', function () {
        $tenantId = \App\Support\TenantContext::currentId();
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : null;

        abort_unless($tenant && auth()->user()->podeGerenciarTenant($tenant), 403);

        return view('app.empresa.assinatura', ['tenant' => $tenant]);
    })->name('app.empresa.assinatura');

    // PERFIS DE ACESSO — só o criador do tenant gerencia perfis/permissões
    Route::get('/perfis-acesso', function () {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        return view('app.gestao.perfis-acesso');
    })->name('gestao.perfis-acesso');

    // cadastros
    Route::prefix('cadastros')->group(function () {

        // clientes
        Route::get('/clientes', function () {
            return view('app.clientes.index');
        })->name('cadastros.clientes.index');

        Route::get('/obras', function () {
            return view('app.obras.index');
        })->name('cadastros.obras.index');

        Route::get('/categorias-restricao', function () {
            return view('app.cadastros.categorias-restricao');
        })->name('cadastros.categorias-restricao');

        Route::get('/itens-prontidao', function () {
            return view('app.cadastros.itens-prontidao');
        })->name('cadastros.itens-prontidao');

        Route::get('/convite-config', function () {
            return view('app.cadastros.convite-config');
        })->name('cadastros.convite-config');

        Route::get('/fornecedores', function () {
            return view('app.cadastros.fornecedores');
        })->name('cadastros.fornecedores');

        Route::get('/feriados', function () {
            return view('app.cadastros.feriados');
        })->name('cadastros.feriados');

        Route::get('/fluxos-suprimento', function () {
            return view('app.cadastros.fluxos-suprimento');
        })->name('cadastros.fluxos-suprimento');

        Route::get('/status-documentos', function () {
            return view('app.cadastros.status-documentos');
        })->name('cadastros.status-documentos');

    })->name('app.cadastros');

    // GESTÃO
    Route::prefix('gestao')->group(function () {
        Route::get('/minhas-obras', function () {
            return view('app.gestao.minhas-obras');
        })->name('gestao.minhas-obras');

        Route::get('/obras/{obra}', function (\App\Models\Work $obra) {
            abort_unless(auth()->user()->is_platform_admin || auth()->user()->temAcessoAObra($obra), 403);
            return view('app.gestao.obra-detalhe', ['obra' => $obra]);
        })->name('gestao.obra.show');

        Route::get('/benchmarking', function () {
            return view('app.gestao.benchmarking-obras');
        })->name('gestao.benchmarking');
    })->name('app.gestao');

    // RADAR — requer obra selecionada na sessão
    Route::prefix('radar')->group(function () {

        // Entrada numa obra: salva contexto e redireciona ao Quadro —
        // lookup manual (não route-model-binding) pra poder responder
        // com mensagem amigável em vez de 404/403 cru (usado por links
        // de e-mail, que podem chegar com obra inválida ou de outro
        // tenant). $responsavel (opcional) repassa pro filtro do Quadro.
        Route::get('/entrar/{obraId}', function (string $obraId, \Illuminate\Http\Request $request) {
            $obra = \App\Models\Work::find($obraId);

            if (! $obra) {
                return redirect()->route('gestao.minhas-obras')
                    ->with('flash.banner', 'Esta obra não existe, foi removida, ou pertence a outra empresa da sua conta — tente trocar de empresa e acessar de novo.')
                    ->with('flash.bannerStyle', 'danger');
            }

            if (! $request->user()->temAcessoAObra($obra)) {
                return redirect()->route('gestao.minhas-obras')
                    ->with('flash.banner', 'Você não tem acesso a essa obra.')
                    ->with('flash.bannerStyle', 'danger');
            }

            \App\Support\ObraContext::set($obra);

            return redirect()->route('radar.restricoes', $request->only('responsavel'));
        })->name('radar.entrar');

        Route::middleware('obra.context')->group(function () {
            Route::get('/dashboard', fn() => view('app.radar.dashboard'))
                ->name('radar.dashboard');

            Route::get('/lookahead', fn() => view('app.radar.lookahead'))
                ->name('radar.lookahead');

            Route::get('/restricoes', fn() => view('app.radar.restricoes'))
                ->name('radar.restricoes');

            Route::get('/plano-semanal', fn() => view('app.radar.plano-semanal'))
                ->name('radar.plano-semanal');

            Route::get('/minhas-programacoes', fn() => view('app.radar.programacoes'))
                ->name('radar.programacoes');

            Route::get('/causas', fn() => view('app.radar.causas'))
                ->name('radar.causas');

            Route::get('/matriz', fn() => view('app.radar.matriz'))
                ->name('radar.matriz');

            Route::get('/relatorios-restricoes', fn() => view('app.radar.relatorios-restricoes'))
                ->name('radar.relatorios-restricoes');

            Route::get('/cronograma', fn() => view('app.radar.cronograma'))
                ->name('radar.cronograma');

            Route::get('/curvas', fn() => view('app.radar.curvas'))
                ->name('radar.curvas');

            Route::get('/linhas-base', fn() => view('app.radar.linhas-base'))
                ->name('radar.linhas-base');

            Route::get('/relatorios', fn() => view('app.radar.relatorios'))
                ->name('radar.relatorios');

            Route::get('/relatorios/novo', fn() => view('app.radar.relatorio-novo'))
                ->name('radar.relatorios.novo');

            Route::get('/relatorios/importar-avanco', fn() => view('app.radar.relatorio-importar-avanco'))
                ->name('radar.relatorios.importar-avanco');

            Route::get('/relatorios/{report}', function (\App\Models\Report $report) {
                abort_unless(auth()->user()->can('view', $report), 403);
                return view('app.radar.relatorio-detalhe', ['report' => $report]);
            })->name('radar.relatorios.show');

            Route::get('/suprimentos', fn() => view('app.radar.suprimentos'))
                ->name('radar.suprimentos');
        });

    });

    // ENGENHARIA — página com seletor de obra próprio (mesmo padrão dos
    // Cadastros), não depende de uma obra ativa na sessão.
    Route::prefix('engenharia')->group(function () {
        Route::get('/pacotes', fn() => view('app.engenharia.pacotes'))
            ->name('engenharia.pacotes');
    });

});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', fn () => view('profile.show'))->name('profile.show');
});

// ADMINISTRAÇÃO DA PLATAFORMA — dono do negócio, fora do escopo de qualquer tenant
Route::middleware(['auth', 'verified', 'platform.admin'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/', fn () => view('admin.dashboard'))->name('dashboard');

    // Tenants (contas)
    Route::get('/tenants', fn () => view('admin.tenants.index'))->name('tenants.index');

    Route::get('/tenants/{tenant}', function (string $tenant) {
        return view('admin.tenants.show', [
            'tenant' => \App\Models\Tenant::withTrashed()->findOrFail($tenant),
        ]);
    })->name('tenants.show');

    Route::post('/tenants/{tenant}/impersonar', function (string $tenant) {
        $tenant = \App\Models\Tenant::withTrashed()->findOrFail($tenant);
        \App\Support\ImpersonationContext::start($tenant);

        return redirect()->route('app.home')
            ->with('flash.banner', 'Você está navegando como ' . $tenant->name . '.')
            ->with('flash.bannerStyle', 'warning');
    })->name('tenants.impersonar');

    // Usuários (cross-tenant)
    Route::get('/usuarios', fn () => view('admin.usuarios.index'))->name('usuarios.index');

    // Planos
    Route::get('/planos', fn () => view('admin.planos.index'))->name('planos.index');

    // Avisos da Plataforma (popup importante mostrado a qualquer usuário logado)
    Route::get('/avisos', fn () => view('admin.avisos.index'))->name('avisos.index');

    // Encerrar impersonation
    Route::post('/impersonar/parar', function () {
        \App\Support\ImpersonationContext::stop();

        return redirect()->route('admin.tenants.index')
            ->with('flash.banner', 'Você voltou ao modo administrador.')
            ->with('flash.bannerStyle', 'success');
    })->name('impersonar.parar');
});

require __DIR__.'/auth.php';

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});
