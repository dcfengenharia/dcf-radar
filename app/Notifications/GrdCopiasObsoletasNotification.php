<?php

namespace App\Notifications;

use App\Notifications\Channels\GrdLedgerMailChannel;
use App\Notifications\Channels\GrdLedgerZApiChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 18, Etapa 18.5.5 — Alerta A: uma nova revisão nasceu e existem
 * cópias físicas de revisão anterior ainda pendentes de recolhimento em
 * campo (independe da nova revisão estar liberada). Todo número já vem
 * PRÉ-CALCULADO pelo chamador (App\Support\Grd\AlertaDistribuicaoGrd,
 * que consome DetectorCopiasObsoletasGrd sem duplicar sua regra) — esta
 * classe nunca recalcula nada, só formata o que já recebeu (mesma
 * filosofia de "fotografia" já usada em Report/Health Check).
 *
 * Etapa 18.5.5.HARDENING — `$id` (UUIDv5 determinístico, ver
 * AlertaDistribuicaoGrd::idAlerta()) é atribuído pelo chamador ANTES do
 * envio, nunca gerado aleatoriamente aqui.
 *
 * Etapa 18.5.6 — canais externos (mail/WhatsApp) via os 2 channels
 * wrapper `GrdLedgerMailChannel`/`GrdLedgerZApiChannel` (nunca o `'mail'`
 * cru nem `ZApiChannel::class` direto) — cada um consulta/grava
 * `App\Models\GrdAlertaEntrega` usando o MESMO `$this->id` como chave de
 * idempotência por canal, nunca uma segunda regra. `documentoId`/
 * `revisaoId`/`tenantId` existem só pra alimentar essa gravação (nunca
 * aparecem em `toArray()`/`toBroadcast()` — o payload exposto ao usuário
 * continua sem nenhum ULID interno, mesma garantia já auditada na 18.5.5).
 *
 * `tenantId` é OBRIGATÓRIO aqui por um motivo estrutural descoberto
 * durante a implementação: `GrdAlertaEntrega` usa `BelongsToTenant`, cujo
 * auto-stamp de `tenant_id` depende de `TenantContext::currentId()` →
 * `Auth::check()`. Como os 2 channels wrapper rodam DENTRO de um job de
 * fila (`SendQueuedNotifications`, processado por um worker sem nenhum
 * usuário autenticado), confiar no auto-stamp gravaria `tenant_id=null`
 * (ou lançaria erro de coluna sem default) — `AlertaDistribuicaoGrd`
 * captura o tenant_id ENQUANTO ainda roda no request original (autenticado,
 * dentro do `DB::afterCommit()` síncrono) e repassa via construtor; os 2
 * channels usam `TenantContext::actingAs()` (mesmo mecanismo já usado por
 * Jobs/Commands de plataforma) pra gravar/consultar `GrdAlertaEntrega` com
 * o tenant certo, mesmo sem usuário autenticado no worker.
 */
class GrdCopiasObsoletasNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $obraId,
        private readonly string $obraNome,
        private readonly string $documentoId,
        private readonly string $documentoCodigo,
        private readonly string $revisaoId,
        private readonly string $revisaoNova,
        private readonly int $quantidadePendente,
    ) {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast', GrdLedgerMailChannel::class, GrdLedgerZApiChannel::class];
    }

    public function toArray($notifiable): array
    {
        return [
            'titulo' => 'Revisão nova com cópias antigas em campo',
            'mensagem' => "{$this->documentoCodigo} — revisão {$this->revisaoNova}. Existem {$this->quantidadePendente} cópia(s) de revisão anterior ainda pendente(s) em campo.",
            'icone' => 'bx-error-circle',
            'cor' => 'warning',
            'link' => $this->link(),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->obraNome}] Revisão nova com cópias antigas em campo")
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line("O documento \"{$this->documentoCodigo}\" recebeu a revisão {$this->revisaoNova}.")
            ->line("Existem {$this->quantidadePendente} cópia(s) de revisão anterior ainda pendente(s) em campo.")
            ->line('Acesse o controle de GRDs para verificar os destinatários e registrar os recolhimentos necessários.')
            ->action('Ver cópias obsoletas', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function toWhatsApp($notifiable): string
    {
        return "Radar EPC — {$this->obraNome}\n"
            ."Documento {$this->documentoCodigo}, revisão {$this->revisaoNova}.\n"
            ."Há {$this->quantidadePendente} cópia(s) de revisões anteriores ainda em campo.\n"
            ."Acesse o Radar > Engenharia > GRDs para verificar e registrar o recolhimento.";
    }

    /**
     * Ciclo 18, Etapa 18.5.6 — metadados pro ledger de entrega por canal
     * (App\Models\GrdAlertaEntrega). `tipo_alerta` distingue este de
     * GrdCandidatosNovaEntregaNotification — mesmos 2 valores já usados
     * por AlertaDistribuicaoGrd::idAlerta().
     */
    public function metadadosAlertaGrd(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'obra_id' => $this->obraId,
            'documento_engenharia_id' => $this->documentoId,
            'revisao_id' => $this->revisaoId,
            'tipo_alerta' => 'copias_obsoletas',
        ];
    }

    private function link(): string
    {
        return route('engenharia.grds', ['obra' => $this->obraId, 'aba' => 'obsoletas']);
    }

    /**
     * Chamado por `SendQueuedNotifications::failed()` quando o job (rodando
     * em fila, fora do processo que chamou `AlertaDistribuicaoGrd`) lança
     * uma exceção — caminho real de produção pro caso raro de corrida em
     * que 2 processos passam pelo `exists()` de `enviarComIdempotencia()`
     * antes de qualquer um dos dois gravar. A PRIMARY KEY de `notifications.id`
     * (mesmo UUID determinístico nos dois) garante que só 1 sobrevive — o
     * job "perdedor" cai aqui. SQLSTATE 23000 / MySQL 1062 (duplicate entry)
     * é tratado como idempotência bem-sucedida, nunca como erro real — mesmo
     * critério de `PlanoAcao::transformarEmRestricoes()`. Qualquer outra
     * exceção continua sendo reportada normalmente.
     */
    public function failed(\Throwable $e): void
    {
        if ($e instanceof QueryException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return;
        }

        report($e);
    }
}
