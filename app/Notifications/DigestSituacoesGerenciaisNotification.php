<?php

namespace App\Notifications;

use App\Notifications\Channels\SituacaoLedgerMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 21, Etapa 21.4 — Digest Operacional diário de Situações
 * Gerenciais (Seção 9/10 do pedido: "1 digest agregado por usuário e por
 * obra... não criar 2 digests sem necessidade" — só este, nenhum
 * segundo digest semanal/executivo criado nesta etapa).
 *
 * **Nunca recalcula risco** (Seção 2) — todo dado já vem PRONTO do
 * `App\Console\Commands\NotificarDigestSituacoesGerenciaisCommand`, que
 * só LÊ `App\Models\SituacaoOcorrencia` (já mantida em dia por
 * `SincronizarSituacoesGerenciais`) — nunca reconsulta
 * `SituacoesGerenciaisQuery` diretamente.
 *
 * **Retry-safe via o MESMO ledger dos e-mails imediatos** (Seção 13) —
 * `via()` inclui `SituacaoLedgerMailChannel::class` (nunca `'mail'`
 * cru), com `$this->id` atribuído deterministicamente pelo Command
 * ANTES do envio (mesmo mecanismo de `SincronizarSituacoesGerenciais::
 * enviarComIdempotencia()`) — um retry do job de fila do canal mail
 * nunca reenvia o MESMO digest.
 */
class DigestSituacoesGerenciaisNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, array<int, array{tipo: string, severidade: string, descricao: string}>>  $porDominio
     * @param  array<int, array{tipo: string, descricao: string}>  $resolvidasRecentemente
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $obraId,
        private readonly string $obraNome,
        private readonly array $porDominio,
        private readonly array $resolvidasRecentemente,
        private readonly int $totalCriticas,
        private readonly int $totalAltas,
        private readonly int $totalOutras,
    ) {
    }

    /**
     * `broadcast` sempre por ÚLTIMO — mesmo achado documentado em
     * `SincronizarSituacoesGerenciais::resolverCanais()`: sob fila
     * síncrona, uma exceção do canal `broadcast` (Reverb inalcançável
     * fora do navegador) aborta os canais SEGUINTES do array — colocá-lo
     * antes do canal de e-mail impediria o digest de ser entregue por
     * e-mail em qualquer ambiente de fila síncrona.
     */
    public function via($notifiable): array
    {
        return ['database', SituacaoLedgerMailChannel::class, 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        $total = $this->totalCriticas + $this->totalAltas + $this->totalOutras;

        return [
            'titulo' => 'Digest de Situações Gerenciais',
            'mensagem' => "{$this->obraNome}: {$total} situação(ões) ativa(s) — {$this->totalCriticas} crítica(s), {$this->totalAltas} alta(s).",
            'icone' => 'bx-list-ul',
            'cor' => $this->totalCriticas > 0 ? 'danger' : ($this->totalAltas > 0 ? 'warning' : 'secondary'),
            'tipo' => 'digest_situacoes_gerenciais',
            'obra_id' => $this->obraId,
            'obra_nome' => $this->obraNome,
            'link' => $this->link(),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * Seção 20 — visão resumida primeiro, depois grupos por domínio.
     * Nunca visualmente gigantesco: cada domínio lista no máximo as
     * situações já filtradas/priorizadas pelo Command (críticas/altas
     * primeiro dentro de cada grupo, mas SEM paginação/scroll infinito —
     * é responsabilidade do Command não deixar a lista crescer sem
     * limite; esta classe só formata o que recebeu).
     */
    public function toMail($notifiable): MailMessage
    {
        $mensagem = (new MailMessage)
            ->subject("[{$this->obraNome}] Digest de Situações Gerenciais")
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line("Resumo de {$this->obraNome}: {$this->totalCriticas} crítica(s), {$this->totalAltas} alta(s), {$this->totalOutras} outra(s) situação(ões) ativa(s).");

        foreach ($this->porDominio as $dominio => $situacoes) {
            $mensagem->line('**'.ucfirst($dominio).'**');
            foreach ($situacoes as $s) {
                $mensagem->line("- [{$s['severidade']}] {$s['descricao']}");
            }
        }

        if (! empty($this->resolvidasRecentemente)) {
            $mensagem->line('**Resolvidas recentemente**');
            foreach ($this->resolvidasRecentemente as $s) {
                $mensagem->line("- {$s['descricao']}");
            }
        }

        return $mensagem
            ->action('Ver Central de Notificações', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    public function metadadosLedgerSituacao(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'obra_id' => $this->obraId,
        ];
    }

    private function link(): string
    {
        return route('notificacoes.index');
    }
}
