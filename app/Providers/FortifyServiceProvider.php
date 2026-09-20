<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auditoria Pré-Produção A1, AUTH-04 — chamado em register(), NUNCA
        // em boot(): Laravel executa register() de TODOS os providers antes
        // de boot() de qualquer um, então esta chamada sempre acontece antes
        // de Laravel\Fortify\FortifyServiceProvider::boot() decidir se
        // registra suas próprias rotas — deterministicamente, independente
        // da ordem relativa entre os dois providers em config/app.php. Sem
        // isso, quem respondia a /login dependia de qual provider bootava
        // por último (achado AUTH-04). As rotas "customizadas" (login/
        // logout/registro/reset de senha/verificação de e-mail/confirmação
        // de senha) já são 100% explícitas em routes/auth.php.
        //
        // Correção (A1.1): a suposição original de que o par confirmação-de-
        // senha/verificação-de-e-mail nos caminhos PADRÃO do Fortify
        // (/user/confirm-password, /email/verify) "nunca é usado nesta
        // stack" estava ERRADA — o próprio autorregistro do Fortify supria
        // esses 2 caminhos silenciosamente até esta chamada existir, e a
        // suíte de testes gerada pelo Jetstream (tests/Feature/
        // EmailVerificationTest.php, tests/Feature/PasswordConfirmationTest.php,
        // fora da pasta Auth/) bate direto neles — restaurados
        // explicitamente (sem nome) em routes/auth.php. Confirmado, por
        // outro lado (lendo vendor/laravel/jetstream/src/Http/Livewire/
        // TwoFactorAuthenticationForm.php e ConfirmsPasswords.php), que a
        // gestão de 2FA (/user/two-factor-*) e o modal de confirmação de
        // senha embutido no próprio Livewire chamam as Actions do Fortify
        // diretamente, em processo, sem HTTP — esses genuinamente não
        // precisam de rota.
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Auditoria Pré-Produção A1, AUTH-01 — vinculado aqui (boot(), nunca
        // register()) pra sempre sobrescrever o binding padrão do Fortify
        // (Laravel\Fortify\FortifyServiceProvider::register(), que roda
        // ANTES de qualquer boot() de qualquer provider) — garante que o
        // admin de plataforma continue sendo redirecionado pra /admin mesmo
        // depois de completar o desafio de 2FA, mesma lógica já usada em
        // AuthenticatedSessionController::store().
        $this->app->singleton(TwoFactorLoginResponseContract::class, \App\Http\Responses\TwoFactorLoginResponse::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
