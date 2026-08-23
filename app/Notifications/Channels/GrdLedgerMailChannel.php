<?php

namespace App\Notifications\Channels;

use App\Models\GrdAlertaEntrega;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 18, Etapa 18.5.6 — wrapper de idempotência por canal em cima do
 * `MailChannel` padrão do Laravel (reaproveitado via injeção, nunca
 * reimplementado — nenhuma lógica de SMTP/Markdown nova aqui). Usado
 * EXCLUSIVAMENTE pelos 2 Notifications de alerta GRD (via `via()`), nunca
 * registrado como substituto global do canal `'mail'` — as outras 4
 * Notifications do projeto que já usam `'mail'` continuam intocadas.
 *
 * A auditoria da 18.5.5.HARDENING confirmou que a PRIMARY KEY de
 * `notifications.id` NÃO protege side effects externos: um retry do job
 * de fila do canal mail (cada canal de `via()` vira 1
 * `SendQueuedNotifications` independente) poderia, em tese, reenviar um
 * e-mail já entregue com sucesso. Este wrapper fecha esse gap com
 * `App\Models\GrdAlertaEntrega` (ledger append-only, `UNIQUE
 * (evento_usuario_id, canal)`), usando o MESMO `$notification->id`
 * (UUIDv5 determinístico, `AlertaDistribuicaoGrd::idAlerta()`) já usado
 * como identidade do canal `database` — nunca uma segunda identidade.
 *
 * ORDEM REAL DE EXECUÇÃO (`send()`, dentro de `TenantContext::actingAs()`):
 * (1) `exists()` — 1 SELECT; (2) `$this->mail->send()` — O SIDE EFFECT
 * (handoff real pro SMTP); (3) `registrarEntrega()` — 1 INSERT. Check,
 * side effect e write são 3 passos SEPARADOS, não uma transação atômica —
 * é exatamente essa sequência (nunca a intenção, o código de verdade) que
 * define a garantia real abaixo.
 *
 * GARANTIA REAL (auditoria adversarial final da 18.5.6, provada com
 * probes P1-P5 em `tests/Feature/GrdNotificacaoTest.php`) — **at-least-once
 * com deduplicação best-effort, NUNCA exactly-once**:
 * - Retry "limpo" (job re-executado depois que uma tentativa ANTERIOR já
 *   tinha COMPLETADO — passo 3 já rodou): `exists()` encontra a linha,
 *   pula o envio inteiro. **Protegido, é o caso mais comum na prática.**
 * - Exceção ANTES do passo 2 (`$this->mail->send()` nunca chegou a
 *   rodar): 0 e-mails, 0 linha — retry seguinte reenvia normalmente, sem
 *   nenhuma duplicata real. **Seguro.**
 * - Exceção ENTRE os passos 2 e 3 (o e-mail JÁ FOI ACEITO pelo provedor —
 *   entregue de verdade — mas o processo morre, ou o INSERT do passo 3
 *   falha por outro motivo antes de gravar): a linha NUNCA existe, então
 *   um retry subsequente reenvia o e-mail DE VERDADE. **JANELA REAL DE
 *   DUPLICIDADE — não protegida, não é hipotética.**
 * - 2 processos genuinamente concorrentes passam pelo passo 1 ANTES de
 *   qualquer um alcançar o passo 3 (ambos veem "não existe"): os 2 EXECUTAM
 *   o passo 2 (2 e-mails reais saem), e só na hora do passo 3 a `UNIQUE
 *   (evento_usuario_id, canal)` decide qual das 2 tentativas de INSERT
 *   sobrevive — a outra recebe 1062 e é engolida em `registrarEntrega()`.
 *   **A UNIQUE impede 2 LINHAS no ledger, nunca impediu os 2 ENVIOS reais
 *   que já tinham acontecido — ela protege o REGISTRO, não o SIDE EFFECT.**
 *   Não confundir as duas coisas.
 *
 * Este é o mesmo residual que qualquer sistema de entrega at-least-once
 * carrega quando o provedor externo (SMTP) não oferece uma idempotency key
 * própria — não resolvido por nenhum mecanismo existente no projeto (nem
 * para os 4 Notifications mail+ZApi já em produção). Fechar essa janela
 * por completo exigiria um padrão de outbox transacional/2-fase com o
 * provedor de e-mail, fora do escopo desta etapa (decisão explícita:
 * proteção forte contra o caso comum, garantia nomeada com precisão pro
 * caso raro, em vez de uma falsa promessa de exactly-once).
 *
 * Achado durante a implementação — `TenantContext::actingAs()`
 * obrigatório: `GrdAlertaEntrega` usa `BelongsToTenant`, cujo auto-stamp
 * de `tenant_id` depende de `Auth::check()`. Este `send()` roda DENTRO de
 * um job de fila (worker sem usuário autenticado) — sem `actingAs()`,
 * tanto a checagem `exists()` quanto o `create()` operariam com
 * `tenant_id` nulo/errado (o global scope de `BelongsToTenant` filtraria
 * a checagem pra não encontrar nada, e o `create()` falharia por coluna
 * sem valor). `$notification->metadadosAlertaGrd()['tenant_id']` foi
 * capturado por `AlertaDistribuicaoGrd` ENQUANTO ainda rodava no request
 * original autenticado — nunca resolvido aqui.
 *
 * **ACHADO C, corrigido na auditoria adversarial final da 18.5.6** — a
 * primeira versão construía `new Tenant(['id' => $meta['tenant_id']])`.
 * `id` NÃO está em `Tenant::$fillable` (é auto-gerado por `HasUlids`), e
 * como `Tenant` não é `totallyGuarded()` (`$fillable` não está vazio),
 * `Model::fill()` não lança exceção — só DESCARTA `id` em silêncio.
 * Confirmado empiricamente via tinker: `(new Tenant(['id' =>
 * 'x']))->id` é `null`. Isso fazia `TenantContext::actingAs($tenant,
 * ...)` setar `$override = null`, e como `currentId()` só usa o override
 * quando `!== null`, a chamada CAÍA DIRETO pro fallback ambiente (auth do
 * processo) — um no-op completo, nunca detectado porque todo teste até a
 * P10 usava um único tenant (ambiente e notificação sempre coincidiam por
 * acidente). Em worker real sem auth isso degradava de forma inofensiva
 * (`currentId()` cai pra `null`, o hook `creating()` do `BelongsToTenant`
 * não sobrescreve nada, e o `tenant_id` explícito já presente em `$meta`
 * sobrevive); mas em qualquer execução síncrona com OUTRO tenant
 * autenticado no ambiente (fila `sync`, testes, etc.), o hook
 * `creating()` SOBRESCREVIA `tenant_id` pelo tenant ERRADO — provado pela
 * P10, que gravava a linha do "tenant B" com `tenant_id` do tenant A.
 * Corrigido com `(new Tenant())->forceFill(['id' => $meta['tenant_id']])`
 * — `forceFill()` bypassa o guard de mass assignment sem precisar tornar
 * `id` fillable globalmente em `Tenant` (mudança de escopo maior, fora
 * desta correção pontual) e sem alterar `TenantContext::actingAs()` em
 * si (a abstração sempre esteve correta — o bug era só na construção do
 * `Tenant` passado a ela). Ver `tests/Feature/GrdNotificacaoTest.php`
 * (seção "ACHADO C") para os 6 cenários permanentes de prova.
 */
class GrdLedgerMailChannel
{
    public function __construct(private readonly MailChannel $mail)
    {
    }

    public function send($notifiable, Notification $notification): mixed
    {
        if (! method_exists($notification, 'metadadosAlertaGrd') || ! $notification->id) {
            // Nunca deveria acontecer pros 2 Notifications de alerta GRD (defensivo) —
            // sem identidade determinística, não há como aplicar o ledger; delega puro.
            return $this->mail->send($notifiable, $notification);
        }

        $meta = $notification->metadadosAlertaGrd();
        // NUNCA `new Tenant(['id' => ...])` — 'id' não está em Tenant::$fillable
        // (auto-gerado por HasUlids), então mass assignment comum DESCARTA o
        // valor em silêncio, deixando $tenant->id null e tornando o
        // actingAs() abaixo um no-op (achado C da auditoria adversarial da
        // 18.5.6). forceFill() bypassa o guard sem alterar Tenant::$fillable.
        $tenant = (new Tenant())->forceFill(['id' => $meta['tenant_id']]);

        return TenantContext::actingAs($tenant, function () use ($notifiable, $notification, $meta) {
            if (GrdAlertaEntrega::where('evento_usuario_id', $notification->id)->where('canal', 'mail')->exists()) {
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
            GrdAlertaEntrega::create(array_merge($meta, [
                'usuario_id' => $usuarioId,
                'evento_usuario_id' => $eventoUsuarioId,
                'canal' => 'mail',
                'enviado_em' => now(),
            ]));
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // Corrida concorrente: outro processo já registrou a mesma chave — o e-mail
            // JÁ FOI ENVIADO por este processo (não tem como desenviar), mas a gravação
            // em duplicata é só ruído de auditoria, nunca um reenvio. Idempotência, não erro.
        }
    }
}
