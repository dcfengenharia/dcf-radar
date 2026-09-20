<?php

namespace App\Http\Responses;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * Auditoria Pré-Produção A1, AUTH-01 — a implementação padrão do Fortify
 * (Laravel\Fortify\Http\Responses\TwoFactorLoginResponse) não sabe que este
 * projeto redireciona admin de plataforma pra /admin — sem esta resposta
 * customizada, um admin com 2FA confirmado cairia em /app/home depois de
 * completar o desafio, em vez de /admin/dashboard, quebrando a paridade com
 * o fluxo sem 2FA (AuthenticatedSessionController::store()). Vinculada em
 * FortifyServiceProvider::boot() (nunca register()), pra sempre sobrescrever
 * o binding padrão do Fortify — que também é feito em register(), então só
 * um binding em boot() garante vencer, independente da ordem relativa entre
 * os dois providers.
 */
class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        if (Auth::user()->is_platform_admin) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
