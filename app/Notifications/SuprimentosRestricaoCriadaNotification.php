<?php

namespace App\Notifications;

use App\Models\Atividade;
use App\Models\ItemSuprimento;
use App\Models\Restricao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 19, Etapa 19.7 — Alerta C: a falta de material da cadeia formal já
 * atingiu a necessidade da atividade e uma Restrição bloqueante nasceu
 * (ou reabriu) automaticamente. Mensagem deliberadamente diferenciada de
 * "risco" (Alerta A) — aqui a Restrição JÁ ESTÁ ATIVA, bloqueando a
 * prontidão da atividade.
 */
class SuprimentosRestricaoCriadaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Restricao $restricao,
        private readonly ItemSuprimento $pacote,
        private readonly Atividade $atividade,
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
            'titulo' => 'Restrição automática de Suprimentos ativa',
            'mensagem' => "Material do Pacote \"{$this->pacote->nome}\" ainda não recebido para a necessidade da atividade \"{$this->atividade->nome}\" — Restrição bloqueante ativa.",
            'icone' => 'bx-block',
            'cor' => 'danger',
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
