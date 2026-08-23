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
 * Ciclo 18, Etapa 18.5.5 — Alerta B: a revisão vigente de um documento
 * acabou de ser LIBERADA e existem destinatários que receberam a revisão
 * anterior mas ainda não receberam a vigente. É recomendação
 * operacional, nunca "entrega obrigatória". Todo número já vem
 * PRÉ-CALCULADO pelo chamador (App\Support\Grd\AlertaDistribuicaoGrd,
 * que consome CandidatosNovaEntregaGrd sem duplicar sua regra).
 *
 * Etapa 18.5.5.HARDENING — `$id` (UUIDv5 determinístico, ver
 * AlertaDistribuicaoGrd::idAlerta()) é atribuído pelo chamador ANTES do
 * envio, nunca gerado aleatoriamente aqui.
 *
 * Etapa 18.5.6 — canais externos via os 2 channels wrapper
 * `GrdLedgerMailChannel`/`GrdLedgerZApiChannel` (ver docblock idêntico em
 * GrdCopiasObsoletasNotification).
 */
class GrdCandidatosNovaEntregaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $obraId,
        private readonly string $obraNome,
        private readonly string $documentoId,
        private readonly string $documentoCodigo,
        private readonly string $revisaoId,
        private readonly string $revisaoVigente,
        private readonly int $quantidadeCandidatos,
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
            'titulo' => 'Nova revisão pronta para distribuição',
            'mensagem' => "{$this->documentoCodigo} — revisão {$this->revisaoVigente}. {$this->quantidadeCandidatos} destinatário(s) receberam revisão anterior e ainda não receberam a vigente.",
            'icone' => 'bx-send',
            'cor' => 'info',
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
            ->subject("[{$this->obraNome}] Nova revisão disponível para distribuição")
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line("O documento \"{$this->documentoCodigo}\" teve a revisão {$this->revisaoVigente} liberada.")
            ->line("Existem {$this->quantidadeCandidatos} destinatário(s) que receberam revisão anterior e ainda não receberam a vigente.")
            ->line('Acesse o controle de GRDs para verificar os destinatários e providenciar a distribuição, se aplicável.')
            ->action('Ver candidatos à nova entrega', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function toWhatsApp($notifiable): string
    {
        return "Radar EPC — {$this->obraNome}\n"
            ."A revisão {$this->revisaoVigente} do documento {$this->documentoCodigo} foi liberada.\n"
            ."Há {$this->quantidadeCandidatos} destinatário(s) que receberam revisão anterior e ainda não receberam a vigente.\n"
            .'Consulte Engenharia > GRDs.';
    }

    /**
     * Ciclo 18, Etapa 18.5.6 — metadados pro ledger de entrega por canal
     * (App\Models\GrdAlertaEntrega). Mesmo padrão de
     * GrdCopiasObsoletasNotification::metadadosAlertaGrd().
     */
    public function metadadosAlertaGrd(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'obra_id' => $this->obraId,
            'documento_engenharia_id' => $this->documentoId,
            'revisao_id' => $this->revisaoId,
            'tipo_alerta' => 'candidatos_nova_entrega',
        ];
    }

    private function link(): string
    {
        return route('engenharia.grds', ['obra' => $this->obraId, 'aba' => 'candidatos']);
    }

    /** Mesma justificativa/critério de GrdCopiasObsoletasNotification::failed(). */
    public function failed(\Throwable $e): void
    {
        if ($e instanceof QueryException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return;
        }

        report($e);
    }
}
