<?php

namespace App\Support;

use Closure;
use Detection\Cache\CacheException;
use Detection\Exception\MobileDetectException;
use Laravel\Jetstream\Agent as JetstreamAgent;

/**
 * BUG TARGETED — `/profile` ("Outras sessões do navegador") lançava
 * "Call to a member function get() on string".
 *
 * CAUSA RAIZ (confirmada lendo o vendor real, nunca presumida):
 * `Laravel\Jetstream\Agent::retrieveUsingCacheOrResolve()` (pacote
 * `laravel/jetstream` fixado em `4.1.0` no composer.json) trata
 * `$this->cache->get($cacheKey)` como se devolvesse um objeto estilo
 * PSR-6 (`CacheItemInterface`, com seu PRÓPRIO `->get()` pra extrair o
 * valor) — `return $cacheItem->get();`. Mas a dependência que o próprio
 * `laravel/jetstream` declara (`"mobiledetect/mobiledetectlib": "^4.8"`,
 * resolvida pelo Composer pra `4.10.0` neste projeto,
 * `Detection\Cache\Cache`) implementa `Psr\SimpleCache\CacheInterface`
 * de verdade (PSR-16) — `get($key)` já devolve o VALOR resolvido
 * diretamente (confirmado lendo `vendor/mobiledetect/mobiledetectlib/
 * src/Cache/Cache.php::get()`), nunca um wrapper.
 *
 * Sequência do crash: 1ª chamada de `platform()`/`browser()` (cache
 * miss) resolve e cacheia normalmente, devolvendo a string certa — o
 * bug fica latente. Numa 2ª chamada NA MESMA requisição (cache hit),
 * `$this->cache->get($cacheKey)` devolve a STRING já resolvida (ex.:
 * "Windows"), e o código tenta `"Windows"->get()` — daí exatamente
 * "Call to a member function get() on string".
 *
 * NÃO é um problema de User-Agent malformado/incomum, nem de sessão
 * antiga, nem de customização deste projeto: a PRÓPRIA Blade oficial do
 * Jetstream (`vendor/laravel/jetstream/stubs/livewire/resources/views/
 * profile/logout-other-browser-sessions-form.blade.php`) já chama
 * `platform()`/`browser()` DUAS VEZES cada
 * (`{{ $session->agent->platform() ? $session->agent->platform() : ... }}`)
 * — então qualquer instalação Jetstream 4.1.0 + mobiledetectlib ^4.8
 * quebra pra QUALQUER sessão com User-Agent resolvível (basicamente
 * sempre). Nunca copiado de uma versão antiga do Jetstream sem
 * confirmar — a correção só reimplementa o contrato REAL de
 * `Psr\SimpleCache\CacheInterface` já usado pela dependência realmente
 * instalada.
 *
 * Correção mínima: subclasse que reimplementa SÓ o método com o bug —
 * nenhuma regra de detecção de plataforma/navegador/dispositivo é
 * tocada (herdadas intocadas de `Laravel\Jetstream\Agent`/
 * `Detection\MobileDetect`). Nunca edita `vendor/` (seria perdido em
 * qualquer `composer install`/`update`).
 */
class Agent extends JetstreamAgent
{
    /**
     * @param  Closure(): mixed  $callback
     */
    protected function retrieveUsingCacheOrResolve(string $key, Closure $callback): mixed
    {
        try {
            $cacheKey = $this->createCacheKey($key);

            // PSR-16 (Psr\SimpleCache\CacheInterface::get()) já devolve o
            // valor resolvido diretamente — nunca um item que precise de
            // um ->get() adicional (essa suposição, presente no
            // Agent::class original do vendor, é a causa raiz do bug).
            $cached = $this->cache->get($cacheKey);

            if (! is_null($cached)) {
                return $cached;
            }

            return tap(call_user_func($callback), function ($result) use ($cacheKey) {
                $this->cache->set($cacheKey, $result);
            });
        } catch (CacheException $e) {
            throw new MobileDetectException("Cache problem in for {$key}: {$e->getMessage()}");
        }
    }
}
