<?php

namespace App\Notifications;

use App\Models\Perfil;
use App\Models\Work;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdicionadoAObraNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Work $obra, private readonly Perfil $perfil)
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
            ->subject("Você foi adicionado à obra {$this->obra->name}")
            ->greeting('Novidade na sua conta!')
            ->line("Você foi adicionado à obra \"{$this->obra->name}\" com o perfil de {$this->perfil->nome}.")
            ->action('Acessar a plataforma', route('gestao.minhas-obras'))
            ->salutation('Equipe ' . config('app.name'));
    }
}
