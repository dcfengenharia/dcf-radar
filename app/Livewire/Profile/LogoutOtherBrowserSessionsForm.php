<?php

namespace App\Livewire\Profile;

use App\Support\Agent;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm as JetstreamLogoutOtherBrowserSessionsForm;

/**
 * BUG TARGETED — `/profile` quebrava com "Call to a member function
 * get() on string" (ver `App\Support\Agent` pra causa raiz completa).
 *
 * Única mudança: `createAgent()` devolve `App\Support\Agent` (a
 * subclasse corrigida) em vez de `Laravel\Jetstream\Agent` (vendor,
 * com o bug). Toda a lógica de sessão — `getSessionsProperty()`
 * (identificação da sessão atual via `is_current_device`, IP,
 * `last_active`), `logoutOtherBrowserSessions()` (confirmação de
 * senha via `Hash::check()`, `$guard->logoutOtherDevices()`, exclusão
 * dos registros de OUTRAS sessões do MESMO usuário autenticado — nunca
 * de outro usuário/tenant), `confirmLogout()` — é herdada 100% intocada
 * de `Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm`.
 *
 * `$session->user_agent` normalizado pra string vazia quando `null`
 * (sessão legada/console sem header de User-Agent) — `Agent::
 * setUserAgent()` (herdado de `Detection\MobileDetect`) é tipado
 * estritamente `string`, nunca aceita `null`; sem essa normalização,
 * uma sessão com `user_agent` nulo no banco lançaria `TypeError` em vez
 * do bug de cache já corrigido. Um User-Agent malformado/incomum
 * continua tratado como dado NÃO CONFIÁVEL, exatamente como antes —
 * `Detection\MobileDetect` já resolve qualquer string que não bata
 * nenhuma regra como `platform()`/`browser()` retornando `null`, e a
 * própria Blade já trata isso com "Unknown" (`$session->agent->platform()
 * ? ... : 'Unknown'`) — nunca alterado aqui.
 *
 * Registrado com o MESMO nome de componente Livewire que o Jetstream já
 * usa (`profile.logout-other-browser-sessions-form`) em
 * `App\Providers\JetstreamOverridesServiceProvider`, rodando DEPOIS do
 * `Laravel\Jetstream\JetstreamServiceProvider` — a última chamada a
 * `Livewire::component()` com o mesmo nome vence, sem precisar publicar/
 * sobrescrever nenhuma view.
 */
class LogoutOtherBrowserSessionsForm extends JetstreamLogoutOtherBrowserSessionsForm
{
    /**
     * @param  object  $session
     * @return \App\Support\Agent
     */
    protected function createAgent($session)
    {
        return tap(new Agent(), fn (Agent $agent) => $agent->setUserAgent((string) ($session->user_agent ?? '')));
    }
}
