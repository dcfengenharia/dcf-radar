<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Navegação de página cheia sem acesso (403) nunca mostra a página
     * de erro crua do Laravel — redireciona de volta com uma flag de
     * sessão que o popup de acesso negado (resources/views/components/
     * modal-acesso-negado.blade.php) lê pra se abrir sozinho. Ações
     * Livewire (header X-Livewire) e respostas JSON continuam com o
     * comportamento padrão — o popup, nesse caso, é aberto pelo hook
     * JS que intercepta a requisição falha (ver o mesmo componente).
     */
    public function render($request, Throwable $e)
    {
        // Pré-produção, Etapa 2 (seção 12-14/16) — 419 (sessão/token CSRF
        // inválido) nunca mais mostra a página técnica crua do Laravel
        // nem qualquer detalhe de exceção pro usuário final. CSRF continua
        // OBRIGATÓRIO em toda parte — este bloco só troca a APRESENTAÇÃO
        // do erro, nunca desativa/ignora a verificação em si (nenhuma rota
        // nova em VerifyCsrfToken::$except, nenhum bypass, nenhum retry
        // automático do POST que falhou — a única ação daqui em diante é
        // SEMPRE um redirect novo, GET, pro usuário decidir se tenta de
        // novo manualmente).
        //
        // Requisições Livewire (header X-Livewire) são DELIBERADAMENTE
        // deixadas de fora — o próprio runtime do Livewire já intercepta
        // status 419 no cliente (vendor/livewire/livewire/dist/livewire.js,
        // branch `response.status === 419`) e mostra um confirm() pedindo
        // pra recarregar a página, sem nunca reenviar a ação que falhou e
        // sem nunca renderizar o corpo da resposta — interceptar aqui
        // também só duplicaria/conflitaria com esse comportamento já
        // seguro. Ainda assim, a resposta que este bloco monta pra
        // Livewire/JSON nunca inclui stack trace/detalhe interno, mesmo
        // que o corpo não seja exibido pelo cliente (defesa em
        // profundidade — nunca vazar isso nem só pra aba de rede do
        // navegador).
        $eSessaoExpirada = $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419;

        if ($eSessaoExpirada) {
            $mensagem = 'Sua sessão expirou. Faça login novamente para continuar.';

            if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
                return response()->json(['message' => $mensagem], 419);
            }

            $session = $request->hasSession() ? $request->session() : app('session')->driver();

            // Sessão de fato expirada (o guard de auth já não resolve
            // ninguém a partir dela) → login de novo é a ação certa.
            // Sessão ainda válida mas com um CSRF token velho (ex.: aba
            // ficou aberta, ou duas abas concorrentes) → o usuário
            // continua autenticado, "faça login novamente" seria
            // impreciso — manda de volta pra página anterior pra tentar
            // de novo, mas SÓ se essa página anterior for comprovadamente
            // interna (Etapa 2.1/2.2 — achado C, ver destinoInternoSeguro()).
            if (auth()->check()) {
                $response = new RedirectResponse($this->destinoInternoSeguro($request, $this->candidatoAnterior($request, $session), 'app.home'));
                $response->setSession($session);

                return $response
                    ->with('flash.banner', 'Sua sessão precisou ser renovada. Tente novamente.')
                    ->with('flash.bannerStyle', 'warning');
            }

            $response = new RedirectResponse(route('login'));
            $response->setSession($session);

            return $response
                ->with('flash.banner', $mensagem)
                ->with('flash.bannerStyle', 'warning');
        }

        $eAcessoNegado = $e instanceof AuthorizationException
            || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403);

        // Rotas públicas "cliente.*" (link somente-leitura sem login, ver
        // App\Http\Controllers\ClienteRelatorioPublicoController) ficam de
        // fora desse redirecionamento — o visitante não tem sessão logada
        // nem "página anterior" dentro do app pra voltar; um link
        // assinado inválido/expirado deve mostrar o 403 padrão, não
        // empurrar quem clicou pra dentro do login.
        if ($eAcessoNegado && ! $request->hasHeader('X-Livewire') && ! $request->expectsJson() && ! $request->routeIs('cliente.*')) {
            // Não usar o helper redirect(): quando um componente Livewire
            // lança essa exceção dentro do próprio mount() (antes do
            // dehydrate() rodar), o binding 'redirect' do container
            // continua trocado pelo Redirector interno do Livewire
            // (vendor/livewire/livewire/src/Features/SupportRedirects/
            // SupportRedirects.php) — cujo ->with() devolve $this em vez
            // de uma RedirectResponse, quebrando o envio da resposta.
            // Construir a RedirectResponse direto contorna esse binding.
            $session = $request->hasSession() ? $request->session() : app('session')->driver();

            $response = new RedirectResponse($this->destinoInternoSeguro($request, $this->candidatoAnterior($request, $session), 'app.home'));
            $response->setSession($session);

            return $response->with('flash.popup', 'acesso-negado');
        }

        return parent::render($request, $e);
    }

    /**
     * Pré-produção, Etapa 2.2 (achado C, ajuste pós-teste) — fonte do
     * candidato a "página anterior", SEM o fallback automático pra `/`
     * que `Illuminate\Routing\UrlGenerator::previous()` embute (`return
     * $this->to('/');` quando não há Referer nem `_previous.url` na
     * sessão). Usar `url()->previous()` direto faria essa aplicação achar
     * que `/` era "a página anterior de verdade" e devolvê-la como se
     * fosse — mesmo sem NENHUMA informação real de navegação. Aqui,
     * ausência de informação vira `null` puro, que `destinoInternoSeguro()`
     * já trata caindo no fallback explícito da rota ($rotaFallback), nunca
     * o `/` genérico do framework.
     */
    private function candidatoAnterior($request, $session): ?string
    {
        return $request->headers->get('referer') ?: $session->previousUrl();
    }

    /**
     * Pré-produção, Etapa 2.1/2.2 (achado C — open redirect): fonte única da
     * regra "URL local segura", usada pelos dois branches acima (419
     * autenticado e 403). `url()->previous()` (Illuminate\Routing\
     * UrlGenerator::previous()) usa o header Referer como primeira fonte e
     * devolve QUALQUER URL absoluta verbatim, sem checar origem — provado
     * com `Referer: https://evil.example.com/...` fazendo os dois branches
     * redirecionarem o navegador da vítima pro domínio do atacante. Este
     * método nunca confia direto no Referer: valida o candidato contra o
     * host/scheme desta aplicação (ALLOWLIST — nunca uma blacklist de
     * domínios) e só o devolve se for comprovadamente interno; qualquer
     * coisa que não seja — host externo, `//evil.example` (protocol-
     * relative), scheme diferente de http/https, `javascript:`/`data:`
     * (nunca têm host, sempre rejeitados pelo `! isset($partes['host'])`),
     * URL malformada — cai no fallback (rota interna conhecida).
     */
    private function destinoInternoSeguro($request, ?string $candidato, string $rotaFallback): string
    {
        $fallback = route($rotaFallback);

        if ($candidato === null || $candidato === '') {
            return $fallback;
        }

        // URL relativa interna de verdade (nunca "//algo", que o navegador
        // resolve como protocol-relative pra OUTRO host).
        if (str_starts_with($candidato, '/') && ! str_starts_with($candidato, '//')) {
            return url($candidato);
        }

        $partes = parse_url($candidato);

        if ($partes === false || ! isset($partes['host'])) {
            return $fallback;
        }

        $appUrl = parse_url((string) config('app.url'));

        $hostsValidos = array_filter([
            isset($appUrl['host']) ? strtolower($appUrl['host']) : null,
            strtolower($request->getHost()),
        ]);

        $schemeValido = in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true);
        $hostValido = in_array(strtolower($partes['host']), $hostsValidos, true);

        $portaCandidata = $partes['port'] ?? null;
        $portaEsperada = $appUrl['port'] ?? null;
        $portaValida = $portaCandidata === $portaEsperada || $portaCandidata === $request->getPort();

        if ($schemeValido && $hostValido && $portaValida) {
            return $candidato;
        }

        return $fallback;
    }
}
