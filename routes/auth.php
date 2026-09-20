<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ConviteController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Auditoria Pré-Produção A1, AUTH-01/AUTH-04 — desafio de 2FA no login,
    // usando o controller OFICIAL do Fortify (nunca reimplementado) — mesmo
    // nome/caminho/middleware que Laravel\Fortify\FortifyServiceProvider
    // registraria sozinho, agora explícito aqui porque
    // App\Providers\FortifyServiceProvider::register() chama
    // Fortify::ignoreRoutes(). Consumido por
    // resources/views/auth/two-factor-challenge.blade.php, já existente.
    Route::get('two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])
        ->name('two-factor.login');

    Route::post('two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.login.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    Route::get('convite/{token}', [ConviteController::class, 'show'])
        ->name('convite.show');

    Route::post('convite/{token}', [ConviteController::class, 'aceitar'])
        ->middleware('throttle:10,1')
        ->name('convite.aceitar');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Auditoria Pré-Produção A1.1 — correção de regressão introduzida pelo
    // próprio AUTH-04: além das rotas acima (convenção Breeze do app,
    // nomeadas e usadas em toda redireção via route() da aplicação), o
    // projeto SEMPRE teve, em paralelo, a suíte de testes gerada pelo
    // próprio Jetstream/Fortify (tests/Feature/EmailVerificationTest.php,
    // tests/Feature/PasswordConfirmationTest.php — fora da pasta Auth/)
    // que bate direto nos caminhos PADRÃO do Fortify (/email/verify,
    // /user/confirm-password — ver vendor/laravel/fortify/routes/routes.php).
    // Antes de Fortify::ignoreRoutes() (AUTH-04), essas 2 rotas eram
    // silenciosamente supridas pelo autorregistro do próprio Fortify,
    // coexistindo sem conflito com as rotas nomeadas abaixo. Com
    // ignoreRoutes(), esse suprimento sumiu e ambas passaram a 404 — 4
    // testes historicamente verdes começaram a falhar (achado na validação
    // final da A1.1, não fixação/desativação de teste — as rotas é que
    // faltavam). Restauradas aqui SEM NOME (evita disputar a resolução de
    // verification.notice/password.confirm, que a app inteira usa via
    // route() apontando pras rotas Breeze acima), reaproveitando os MESMOS
    // controllers — nunca uma segunda implementação.
    Route::get('email/verify', EmailVerificationPromptController::class);

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::get('user/confirm-password', [ConfirmablePasswordController::class, 'show']);

    Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
