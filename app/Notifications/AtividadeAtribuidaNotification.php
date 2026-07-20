<?php

namespace App\Notifications;

use App\Models\Atividade;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AtividadeAtribuidaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Atividade $atividade, private readonly User $atribuidor)
    {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return [
            'titulo'   => 'Atividade atribuída a você',
            'mensagem' => "{$this->atribuidor->first_name} atribuiu a atividade \"{$this->atividade->nome}\" a você.",
            'icone'    => 'bx-task',
            'cor'      => 'info',
            'link'     => route('radar.lookahead'),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
