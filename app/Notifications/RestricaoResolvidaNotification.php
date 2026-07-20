<?php

namespace App\Notifications;

use App\Models\Restricao;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RestricaoResolvidaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Restricao $restricao, private readonly User $resolvedor)
    {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        $atividade = $this->restricao->atividade;
        return [
            'titulo'   => 'Restrição resolvida',
            'mensagem' => "{$this->resolvedor->first_name} resolveu a restrição em \"{$atividade->nome}\": {$this->restricao->descricao}",
            'icone'    => 'bx-check-circle',
            'cor'      => 'success',
            'link'     => route('radar.entrar', ['obraId' => $atividade->obra_id]),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
