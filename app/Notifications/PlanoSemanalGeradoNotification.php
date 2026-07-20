<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Work;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PlanoSemanalGeradoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Work $obra, private readonly User $gerador, private readonly int $totalAtividades)
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
            'titulo'   => 'Plano semanal gerado',
            'mensagem' => "{$this->gerador->first_name} gerou o plano semanal da obra \"{$this->obra->name}\" com {$this->totalAtividades} atividade(s) comprometidas.",
            'icone'    => 'bx-calendar-check',
            'cor'      => 'primary',
            'link'     => route('radar.plano-semanal'),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
