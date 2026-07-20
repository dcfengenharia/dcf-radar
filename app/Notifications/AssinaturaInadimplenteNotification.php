<?php

namespace App\Notifications;

use App\Models\Assinatura;
use App\Notifications\Channels\ZApiChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssinaturaInadimplenteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Assinatura $assinatura)
    {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail', 'database', 'broadcast', ZApiChannel::class];
    }

    public function toWhatsApp($notifiable): string
    {
        return "Olá, {$notifiable->first_name}! Não encontramos o pagamento da sua assinatura e o acesso à obra foi suspenso. Regularize pra voltar a usar: {$this->link()}";
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Assinatura em atraso — acesso suspenso')
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line('Não encontramos o pagamento da sua assinatura e o acesso à obra foi suspenso.')
            ->line('Regularize o pagamento para voltar a usar o sistema.')
            ->action('Regularizar assinatura', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function toArray($notifiable): array
    {
        return [
            'titulo' => 'Assinatura em atraso',
            'mensagem' => 'Não encontramos o pagamento da sua assinatura — acesso suspenso até regularizar.',
            'icone' => 'bx-error-circle',
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
        return route('app.empresa.assinatura');
    }
}
