<?php

namespace App\Notifications;

use App\Models\Convite;
use App\Support\TemplateConvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConviteObraNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Convite $convite)
    {
        // Mesma conexão dedicada usada em CustomVerifyEmail, pra não
        // depender do QUEUE_CONNECTION global (sync).
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $obra = $this->convite->obra;
        $tenant = $obra->tenant;
        $convidadoPor = $this->convite->convidadoPor;
        $papel = $this->convite->perfil->nome;

        $assunto = TemplateConvite::substituir(
            $tenant->convite_email_assunto ?: TemplateConvite::assuntoPadrao(),
            $obra->name,
            "{$convidadoPor->first_name} {$convidadoPor->last_name}",
            $papel
        );

        $mensagem = TemplateConvite::substituir(
            $tenant->convite_email_mensagem ?: TemplateConvite::mensagemPadrao(),
            $obra->name,
            "{$convidadoPor->first_name} {$convidadoPor->last_name}",
            $papel
        );

        return (new MailMessage)
            ->subject($assunto)
            ->greeting('Fala comigo bb!!! Você foi convidado!')
            ->line($mensagem)
            ->action('Aceitar Convite', route('convite.show', $this->convite->token))
            ->line('Se você não esperava este convite, pode ignorar este e-mail com tranquilidade.')
            ->salutation('Equipe ' . config('app.name'));
    }
}
