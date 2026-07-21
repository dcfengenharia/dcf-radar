<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Barra o acesso de um usuário marcado como inativo (`users.ativo = false`,
 * toggle do admin da plataforma em /admin/usuarios) — a cada requisição,
 * não só no login: o guard de sessão do Laravel resolve `auth()->user()`
 * do banco a cada request (a sessão só guarda o ID), então checar aqui
 * já cobre uma sessão já aberta no momento em que o admin desativa o
 * usuário, sem precisar de nenhuma invalidação especial de sessão.
 * Comparação estrita (`=== false`) de propósito, não `! $ativo` — mesmo
 * fallback "dado ausente nunca bloqueia" já usado em
 * EnsureTenantAssinaturaAtiva/limiteObras()/etc: um valor null/ausente
 * (ex.: modelo em memória sem esse atributo carregado) nunca deve barrar
 * acesso, só uma desativação explícita.
 * Roda pra todo mundo, inclusive admin da plataforma — a auto-desativação
 * é bloqueada na própria ação de toggle, não aqui.
 */
class BloquearUsuarioInativo
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check() && auth()->user()->ativo === false) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('flash.banner', 'Sua conta foi desativada. Fale com o administrador da plataforma.')
                ->with('flash.bannerStyle', 'danger');
        }

        return $next($request);
    }
}
