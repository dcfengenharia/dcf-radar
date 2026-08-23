<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 18, Etapa 18.5.7 — Digest Semanal de Pendências GED: "o que
 * continua pendente hoje e precisa voltar à atenção da equipe?". Todo
 * número já vem PRÉ-CALCULADO pelo chamador (App\Services\
 * DigestPendenciasGed, que consome DetectorCopiasObsoletasGrd/
 * CandidatosNovaEntregaGrd sem duplicar nenhuma regra) — esta classe
 * nunca recalcula nada, só formata (mesma filosofia de fotografia já
 * usada nos Alertas A/B imediatos).
 *
 * **Canais — decisão explícita do usuário (18.5.7)**: só `database`+
 * `broadcast`, IGUAL ao Digest Semanal de Prontidão
 * (`App\Notifications\ProntidaoSemanalNotification`, Ciclo 16, A.3) —
 * mesma convenção já documentada lá: evento RECORRENTE pra múltiplos
 * usuários da obra, nunca os canais externos (mail/WhatsApp) reservados a
 * alertas raros e pessoais. Os Alertas A/B imediatos (18.5.5/18.5.6)
 * continuam sendo os únicos responsáveis por mail/WhatsApp na GRD — o
 * digest NUNCA usa `GrdLedgerMailChannel`/`GrdLedgerZApiChannel` nem
 * `App\Models\GrdAlertaEntrega` (que nem serviria semanticamente: aquele
 * ledger tem `documento_engenharia_id`/`revisao_id` NOT NULL, identidade
 * de 1 evento por 1 documento/revisão — o digest é agregado por obra
 * inteira, sem nenhum documento/revisão específico).
 */
class GrdPendenciasDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $obraId,
        private readonly string $obraNome,
        private readonly int $obsoletasDocumentos,
        private readonly int $obsoletasDestinatarios,
        private readonly int $obsoletasQuantidadeFisica,
        private readonly int $candidatosDocumentos,
        private readonly int $candidatosDestinatarios,
    ) {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return [
            'titulo' => "Pendências GED — {$this->obraNome}",
            'mensagem' => $this->mensagem(),
            'icone' => 'bx-list-check',
            'cor' => 'warning',
            'link' => $this->link(),
            'link_obsoletas' => $this->obsoletasDocumentos > 0 ? $this->linkObsoletas() : null,
            'link_candidatos' => $this->candidatosDocumentos > 0 ? $this->linkCandidatos() : null,
            'obra_id' => $this->obraId,
            'obsoletas_documentos' => $this->obsoletasDocumentos,
            'obsoletas_destinatarios' => $this->obsoletasDestinatarios,
            'obsoletas_quantidade_fisica' => $this->obsoletasQuantidadeFisica,
            'candidatos_documentos' => $this->candidatosDocumentos,
            'candidatos_destinatarios' => $this->candidatosDestinatarios,
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * Só as 2 cláusulas cujo total é > 0 — o Command que despacha esta
     * Notification só chama isto quando pelo menos uma das 2 categorias
     * tem pendência (nunca as duas em zero), mas nada impede uma obra ter
     * só obsoletas OU só candidatos.
     */
    private function mensagem(): string
    {
        $partes = [];

        if ($this->obsoletasDocumentos > 0) {
            $partes[] = "{$this->obsoletasDocumentos} documento(s) com cópias obsoletas em campo "
                . "({$this->obsoletasDestinatarios} destinatário(s), {$this->obsoletasQuantidadeFisica} cópia(s) pendente(s)).";
        }

        if ($this->candidatosDocumentos > 0) {
            $partes[] = "{$this->candidatosDocumentos} documento(s) com nova revisão aguardando distribuição "
                . "({$this->candidatosDestinatarios} destinatário(s) candidato(s)).";
        }

        return implode(' ', $partes);
    }

    private function link(): string
    {
        return route('engenharia.grds', ['obra' => $this->obraId]);
    }

    private function linkObsoletas(): string
    {
        return route('engenharia.grds', ['obra' => $this->obraId, 'aba' => 'obsoletas']);
    }

    private function linkCandidatos(): string
    {
        return route('engenharia.grds', ['obra' => $this->obraId, 'aba' => 'candidatos']);
    }
}
