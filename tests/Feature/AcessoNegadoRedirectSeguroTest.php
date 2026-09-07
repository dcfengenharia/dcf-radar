<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Pré-produção, Etapa 2.2 (achado C, branch 403) — o popup de acesso
 * negado (`⚡acesso-negado`) sempre existiu redirecionando pra
 * `url()->previous()`, mesmo padrão vulnerável do branch 419 (Etapa
 * 2.1). Esta suíte prova que o mesmo `destinoInternoSeguro()` usado no
 * 419 também protege o 403 — sem alterar a UX existente (continua
 * redirecionando + `flash.popup=acesso-negado`), só nunca mais confiando
 * cegamente no Referer.
 */
class AcessoNegadoRedirectSeguroTest extends TestCase
{
    use RefreshDatabase;

    private function autenticado(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        return $user;
    }

    private function renderComRequestNoContainer(Request $request, AuthorizationException $excecao)
    {
        $original = $this->app['request'];
        $this->app->instance('request', $request);

        try {
            return app(Handler::class)->render($request, $excecao);
        } finally {
            $this->app->instance('request', $original);
        }
    }

    public function test_403_referer_interno_valido_e_usado_como_destino(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/dashboard', 'GET');
        $request->headers->set('referer', 'http://localhost/radar/dashboard');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, new AuthorizationException('não autorizado'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('http://localhost/radar/dashboard', $response->getTargetUrl());
        $this->assertSame('acesso-negado', $response->getSession()->get('flash.popup'));
    }

    public function test_403_referer_externo_nunca_e_usado_como_destino(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/dashboard', 'GET');
        $request->headers->set('referer', 'https://evil.example.com/phishing-page');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, new AuthorizationException('não autorizado'));

        $this->assertStringNotContainsString('evil.example.com', $response->getTargetUrl());
        $this->assertSame(route('app.home'), $response->getTargetUrl());
        // Continua abrindo o popup mesmo caindo no fallback — nunca uma
        // tela de erro crua só porque o Referer era inválido.
        $this->assertSame('acesso-negado', $response->getSession()->get('flash.popup'));
    }

    public function test_403_referer_protocol_relative_nunca_e_usado_como_destino(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/dashboard', 'GET');
        $request->headers->set('referer', '//evil.example.com/phishing-page');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, new AuthorizationException('não autorizado'));

        $this->assertStringNotContainsString('evil.example.com', $response->getTargetUrl());
        $this->assertSame(route('app.home'), $response->getTargetUrl());
    }

    public function test_403_sem_referer_cai_no_fallback(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/dashboard', 'GET');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, new AuthorizationException('não autorizado'));

        $this->assertSame(route('app.home'), $response->getTargetUrl());
    }

    /**
     * Rotas cliente.* (link público sem login) continuam inteiramente
     * fora do redirecionamento — este teste reconfirma que a correção do
     * achado C não tocou essa exceção já existente.
     */
    public function test_403_em_rota_cliente_continua_sem_redirecionamento(): void
    {
        $request = Request::create('/cliente/qualquer-coisa', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'cliente/qualquer-coisa', []);
            $route->name('cliente.qualquer');
            $route->bind($request);

            return $route;
        });

        $response = app(Handler::class)->render($request, new AuthorizationException('não autorizado'));

        $this->assertNotInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
    }
}
