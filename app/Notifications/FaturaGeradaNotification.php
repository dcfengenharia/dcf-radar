<?php

namespace App\Notifications;

use App\Models\AssinaturaFatura;
use App\Notifications\Channels\ZApiChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FaturaGeradaNotification extends Notification implements ShouldQueue
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
        $metodo = $this->fatura->metodo_pagamento === 'pix' ? 'Pix' : 'boleto';

        return "Olá, {$notifiable->first_name}! Sua próxima cobrança ({$metodo}, R$ ".number_format($this->fatura->valor, 2, ',', '.').
            ") já está disponível, vencimento {$this->fatura->vencimento->format('d/m/Y')}. Ver: {$this->link()}";
    }

    public function toMail($notifiable): MailMessage
    {
        $metodo = $this->fatura->metodo_pagamento === 'pix' ? 'Pix' : 'boleto';

        return (new MailMessage)
            ->subject('Nova cobrança disponível')
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line("Sua próxima cobrança ({$metodo}) já está disponível: R$ ".number_format($this->fatura->valor, 2, ',', '.').'.')
            ->line("Vencimento: {$this->fatura->vencimento->format('d/m/Y')}.")
            ->action('Ver cobrança', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function toArray($notifiable): array
    {
        $metodo = $this->fatura->metodo_pagamento === 'pix' ? 'Pix' : 'boleto';

        return [
            'titulo' => 'Nova cobrança disponível',
            'mensagem' => "Cobrança de {$metodo} (R$ ".number_format($this->fatura->valor, 2, ',', '.').") vence em {$this->fatura->vencimento->format('d/m/Y')}.",
            'icone' => 'bx-receipt',
            'cor' => 'warning',
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
