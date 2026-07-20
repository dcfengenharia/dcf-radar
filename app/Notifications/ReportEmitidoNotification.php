<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReportEmitidoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Report $report, private readonly User $emissor)
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
            'titulo'   => 'Report emitido',
            'mensagem' => "{$this->emissor->first_name} emitiu o report da obra \"{$this->report->obra->name}\".",
            'icone'    => 'bx-file-check',
            'cor'      => 'success',
            'link'     => route('radar.relatorios.show', $this->report->id),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
