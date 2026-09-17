<?php

namespace App\Notifications;

use App\Models\Work;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class AdicionadoAObraNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * FASE 2C, Seção 4-8 — `$perfis` passou a ser uma Collection (1..N
     * perfis, nunca vazia — quem adiciona direto à equipe sempre
     * seleciona ao menos 1), pra o e-mail refletir corretamente um
     * convite/adição multiperfil.
     */
    public function __construct(private readonly Work $obra, private readonly Collection $perfis)
    {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $nomesPerfis = $this->perfis->pluck('nome')->implode(', ');

        return (new MailMessage)
            ->subject("Você foi adicionado à obra {$this->obra->name}")
            ->greeting('Novidade na sua conta!')
            ->line("Você foi adicionado à obra \"{$this->obra->name}\" com o(s) perfil(is) de {$nomesPerfis}.")
            ->action('Acessar a plataforma', route('gestao.minhas-obras'))
            ->salutation('Equipe ' . config('app.name'));
    }
}
