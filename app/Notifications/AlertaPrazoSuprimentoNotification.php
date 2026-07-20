<?php

namespace App\Notifications;

use App\Models\ItemSuprimento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertaPrazoSuprimentoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ItemSuprimento $item,
        private readonly int $marcoDias,
    ) {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail($notifiable): MailMessage
    {
        $necessidade = $this->item->necessidade();

        $mail = (new MailMessage)
            ->subject("Atenção: faltam {$this->marcoDias} dias pro prazo do item \"{$this->item->nome}\"")
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line("O item de suprimento \"{$this->item->nome}\" da obra \"{$this->item->obra->name}\" está a {$this->marcoDias} dias (ou menos) do prazo em que é necessário.");

        if ($necessidade) {
            $mail->line("Prazo necessário: {$necessidade->format('d/m/Y')}.");
        }

        return $mail
            ->action('Ver no Mapa de Suprimentos', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function toArray($notifiable): array
    {
        return [
            'titulo' => "Faltam {$this->marcoDias} dias pro prazo de suprimento",
            'mensagem' => "\"{$this->item->nome}\" ({$this->item->obra->name}) está a {$this->marcoDias} dias (ou menos) do prazo necessário.",
            'icone' => 'bx-time-five',
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
        return route('radar.entrar', ['obraId' => $this->item->obra_id]);
    }
}
