<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Pré-produção, Etapa 2 (seção 12-14/16/18) — nunca mostrar a página
 * técnica "419 | Página expirada", nunca expor exception, nunca repetir
 * POST automaticamente, sempre uma mensagem clara.
 *
 * Achado de teste, não de produção: `VerifyCsrfToken::isReading()` já
 * pula a verificação inteira quando `runningInConsole() &&
 * runningUnitTests()` — TRUE em qualquer teste PHPUnit (config padrão do
 * próprio framework, `APP_ENV=testing`). Ou seja: é estruturalmente
 * impossível provocar um 419 real de ponta a ponta via `$this->post()`
 * neste ambiente de teste — por isso os testes chamam
 * `App\Exceptions\Handler::render()` diretamente (mesma técnica de
 * unit-test já aceita no projeto sempre que o caminho HTTP real não é
 * alcançável em teste), com uma `HttpException(419)` construída à mão
 * (o mesmo tipo que `TokenMismatchException` já é, por herança).
 */
class Error419Test extends TestCase
{
    use RefreshDatabase;

    private function excecao419(): HttpException
    {
        return new HttpException(419, 'CSRF token mismatch.');
    }

    public function test_visitante_recebe_redirect_para_login_com_mensagem_clara_nunca_a_pagina_tecnica(): void
    {
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession(app('session.store'));

        $response = app(Handler::class)->render($request, $this->excecao419());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->getTargetUrl());
        $this->assertSame('Sua sessão expirou. Faça login novamente para continuar.', $response->getSession()->get('flash.banner'));
        $this->assertSame('warning', $response->getSession()->get('flash.bannerStyle'));
    }

    public function test_usuario_ja_autenticado_recebe_redirect_para_pagina_anterior_com_mensagem_diferente(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $request = Request::create('/radar/lookahead', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_previous.url', 'http://localhost/radar/lookahead');
        $request->setUserResolver(fn () => $user);

        $response = app(Handler::class)->render($request, $this->excecao419());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('http://localhost/radar/lookahead', $response->getTargetUrl());
        $this->assertSame('Sua sessão precisou ser renovada. Tente novamente.', $response->getSession()->get('flash.banner'));
        // Nunca a mensagem de "faça login" pra quem continua autenticado —
        // seria impreciso, o usuário não foi deslogado.
        $this->assertStringNotContainsString('login', $response->getSession()->get('flash.banner'));
    }

    public function test_requisicao_json_recebe_corpo_limpo_com_status_419_nunca_html_ou_stacktrace(): void
    {
        $request = Request::create('/api/qualquer-coisa', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->setLaravelSession(app('session.store'));

        $response = app(Handler::class)->render($request, $this->excecao419());

        $this->assertSame(419, $response->getStatusCode());
        $conteudo = $response->getContent();
        $this->assertStringNotContainsString('<html', $conteudo);
        $this->assertStringNotContainsString('Exception', $conteudo);
        $this->assertStringNotContainsString('stack', strtolower($conteudo));
        $this->assertJson($conteudo);
        $this->assertSame('Sua sessão expirou. Faça login novamente para continuar.', json_decode($conteudo, true)['message']);
    }

    public function test_requisicao_livewire_nunca_e_redirecionada_fica_a_cargo_do_runtime_do_livewire(): void
    {
        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('X-Livewire', 'true');
        $request->setLaravelSession(app('session.store'));

        $response = app(Handler::class)->render($request, $this->excecao419());

        // Nunca um redirect (302) — Livewire, no cliente, já intercepta o
        // status 419 sozinho (vendor/livewire/livewire/dist/livewire.js) e
        // nunca reenvia a ação que falhou. Interceptar aqui duplicaria/
        // conflitaria com esse comportamento já seguro.
        $this->assertNotSame(302, $response->getStatusCode());
        $conteudo = $response->getContent();
        $this->assertStringNotContainsString('Exception', $conteudo);
    }

    /**
     * Redirecionar sempre pra um destino GET (login, ou a página
     * anterior) nunca pode reproduzir um 419 de novo — GET nunca passa
     * pela verificação de CSRF (só POST/PUT/PATCH/DELETE são checados),
     * então a própria construção do fluxo já torna um loop
     * estruturalmente impossível, não só "improvável": a resposta é
     * sempre um redirect 302 (o navegador sempre segue redirect via GET),
     * nunca um re-render/auto-submit do POST original.
     */
    public function test_resposta_e_sempre_um_redirect_302_nunca_reenvia_o_post_que_falhou(): void
    {
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession(app('session.store'));

        $response = app(Handler::class)->render($request, $this->excecao419());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
    }

    /**
     * Pré-produção, Etapa 2.1/2.2 (achado C — open redirect) —
     * `Handler::render()` chama o helper global `url()->previous()`, que
     * resolve o `UrlGenerator` vinculado ao request do CONTAINER, nunca ao
     * parâmetro `$request` de `render()`. Sem trocar o binding do
     * container, um Referer forjado no request manual não chegaria no
     * ponto que realmente decide o redirect — mesma técnica já usada nos
     * probes descartáveis da Etapa 2.1 que encontraram o bug.
     */
    private function renderComRequestNoContainer(Request $request, HttpException $excecao)
    {
        $original = $this->app['request'];
        $this->app->instance('request', $request);

        try {
            return app(Handler::class)->render($request, $excecao);
        } finally {
            $this->app->instance('request', $original);
        }
    }

    private function autenticado(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_419_autenticado_referer_interno_valido_e_usado_como_destino(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/lookahead', 'POST');
        $request->headers->set('referer', 'http://localhost/radar/lookahead');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, $this->excecao419());

        $this->assertSame('http://localhost/radar/lookahead', $response->getTargetUrl());
    }

    public function test_419_autenticado_referer_externo_nunca_e_usado_como_destino(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/lookahead', 'POST');
        $request->headers->set('referer', 'https://evil.example.com/phishing-page');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, $this->excecao419());

        $this->assertStringNotContainsString('evil.example.com', $response->getTargetUrl());
        $this->assertSame(route('app.home'), $response->getTargetUrl());
    }

    public function test_419_autenticado_referer_protocol_relative_nunca_e_usado_como_destino(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/lookahead', 'POST');
        $request->headers->set('referer', '//evil.example.com/phishing-page');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, $this->excecao419());

        $this->assertStringNotContainsString('evil.example.com', $response->getTargetUrl());
        $this->assertSame(route('app.home'), $response->getTargetUrl());
    }

    /**
     * `javascript:alert(...)` não bate no fast-path de
     * `UrlGenerator::isValidUrl()` (regex `^(#|//|https?://|(mailto|tel|sms):)`)
     * nem passa em `filter_var(..., FILTER_VALIDATE_URL)` — o próprio
     * `to()` do framework já neutraliza tratando a string inteira como um
     * PATH relativo, nunca um scheme de verdade. O destino final é sempre
     * uma URL `http://` navegável na MESMA origem — nunca um `javascript:`
     * executável pelo navegador.
     */
    public function test_419_autenticado_referer_scheme_javascript_nunca_vira_uri_executavel(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/lookahead', 'POST');
        $request->headers->set('referer', 'javascript:alert(document.cookie)');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, $this->excecao419());

        $this->assertStringStartsNotWith('javascript:', $response->getTargetUrl());
        $this->assertStringStartsWith('http://localhost', $response->getTargetUrl());
    }

    public function test_419_autenticado_sem_referer_e_sem_previous_na_sessao_cai_no_fallback(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/lookahead', 'POST');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, $this->excecao419());

        $this->assertSame(route('app.home'), $response->getTargetUrl());
    }

    public function test_419_autenticado_referer_mesma_pagina_que_falhou_e_aceito(): void
    {
        $this->autenticado();

        $request = Request::create('http://localhost/radar/plano-semanal', 'POST');
        $request->headers->set('referer', 'http://localhost/radar/plano-semanal');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, $this->excecao419());

        $this->assertSame('http://localhost/radar/plano-semanal', $response->getTargetUrl());
    }

    public function test_419_autenticado_referer_malformado_cai_no_fallback(): void
    {
        $this->autenticado();

        $request = Request::create('/radar/lookahead', 'POST');
        // "http:///" (host vazio) é um dos poucos formatos que
        // parse_url() aceita sintaticamente mas sem host utilizável.
        $request->headers->set('referer', 'http:///caminho-sem-host');
        $request->setLaravelSession(app('session.store'));

        $response = $this->renderComRequestNoContainer($request, $this->excecao419());

        $this->assertSame(route('app.home'), $response->getTargetUrl());
    }
}
