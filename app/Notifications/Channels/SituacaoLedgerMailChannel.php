<?php

namespace App\Notifications\Channels;

use App\Models\SituacaoComunicacaoEntrega;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 21, Etapa 21.4 — generalização EXATA de
 * `App\Notifications\Channels\GrdLedgerMailChannel` (Ciclo 18.5.6) pro
 * domínio de Situações Gerenciais. Wrapper de idempotência por canal em
 * cima do `MailChannel` nativo (nunca reimplementa SMTP/Markdown) — só
 * usado por `App\Notifications\SituacaoGerencialNotification`, nunca
 * registrado como substituto global do canal `'mail'`.
 *
 * **Mesma garantia REAL, mesmo residual conhecido** (ver docblock de
 * `GrdLedgerMailChannel` pra prova completa): at-least-once com
 * deduplicação best-effort via `UNIQUE(evento_usuario_id, canal)`, nunca
 * exactly-once — a janela entre o SMTP aceitar o envio e o INSERT deste
 * ledger falhar não é fechada por nada existente no projeto.
 *
 * **`TenantContext::actingAs()` obrigatório** — `SituacaoComunicacaoEntrega`
 * usa `BelongsToTenant`; este `send()` roda dentro de um job de fila
 * (`SendQueuedNotifications`, worker sem usuário autenticado). `tenantId`
 * é capturado pelo CHAMADOR (`SincronizarSituacoesGerenciais`, ainda
 * dentro do processo/tenant corretos) e repassado via
 * `metadadosLedgerSituacao()` — nunca resolvido aqui. `forceFill(['id'
 * => ...])`, nunca `new Tenant(['id' => ...])` — mesmo achado (ACHADO C)
 * já documentado e corrigido em `GrdLedgerMailChannel`: `id` não está em
 * `Tenant::$fillable` (autogerado por `HasUlids`), mass assignment comum
 * descartaria o valor em silêncio.
 */
class SituacaoLedgerMailChannel
{
    public function __construct(private readonly MailChannel $mail)
    {
    }

    public function send($notifiable, Notification $notification): mixed
    {
        if (! method_exists($notification, 'metadadosLedgerSituacao') || ! $notification->id) {
            // Defensivo — nunca deveria acontecer pra
            // SituacaoGerencialNotification; sem identidade determinística,
            // delega puro (sem ledger).
            return $this->mail->send($notifiable, $notification);
        }

        $meta = $notification->metadadosLedgerSituacao();
        $tenant = (new Tenant())->forceFill(['id' => $meta['tenant_id']]);

        return TenantContext::actingAs($tenant, function () use ($notifiable, $notification, $meta) {
            if (SituacaoComunicacaoEntrega::where('evento_usuario_id', $notification->id)->where('canal', 'mail')->exists()) {
                return null;
            }

            $resultado = $this->mail->send($notifiable, $notification);

            $this->registrarEntrega($notification->id, $meta, $notifiable->id);

            return $resultado;
        });
    }

    private function registrarEntrega(string $eventoUsuarioId, array $meta, string $usuarioId): void
    {
        try {
            SituacaoComunicacaoEntrega::create([
                'obra_id' => $meta['obra_id'],
                'usuario_id' => $usuarioId,
                'evento_usuario_id' => $eventoUsuarioId,
                'canal' => 'mail',
                'enviado_em' => now(),
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // Corrida concorrente — o e-mail já foi enviado por este
            // processo (não tem como desenviar), mas a gravação em
            // duplicata é só ruído de auditoria. Idempotência, não erro.
        }
    }
}
