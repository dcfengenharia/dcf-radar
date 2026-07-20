<?php

namespace App\Notifications;

use App\Models\Restricao;
use App\Models\User;
use App\Models\Work;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class RestricoesPendentesNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, Restricao>  $restricoes  Já filtradas para este responsável, com 'atividade' eager-loaded.
     */
    public function __construct(
        private readonly User $responsavel,
        private readonly Collection $restricoes,
        private readonly Work $obra,
    ) {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Você tem {$this->restricoes->count()} restrição(ões) pendente(s) em {$this->obra->name}")
            ->greeting("Olá, {$this->responsavel->first_name}!")
            ->line("Você é responsável por {$this->restricoes->count()} restrição(ões) em aberto na obra \"{$this->obra->name}\":");

        foreach ($this->restricoes as $restricao) {
            $linha = "• [{$restricao->atividade->nome}] {$restricao->descricao} — status: {$restricao->status->label()}";
            if ($restricao->prazo_limite) {
                $linha .= " (prazo: {$restricao->prazo_limite->format('d/m/Y')})";
            }
            $mail->line($linha);
        }

        return $mail
            ->action('Ver restrições', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function toArray($notifiable): array
    {
        $n = $this->restricoes->count();

        return [
            'titulo' => "Você tem {$n} restrição(ões) pendente(s)",
            'mensagem' => "Na obra \"{$this->obra->name}\", você é responsável por {$n} restrição(ões) em aberto.",
            'icone' => 'bx-error',
            'cor' => 'warning',
            'link' => $this->link(),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * Entra na obra certa (mesmo que não seja a ativa na sessão de
     * quem clicar) e já cai no Quadro filtrado pelas próprias
     * restrições — funciona tanto logado quanto via redirect-after-login.
     */
    private function link(): string
    {
        return route('radar.entrar', ['obraId' => $this->obra->id, 'responsavel' => $this->responsavel->id]);
    }

    /**
     * Getter público só pra permitir asserção em teste (a coleção é
     * private/readonly no construtor).
     */
    public function restricoesParaTeste(): Collection
    {
        return $this->restricoes;
    }
}
