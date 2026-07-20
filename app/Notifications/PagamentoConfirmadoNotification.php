<?php

namespace App\Notifications;

use App\Models\AssinaturaFatura;
use App\Notifications\Channels\ZApiChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PagamentoConfirmadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly AssinaturaFatura $fatura)
    {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail', 'database', 'broadcast', ZApiChannel::class];
    }

    public function toWhatsApp($notifiable): string
    {
        return "Olá, {$notifiable->first_name}! Recebemos seu pagamento de R$ ".number_format($this->fatura->valor, 2, ',', '.').
            '. Assinatura ativa, obrigado! Ver: '.$this->link();
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pagamento confirmado')
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line('Recebemos seu pagamento de R$ '.number_format($this->fatura->valor, 2, ',', '.').'.')
            ->line('Sua assinatura está ativa. Obrigado!')
            ->action('Ver assinatura', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function toArray($notifiable): array
    {
        return [
            'titulo' => 'Pagamento confirmado',
            'mensagem' => 'Recebemos seu pagamento de R$ '.number_format($this->fatura->valor, 2, ',', '.').'. Assinatura ativa.',
            'icone' => 'bx-check-circle',
            'cor' => 'success',
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
