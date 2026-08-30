<?php

namespace App\Notifications;

use App\Models\ItemSuprimento;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 19, Etapa 19.7 — Alerta A: a melhor previsão de atendimento
 * (`ItemSuprimento::dataProjetadaAtendimento()`) já ultrapassa a
 * necessidade do cronograma. É um ALERTA, nunca cria Restrição sozinho —
 * mesmo padrão de canais de `ProntidaoSemanalNotification` (evento
 * recorrente pra múltiplos usuários da obra, database+broadcast, nunca
 * e-mail/WhatsApp — reservados a alertas raros/pessoais).
 */
class SuprimentosRiscoProjetadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ItemSuprimento $pacote,
        private readonly Carbon $necessidade,
        private readonly Carbon $atendimentoProjetado,
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
            'titulo' => 'Risco de atendimento em Suprimentos',
            'mensagem' => "Pacote \"{$this->pacote->nome}\" apresenta risco de atendimento: previsão {$this->atendimentoProjetado->format('d/m/Y')} ultrapassa a necessidade {$this->necessidade->format('d/m/Y')}.",
            'icone' => 'bx-trending-down',
            'cor' => 'warning',
            'link' => $this->link(),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    private function link(): string
    {
        return route('radar.entrar', ['obraId' => $this->pacote->obra_id]);
    }
}
