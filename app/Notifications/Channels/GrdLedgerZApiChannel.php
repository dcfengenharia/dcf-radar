<?php

namespace App\Notifications\Channels;

use App\Models\GrdAlertaEntrega;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 18, Etapa 18.5.6 — wrapper de ledger em cima do `ZApiChannel`
 * existente (reaproveitado via injeção, nunca reimplementado — nenhuma
 * lógica de HTTP/normalização de número nova aqui). Usado EXCLUSIVAMENTE
 * pelos 2 Notifications de alerta GRD, nunca substitui `ZApiChannel::class`
 * globalmente — as outras 4 Notifications que já usam `ZApiChannel::class`
 * direto continuam intocadas.
 *
 * `ZApiChannel::send()` é, por design, COMPLETAMENTE silencioso — nunca
 * lança exceção (retorna cedo sem número/credencial, só loga um warning
 * em falha HTTP). Isso significa que, ao contrário do mail, o WhatsApp já
 * é estruturalmente IMUNE a duplicação por RETRY DE JOB: uma falha nunca
 * dispara um retry, porque nunca há exceção pra disparar um. O ledger
 * aqui existe por CONSISTÊNCIA/auditoria com o canal mail (mesma
 * infraestrutura, mesmo `evento_usuario_id`+`canal`), não porque seja a
 * defesa real contra duplicação neste canal — a defesa real do WhatsApp
 * contra RETRY é o próprio silêncio do ZApiChannel.
 *
 * Etapa 18.5.6 — AUDITORIA ADVERSARIAL FINAL (achado P6/P7, corrigido
 * aqui): a versão original gravava o ledger incondicionalmente após
 * `send()` retornar, mesmo quando `ZApiChannel` fazia um no-op silencioso
 * (sem telefone ou sem credencial configurada) — a linha ficava com
 * `enviado_em` preenchido como se uma mensagem real tivesse saído. Esta
 * versão espia (nunca reimplementa) as MESMAS 2 condições que
 * `ZApiChannel::send()` já checa internamente — telefone roteável e
 * credenciais configuradas — e só grava o ledger quando as duas são
 * verdadeiras, ou seja, quando uma tentativa de envio HTTP de fato
 * ocorreu. Isso é apenas peek em dados de roteamento/config já públicos
 * (mesmo padrão já usado em outras partes do projeto pra "checar
 * elegibilidade de canal sem duplicar a lógica de envio em si") — nunca
 * toca `ZApiChannel` nem duplica a chamada HTTP.
 *
 * Limitação residual CONHECIDA e NÃO fechada por este ajuste (ver
 * auditoria adversarial, achado P8): `ZApiChannel::send()` retorna `void`
 * mesmo quando a chamada HTTP é feita e falha (ex.: Z-API responde 500) —
 * só loga um `Log::warning()` internamente, sem expor esse resultado ao
 * chamador. Este wrapper não tem como distinguir "enviado com sucesso" de
 * "tentativa feita mas a Z-API recusou" sem alterar a assinatura de
 * `ZApiChannel::send()` (usada por mais 4 Notifications em produção,
 * fora do escopo desta auditoria) — nesse caso específico, o ledger AINDA
 * grava como se tivesse sido entregue. Impacto prático hoje: NULO — nada
 * no sistema re-lê ou tenta corrigir esse ledger automaticamente; é uma
 * imprecisão de registro histórico, não uma causa de comportamento
 * incorreto observável. Registrado como dívida (classificação B) até uma
 * eventual etapa futura que precise dessa distinção de verdade.
 *
 * Mesmo achado/fix (ACHADO C, auditoria adversarial final da 18.5.6) de
 * `TenantContext::actingAs()` que `GrdLedgerMailChannel` — `new
 * Tenant(['id' => ...])` descartava `id` em silêncio (mass assignment,
 * `id` não é `$fillable`), tornando o `actingAs()` abaixo um no-op;
 * corrigido com `(new Tenant())->forceFill(['id' => ...])`. Ver docblock
 * de `GrdLedgerMailChannel` para a análise completa do mecanismo e do
 * impacto (worker sem auth vs. execução síncrona com outro tenant
 * ambiente).
 */
class GrdLedgerZApiChannel
{
    public function __construct(private readonly ZApiChannel $zapi)
    {
    }

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'metadadosAlertaGrd') || ! $notification->id) {
            $this->zapi->send($notifiable, $notification);

            return;
        }

        $meta = $notification->metadadosAlertaGrd();
        // NUNCA `new Tenant(['id' => ...])` — 'id' não está em Tenant::$fillable
        // (auto-gerado por HasUlids), então mass assignment comum DESCARTA o
        // valor em silêncio, deixando $tenant->id null e tornando o
        // actingAs() abaixo um no-op (achado C da auditoria adversarial da
        // 18.5.6). forceFill() bypassa o guard sem alterar Tenant::$fillable.
        $tenant = (new Tenant())->forceFill(['id' => $meta['tenant_id']]);

        TenantContext::actingAs($tenant, function () use ($notifiable, $notification, $meta) {
            if (GrdAlertaEntrega::where('evento_usuario_id', $notification->id)->where('canal', 'whatsapp')->exists()) {
                return;
            }

            $tentativaSeraFeita = $this->tentativaDeEnvioSeraFeita($notifiable, $notification);

            $this->zapi->send($notifiable, $notification);

            if ($tentativaSeraFeita) {
                $this->registrarEntrega($notification->id, $meta, $notifiable->id);
            }
        });
    }

    /**
     * Espia (nunca reimplementa) as MESMAS 2 condições que `ZApiChannel::
     * send()` já checa antes de chamar a Z-API — evita gravar o ledger
     * como "enviado" quando, na verdade, nada foi tentado (achado P6/P7 da
     * auditoria adversarial). Checado ANTES de chamar `zapi->send()`
     * (não depois) pra decidir com a MESMA informação que o canal usaria.
     */
    private function tentativaDeEnvioSeraFeita($notifiable, Notification $notification): bool
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return false;
        }

        if (! $notifiable->routeNotificationFor('whatsapp', $notification)) {
            return false;
        }

        return (bool) (config('services.zapi.instance_id') && config('services.zapi.token'));
    }

    private function registrarEntrega(string $eventoUsuarioId, array $meta, string $usuarioId): void
    {
        try {
            GrdAlertaEntrega::create(array_merge($meta, [
                'usuario_id' => $usuarioId,
                'evento_usuario_id' => $eventoUsuarioId,
                'canal' => 'whatsapp',
                'enviado_em' => now(),
            ]));
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }
}
