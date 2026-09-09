<?php

namespace Tests\Feature;

use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUG TARGETED — `/profile` ("Outras sessões do navegador") lançava
 * "Call to a member function get() on string".
 *
 * CAUSA RAIZ: `Laravel\Jetstream\Agent::retrieveUsingCacheOrResolve()`
 * (vendor, `laravel/jetstream` 4.1.0) assume um cache estilo PSR-6
 * (`get()` devolvendo um item com seu próprio `->get()`), mas a
 * dependência que o PRÓPRIO Jetstream declara (`mobiledetect/
 * mobiledetectlib: ^4.8`, resolvida pra `4.10.0` neste projeto)
 * implementa PSR-16 de verdade (`get()` já devolve o valor). Na 2ª
 * chamada de `platform()`/`browser()` NA MESMA requisição (cache hit),
 * o código tenta `"Windows"->get()` — daí o erro. A PRÓPRIA Blade
 * oficial do Jetstream já chama esses métodos duas vezes cada
 * (`X() ? X() : 'Unknown'`), então isto quebra pra QUALQUER sessão com
 * User-Agent resolvível — nunca foi específico de UA malformado.
 *
 * LACUNA QUE ESCONDEU O BUG: `phpunit.xml` define `SESSION_DRIVER=array`
 * pra toda a suíte — `LogoutOtherBrowserSessionsForm::getSessionsProperty()`
 * tem `if (config('session.driver') !== 'database') return collect();`
 * como PRIMEIRA linha, então em QUALQUER teste anterior (`ProfileTest`
 * incluso) essa checagem sempre retornava uma coleção vazia, o código
 * de `$session->agent` NUNCA rodava, e `/profile` sempre respondia 200
 * mesmo estando genuinamente quebrado no runtime real
 * (`SESSION_DRIVER=database`). Este arquivo força
 * `config(['session.driver' => 'database'])` explicitamente pra
 * exercitar o caminho real.
 */
class LogoutOtherBrowserSessionsFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    /**
     * Achado de teste — `Livewire::test()` NUNCA serve pra exercitar
     * `request()->session()` de verdade: toda interação (mount inicial
     * E qualquer `->call()`/`->set()` posterior) passa por
     * `Livewire\Features\SupportTesting\RequestBroker::
     * temporarilyDisableExceptionHandlingAndMiddleware()`, que chama
     * `withoutMiddleware()` incondicionalmente — pulando `StartSession`
     * SEMPRE — e o próprio `Illuminate\Foundation\Http\Kernel::handle()`
     * rebinda `app('request')` no início de QUALQUER dispatch (`$this
     * ->app->instance('request', $request)`), mesmo com middleware
     * desligado — então qualquer "aquecimento" de sessão feito ANTES é
     * substituído por um `Request` sem sessão assim que `Livewire::
     * test()` dispara sua requisição interna. `getSessionsProperty()`/
     * `logoutOtherBrowserSessions()` (herdados do Jetstream) chamam
     * `request()->session()` — por isso os testes que precisam disso
     * de verdade (`test_d`/`test_e`/`test_g`) instanciam o componente
     * DIRETO (`new LogoutOtherBrowserSessionsForm()`) e chamam os
     * métodos como PHP puro, nunca via `Livewire::test()` — sem nenhum
     * dispatch HTTP no meio, o `request()` que amarramos aqui nunca é
     * trocado por baixo dos panos.
     */
    private function estabelecerSessaoAtual(User $user, string $userAgent, string $ip = '127.0.0.1'): string
    {
        $sessionId = \Illuminate\Support\Str::random(40);

        $sessionStore = $this->app['session']->driver();
        $sessionStore->setId($sessionId);
        $sessionStore->start();

        $request = \Illuminate\Http\Request::create('/profile', 'GET');
        $request->setLaravelSession($sessionStore);
        $this->app->instance('request', $request);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);

        return $sessionId;
    }

    /**
     * Reproduz o bug isoladamente contra o Agent do VENDOR — nunca
     * reimplementa a lógica, chama a classe real
     * `Laravel\Jetstream\Agent` diretamente. Este teste documenta a
     * causa raiz e continuará vermelho enquanto o pacote `laravel/
     * jetstream`/`mobiledetect/mobiledetectlib` mantiver essa
     * incompatibilidade — é esperado que ele NUNCA precise ficar verde
     * por si só (a correção real está em `App\Support\Agent`, testada
     * nos métodos seguintes); serve só como prova permanente da causa
     * raiz, igual a um "achado de investigação" registrado em código.
     */
    public function test_a_causa_raiz_agent_do_vendor_quebra_na_segunda_chamada(): void
    {
        $agent = new \Laravel\Jetstream\Agent();
        $agent->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $this->assertSame('Windows', $agent->platform());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Call to a member function get() on string');
        $agent->platform();
    }

    /**
     * A MESMA sequência de chamadas, mas sobre `App\Support\Agent` (a
     * correção) — nunca deve lançar, em nenhuma das duas chamadas.
     */
    public function test_b_agent_corrigido_nunca_quebra_em_chamadas_repetidas(): void
    {
        $agent = new \App\Support\Agent();
        $agent->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $this->assertSame('Windows', $agent->platform());
        $this->assertSame('Windows', $agent->platform());
        $this->assertSame('Chrome', $agent->browser());
        $this->assertSame('Chrome', $agent->browser());
    }

    /**
     * O TESTE OBRIGATÓRIO — renderiza a página `/profile` REAL, com
     * `session.driver=database` e sessões REAIS na tabela `sessions`
     * (nunca mockadas), reproduzindo o cenário completo do usuário:
     * sessão atual + outra sessão com User-Agent normal + User-Agent
     * nulo + User-Agent malformado. Falhava com "Call to a member
     * function get() on string" antes da correção (comprovado
     * isoladamente no teste A) e passa depois, porque
     * `App\Providers\JetstreamServiceProvider::boot()` registra
     * `App\Livewire\Profile\LogoutOtherBrowserSessionsForm` no lugar do
     * componente do vendor.
     */
    public function test_c_pagina_profile_renderiza_com_sessoes_reais_incluindo_casos_extremos(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Aquece a Request vinculada no container com uma sessão real —
        // o próprio Laravel já grava uma linha em `sessions` pra ela ao
        // final desta primeira requisição (session.driver=database).
        $this->get('/profile');

        $sessionIdAtual = session()->getId();

        // A linha da sessão ATUAL já existe (Laravel acabou de criá-la) —
        // só normalizamos o User-Agent/IP dela pra um valor conhecido,
        // nunca inserimos uma 2ª linha com o mesmo id (violaria a PK).
        DB::table('sessions')->where('id', $sessionIdAtual)->update([
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);

        DB::table('sessions')->insert([
            [
                'id' => 'sessao-outro-navegador-normal',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.5',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->subHour()->timestamp,
            ],
            [
                'id' => 'sessao-user-agent-nulo',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.6',
                'user_agent' => null,
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->subHours(2)->timestamp,
            ],
            [
                'id' => 'sessao-user-agent-malformado',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.7',
                'user_agent' => '???totally-not-a-real-user-agent!!!###',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->subHours(3)->timestamp,
            ],
        ]);

        // Achado de teste: Laravel NÃO repropaga o cookie de sessão de
        // uma resposta pra a chamada de teste seguinte automaticamente
        // (`MakesHttpRequests::call()` nunca lê `Set-Cookie` da resposta
        // anterior) — sem isso, esta 2ª chamada abriria uma sessão NOVA
        // e diferente, e a linha que acabamos de arrumar acima nunca
        // seria "a atual". `withCookie()` força a mesma sessão a ser
        // resumida (mesmo mecanismo de `CookieValuePrefix` que
        // `EncryptCookies` já sabe decifrar).
        $response = $this->withCookie(config('session.cookie'), $sessionIdAtual)->get('/profile');

        $response->assertOk();
        $response->assertDontSee('Call to a member function get() on string');
        $response->assertSee('This device');
        // Sessão com UA nulo/malformado nunca derruba a página — cai no
        // fallback "Unknown" já existente na própria Blade.
        $response->assertSee('Unknown');
    }

    /**
     * Confirma explicitamente, via o próprio componente Livewire, que
     * cada tipo de sessão resolve exatamente como esperado — nunca só
     * "a página não quebrou", também que o dado exibido é coerente.
     */
    public function test_d_componente_livewire_resolve_platform_e_browser_corretamente_para_cada_sessao(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $sessionIdAtual = $this->estabelecerSessaoAtual(
            $user,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        DB::table('sessions')->insert([
            [
                'id' => 'sessao-linux-firefox',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.5',
                'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->subHour()->timestamp,
            ],
            [
                'id' => 'sessao-sem-user-agent',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.6',
                'user_agent' => null,
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->subHours(2)->timestamp,
            ],
        ]);

        $componente = new LogoutOtherBrowserSessionsForm();
        $sessoes = $componente->getSessionsProperty();

        $this->assertCount(3, $sessoes);

        $atual = $sessoes->firstWhere('is_current_device', true);
        $this->assertNotNull($atual);
        $this->assertSame('Windows', $atual->agent->platform());
        $this->assertSame('Chrome', $atual->agent->browser());

        $firefox = collect($sessoes)->first(fn ($s) => $s->ip_address === '10.0.0.5');
        $this->assertSame('Linux', $firefox->agent->platform());
        $this->assertSame('Firefox', $firefox->agent->browser());

        $semUserAgent = collect($sessoes)->first(fn ($s) => $s->ip_address === '10.0.0.6');
        // Nunca lança TypeError por User-Agent nulo (createAgent() normaliza
        // pra string vazia antes de chamar setUserAgent()) — resolve pra
        // null, tratado como "Unknown" na Blade.
        $this->assertNull($semUserAgent->agent->platform());
        $this->assertNull($semUserAgent->agent->browser());
    }

    /**
     * Preserva o fluxo de segurança já existente — nunca reimplementado,
     * só herdado de `Laravel\Jetstream\Http\Livewire\
     * LogoutOtherBrowserSessionsForm`, agora exercitado de fato (a
     * suíte anterior nunca chegava aqui com sessões reais).
     */
    public function test_e_logout_de_outras_sessoes_continua_funcional_e_isolado_por_usuario(): void
    {
        $user = User::factory()->create(['password' => bcrypt('senha-correta')]);
        $outroUsuario = User::factory()->create();
        $this->actingAs($user);

        $sessionIdAtual = $this->estabelecerSessaoAtual(
            $user,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'
        );

        DB::table('sessions')->insert([
            [
                'id' => 'sessao-outro-navegador-do-mesmo-usuario',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.5',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->subHour()->timestamp,
            ],
            [
                'id' => 'sessao-de-outro-usuario-nunca-deve-ser-tocada',
                'user_id' => $outroUsuario->id,
                'ip_address' => '10.0.0.9',
                'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->timestamp,
            ],
        ]);

        // Senha errada — nunca desloga nada.
        $componenteSenhaErrada = new LogoutOtherBrowserSessionsForm();
        $componenteSenhaErrada->password = 'senha-errada';
        try {
            app()->call([$componenteSenhaErrada, 'logoutOtherBrowserSessions']);
            $this->fail('Esperava ValidationException por senha incorreta.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertDatabaseHas('sessions', ['id' => 'sessao-outro-navegador-do-mesmo-usuario']);

        // Senha certa — desloga só as OUTRAS sessões DESTE usuário.
        $componenteSenhaCorreta = new LogoutOtherBrowserSessionsForm();
        $componenteSenhaCorreta->password = 'senha-correta';
        app()->call([$componenteSenhaCorreta, 'logoutOtherBrowserSessions']);

        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-outro-navegador-do-mesmo-usuario']);
        $this->assertDatabaseHas('sessions', ['id' => $sessionIdAtual]); // sessão atual preservada
        $this->assertDatabaseHas('sessions', ['id' => 'sessao-de-outro-usuario-nunca-deve-ser-tocada']); // outro usuário intocado
    }

    /**
     * Nunca expõe o `id` real da sessão (nem de outros usuários) no
     * HTML renderizado — só IP/plataforma/navegador/"last active".
     */
    public function test_f_nunca_expoe_session_id_no_html_renderizado(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        DB::table('sessions')->insert([
            'id' => session()->getId(),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            'payload' => base64_encode(serialize(['segredo-nao-pode-vazar' => 'xyz'])),
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->get('/profile');

        $response->assertOk();
        $response->assertDontSee(session()->getId());
        $response->assertDontSee('segredo-nao-pode-vazar');
    }

    /**
     * Isolamento de tenant — sessões de um usuário de OUTRO tenant nunca
     * aparecem, mesmo tecnicamente possível de coexistir na mesma
     * tabela `sessions` (que não tem coluna tenant_id — o filtro real é
     * sempre por `user_id`, herdado sem alteração do Jetstream).
     */
    public function test_g_sessao_de_usuario_de_outro_tenant_nunca_aparece(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $usuarioA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $usuarioB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($usuarioA);

        $this->estabelecerSessaoAtual($usuarioA, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0');

        DB::table('sessions')->insert([
            [
                'id' => 'sessao-do-tenant-b',
                'user_id' => $usuarioB->id,
                'ip_address' => '10.0.0.9',
                'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->timestamp,
            ],
        ]);

        $sessoes = (new LogoutOtherBrowserSessionsForm())->getSessionsProperty();

        $this->assertCount(1, $sessoes);
        $this->assertSame('127.0.0.1', $sessoes->first()->ip_address);
    }
}
