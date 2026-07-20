<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Corta acesso à plataforma quando a Assinatura vigente do tenant não
 * concede acesso (App\Enums\StatusAssinatura::concedeAcesso() — hoje
 * `Cancelada`/`Suspensa`, e `Inadimplente` a partir da correção desta
 * fase). Tenant sem NENHUMA Assinatura (situação de todo tenant até
 * o gateway de pagamento existir) nunca é bloqueado por este middleware.
 * Admin da plataforma sempre passa — precisa conseguir entrar pra
 * resolver a pendência da conta.
 */
class EnsureTenantAssinaturaAtiva
{
    public function handle(Request $request, Closure $next)
    {
        $usuario = $request->user();

        if (! $usuario || $usuario->is_platform_admin) {
            return $next($request);
        }

        $assinatura = $usuario->tenant?->assinaturaAtual();

        if ($assinatura && ! $assinatura->estaAtiva()) {
            return redirect()->route('login')
                ->with('flash.banner', 'O acesso desta conta está temporariamente suspenso. Fale com o administrador da sua empresa ou com o suporte.')
                ->with('flash.bannerStyle', 'danger');
        }

        return $next($request);
    }
}
