<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgradecimentoFeedbackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Obrigado pelo seu feedback!')
            ->greeting('Obrigado pelo seu feedback!')
            ->line('Recebemos sua mensagem e ela já foi encaminhada para a nossa equipe.')
            ->line('Estamos numa fase de testes e cada contribuição como a sua nos ajuda a construir um sistema melhor para todos.')
            ->line('Sua opinião importa de verdade para a evolução da plataforma — continue contando com a gente para o que precisar.')
            ->salutation('Equipe '.config('app.name'));
    }
}
