<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * Auditoria Pré-Produção A1, AUTH-01 — antes, esta ação chamava
     * $request->authenticate() (Auth::attempt() puro), autenticando por
     * completo mesmo um usuário com 2FA confirmado (o 2FA existia, mas era
     * puramente cosmético — nunca era de fato exigido no login). Agora usa
     * o mecanismo oficial do Fortify já parcialmente wireado neste projeto
     * desde FortifyServiceProvider::redirectUserForTwoFactorAuthenticationUsing()
     * — nunca reimplementa verificação de código TOTP/recovery code, só
     * decide, ANTES de autenticar de verdade, se o usuário deve ser
     * redirecionado pro desafio oficial (rota two-factor.login, controller
     * Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController,
     * ambos 100% do Fortify).
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        // Mesmo rate limiting de sempre (LoginRequest::ensureIsNotRateLimited(),
        // mesma chave email|ip usada pelo LoginRateLimiter interno do
        // Fortify) — nunca enfraquecido, sempre checado antes de qualquer
        // tentativa de credencial.
        $request->ensureIsNotRateLimited();

        return app(RedirectsIfTwoFactorAuthenticatable::class)->handle(
            $request,
            function (LoginRequest $request) {
                // Usuário sem 2FA confirmado (ou já validado pelo passo
                // acima sem 2FA aplicável) — autentica normalmente, mesmo
                // fluxo de sempre.
                if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
                    RateLimiter::hit($request->throttleKey());

                    throw ValidationException::withMessages([
                        'email' => trans('auth.failed'),
                    ]);
                }

                RateLimiter::clear($request->throttleKey());

                $request->session()->regenerate();

                if (Auth::user()->is_platform_admin) {
                    return redirect()->intended(route('admin.dashboard'));
                }

                return redirect()->intended(RouteServiceProvider::HOME);
            }
        );
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
