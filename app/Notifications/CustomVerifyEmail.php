<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as OriginalVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class CustomVerifyEmail extends OriginalVerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        // Conexão dedicada para não depender do QUEUE_CONNECTION global (que
        // permanece 'sync' para não afetar outros jobs, como ImportarCronogramaJob).
        $this->connection = 'redis';
    }

    /**
     * Modifica exclusivamente a mensagem e o visual do e-mail.
     * Os métodos via() e toMail() são herdados automaticamente da classe pai.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('🎉 Sua conta está quase pronta!')
            ->greeting('Fala comigo bb!!! 😎')
            ->line('Estamos felizes em ter você conosco.')
            ->line('Falta apenas um último passo para liberar seu acesso e começar a aproveitar todas as funcionalidades da plataforma.')
            ->action('🚀 Ativar Minha Conta', $url)
            ->line('Depois disso, é só entrar e começar.')
            ->line('⚠️ Se você não realizou este cadastro, pode ignorar esta mensagem com tranquilidade.')
            ->salutation('Equipe DCF.eng');
    }
}
