<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A1, AUTH-01/AUTH-04 — prova adversarial de que o
 * login com 2FA confirmado exige de fato o desafio oficial do Fortify
 * (nunca autentica direto), e de que /login continua respondido pelo
 * controller da aplicação, nunca pelo Fortify silenciosamente, agora que
 * FortifyServiceProvider::register() chama Fortify::ignoreRoutes().
 */
class TwoFactorLoginChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function ativarDoisFatoresConfirmados(User $user): string
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => Crypt::encrypt($secret),
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode([
                'recovery-code-um', 'recovery-code-dois',
            ])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $secret;
    }

    public function test_ambos_login_e_two_factor_login_sao_respondidos_pelos_controllers_esperados(): void
    {
        $rotaLogin = Route::getRoutes()->match(Request::create('/login', 'POST'));
        $this->assertSame(
            \App\Http\Controllers\Auth\AuthenticatedSessionController::class.'@store',
            $rotaLogin->getActionName()
        );

        $rotaDesafio = Route::getRoutes()->match(Request::create('/two-factor-challenge', 'POST'));
        $this->assertSame(
            \Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController::class.'@store',
            $rotaDesafio->getActionName()
        );
    }

    public function test_usuario_sem_2fa_confirmado_autentica_direto_sem_desafio(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_usuario_com_2fa_confirmado_nao_autentica_direto_e_e_desafiado(): void
    {
        $user = User::factory()->create();
        $this->ativarDoisFatoresConfirmados($user);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // Senha correta NÃO autentica diretamente — precisa do desafio.
        $this->assertGuest();
        $response->assertRedirect(route('two-factor.login'));
        $this->assertEquals($user->id, session('login.id'));
    }

    public function test_codigo_valido_conclui_o_login_apos_o_desafio(): void
    {
        $user = User::factory()->create();
        $secret = $this->ativarDoisFatoresConfirmados($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $codigoValido = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->post('/two-factor-challenge', ['code' => $codigoValido]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_codigo_invalido_nao_autentica(): void
    {
        $user = User::factory()->create();
        $this->ativarDoisFatoresConfirmados($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => '000000']);

        $this->assertGuest();
    }

    public function test_recovery_code_valido_conclui_o_login_e_e_consumido(): void
    {
        $user = User::factory()->create();
        $this->ativarDoisFatoresConfirmados($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $response = $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-um']);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME);

        $codigosRestantes = $user->fresh()->recoveryCodes();
        $this->assertNotContains('recovery-code-um', $codigosRestantes);
    }

    public function test_recovery_code_invalido_nao_autentica(): void
    {
        $user = User::factory()->create();
        $this->ativarDoisFatoresConfirmados($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-que-nao-existe']);

        $this->assertGuest();
    }

    public function test_admin_de_plataforma_com_2fa_confirmado_tambem_e_desafiado_e_redirecionado_certo_apos(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $secret = $this->ativarDoisFatoresConfirmados($user);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();
        $response->assertRedirect(route('two-factor.login'));

        $codigoValido = (new Google2FA())->getCurrentOtp($secret);
        $response = $this->post('/two-factor-challenge', ['code' => $codigoValido]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_senha_errada_com_2fa_confirmado_nunca_chega_a_desafiar(): void
    {
        $user = User::factory()->create();
        $this->ativarDoisFatoresConfirmados($user);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'senha-errada',
        ]);

        $this->assertGuest();
        $this->assertNull(session('login.id'));
        $response->assertSessionHasErrors('email');
    }

    public function test_rate_limiting_do_login_continua_ativo(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'senha-errada']);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }
}
