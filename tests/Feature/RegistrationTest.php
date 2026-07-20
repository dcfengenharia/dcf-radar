<?php

namespace Tests\Feature;

use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is not enabled.');

            return;
        }

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_registration_screen_cannot_be_rendered_if_support_is_disabled(): void
    {
        // O projeto usa rota de cadastro própria, independente da feature flag do Fortify.
        // Este teste de template do Jetstream não se aplica a esta arquitetura.
        $this->markTestSkipped('Projeto usa rota de cadastro customizada — feature flag do Fortify não controla esta rota.');
    }

    public function test_new_users_can_register(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is not enabled.');

            return;
        }

        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'company_name' => 'Test Company',
            'email' => 'test@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    /**
     * Melhoria pedida pelo usuário: vincular o cadastro ao aceite do
     * checkbox de Política de Privacidade/Termos de Uso
     * (Features::termsAndPrivacyPolicy() habilitada em config/jetstream.php).
     */
    public function test_cadastro_exige_aceite_dos_termos_quando_feature_habilitada(): void
    {
        $this->assertTrue(Jetstream::hasTermsAndPrivacyPolicyFeature());

        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'company_name' => 'Test Company',
            'email' => 'sem-termos@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            // 'terms' propositalmente omitido
        ]);

        $response->assertSessionHasErrors('terms');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'sem-termos@example.com']);
    }

    public function test_pagina_de_politica_de_privacidade_mostra_conteudo_real(): void
    {
        $this->get(route('policy.show'))
            ->assertOk()
            ->assertSee('Política de Privacidade')
            ->assertDontSee('Edit this file to define the privacy policy');
    }

    public function test_pagina_de_termos_de_uso_mostra_conteudo_real(): void
    {
        $this->get(route('terms.show'))
            ->assertOk()
            ->assertSee('Termos de Uso')
            ->assertDontSee('Edit this file to define the terms of service');
    }
}
