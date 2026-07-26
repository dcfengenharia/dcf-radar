<?php

namespace App\Notifications;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NovoFeedbackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Feedback $feedback)
    {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $feedback = $this->feedback;
        $usuario = $feedback->user;

        return (new MailMessage)
            ->subject("Novo feedback do sistema — {$feedback->tipo->label()}")
            ->greeting('Novo feedback recebido')
            ->line("**Tipo:** {$feedback->tipo->label()}")
            ->line("**Empresa:** {$feedback->tenant?->name}")
            ->line("**Enviado por:** {$usuario?->first_name} {$usuario?->last_name} ({$usuario?->email})")
            ->line("**Data:** {$feedback->created_at->format('d/m/Y H:i')}")
            ->line("**Página de origem:** ".($feedback->url_origem ?: 'não informada'))
            ->line('**Mensagem:**')
            ->line($feedback->mensagem)
            ->salutation('Sistema '.config('app.name'));
    }
}
