<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BoasVindasNotification extends Notification implements ShouldQueue
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
            ->subject('🎉 Bem-vindo(a) ao '.config('app.name').'!')
            ->greeting('Sua conta está verificada, '.$notifiable->first_name.'!')
            ->line('Agora você tem acesso ao '.config('app.name').' — o quadro de restrições que antecipa e remove impedimentos antes da execução, baseado no Last Planner System.')
            ->line('Com ele, sua equipe consegue:')
            ->line('📋 Importar o cronograma e enxergar o Lookahead com hierarquia e filtros de verdade.')
            ->line('🚧 Cadastrar e resolver restrições antes que elas travem a atividade — prontidão sempre em dia.')
            ->line('📊 Acompanhar PPC, aderência e curvas de avanço em relatórios prontos para o cliente.')
            ->action('Acessar o Painel de Controle', url('/app/home'))
            ->line('Qualquer dúvida, é só chamar a gente pelo canal de Suporte dentro do sistema.')
            ->salutation('Equipe '.config('app.name'));
    }
}
