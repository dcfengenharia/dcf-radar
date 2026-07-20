<?php

namespace App\Notifications;

use App\Models\Restricao;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RestricaoCriadaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Restricao $restricao, private readonly User $criador)
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
            'titulo'   => 'Nova restrição atribuída a você',
            'mensagem' => "{$this->criador->first_name} criou uma restrição em \"{$atividade->nome}\": {$this->restricao->descricao}",
            'icone'    => 'bx-block',
            'cor'      => 'warning',
            'link'     => route('radar.entrar', ['obraId' => $atividade->obra_id]),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
