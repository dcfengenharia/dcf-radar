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
        $eAcessoNegado = $e instanceof AuthorizationException
            || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403);

        if ($eAcessoNegado && ! $request->hasHeader('X-Livewire') && ! $request->expectsJson()) {
            // Não usar o helper redirect(): quando um componente Livewire
            // lança essa exceção dentro do próprio mount() (antes do
            // dehydrate() rodar), o binding 'redirect' do container
            // continua trocado pelo Redirector interno do Livewire
            // (vendor/livewire/livewire/src/Features/SupportRedirects/
            // SupportRedirects.php) — cujo ->with() devolve $this em vez
            // de uma RedirectResponse, quebrando o envio da resposta.
            // Construir a RedirectResponse direto contorna esse binding.
            $session = $request->hasSession() ? $request->session() : app('session')->driver();

            $response = new RedirectResponse(url()->previous() ?: route('app.home'));
            $response->setSession($session);

            return $response->with('flash.popup', 'acesso-negado');
        }

        return parent::render($request, $e);
    }
}
