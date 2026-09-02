<?php

namespace App\Notifications;

use App\Notifications\Channels\SituacaoLedgerMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 21, Etapa 21.3/21.4 — canal único (`database`+`broadcast`
 * sempre; `mail` condicional, ver abaixo) das 11
 * `App\Enums\TipoSituacaoGerencial`. Deliberadamente UMA classe
 * genérica, não 11 (diferente do padrão "1 classe por alerta" usado em
 * GRD/19.7) — o catálogo já é uniforme via `App\DTOs\Gestao\
 * SituacaoGerencial`, então 11 classes quase idênticas duplicariam
 * estrutura sem nenhum ganho.
 *
 * **Snapshot, não recálculo (21.3, Seção 14)** — diferente de
 * `SuprimentosPedidoAtrasadoNotification`/`GrdCopiasObsoletasNotification`
 * (que recebem o MODEL e recalculam texto quando o job de fila roda,
 * possivelmente minutos/horas depois), esta classe recebe um array JÁ
 * RESOLVIDO em texto puro no momento da sincronização
 * (`App\Support\Gestao\SincronizarSituacoesGerenciais`) — a mensagem
 * histórica nunca muda mesmo que a entidade de origem mude ou seja
 * excluída depois.
 *
 * **21.4 — canais e política nunca decididos aqui** (Seção 2/6 do
 * pedido: "não espalhar `if` por Notifications"): `$payload['canais']`
 * já vem PRONTO de `SincronizarSituacoesGerenciais` (que consulta
 * `App\Support\Gestao\PoliticaEntregaSituacao`) — `via()` só devolve o
 * que já foi decidido, nunca reavalia severidade/cooldown/tipo. Quando
 * `mail` está entre os canais elegíveis, o valor É
 * `SituacaoLedgerMailChannel::class` (nunca a string `'mail'` crua) —
 * é o wrapper de idempotência quem garante que um retry do job de fila
 * do canal `mail` nunca reenvia um e-mail já entregue (Seção 13).
 *
 * `link` é resolvido em `toArray()`/`toMail()`, nunca no payload —
 * depende de `$this->id`, que só é conhecido depois que o UUIDv5
 * determinístico é atribuído (mesmo mecanismo de `AlertaCadeiaSuprimento::
 * enviarComIdempotencia()`) e ANTES de `$user->notify()` — o link aponta
 * sempre pro wrapper `notificacoes.abrir` (nunca uma rota direta),
 * responsável por validar acesso/obra/entidade no momento do clique
 * (Seção 19, 21.4), nunca no momento do envio.
 */
class SituacaoGerencialNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Achado da implementação (Ciclo 21, Etapa 21.3): notificações
     * legadas (`SuprimentosPedidoAtrasadoNotification` etc.) fixam
     * `$this->connection = 'redis'` incondicionalmente — em produção
     * isso já é o default (`QUEUE_CONNECTION=redis` no `.env`), mas
     * FORÇAR essa conexão ignora o `QUEUE_CONNECTION=sync` que
     * `phpunit.xml` define pra testes, empurrando o job pro Redis real
     * mesmo dentro da suíte. Deliberadamente NÃO fixado aqui — herda
     * `config('queue.default')`.
     *
     * @param  array{
     *     titulo: string, mensagem: string, icone: string, cor: string,
     *     tipo: string, severidade: string, obra_id: string, obra_nome: string,
     *     ocorrencia_id: string, motivo: string, episodio: int,
     *     entidade_tipo: string, entidade_id: string, contexto: array<string, mixed>,
     *     canais: array<int, string>, tenant_id?: string
     * }  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {
    }

    public function via($notifiable): array
    {
        return $this->payload['canais'] ?? ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return array_merge($this->arrayExibicao(), [
            'link' => $this->link(),
        ]);
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * E-mail imediato — Seção 20 do pedido: claro e factual (situação,
     * severidade, obra, motivo, link), nunca visualmente gigantesco.
     * Linguagem sempre a mesma de `$this->payload['mensagem']`
     * (já não-acusatória por construção, `SituacaoGerencial::$descricao`,
     * 21.2) — nunca um texto novo inventado aqui.
     */
    public function toMail($notifiable): MailMessage
    {
        $p = $this->payload;

        return (new MailMessage)
            ->subject("[{$p['obra_nome']}] {$p['titulo']}")
            ->greeting("Olá, {$notifiable->first_name}!")
            ->line($p['mensagem'])
            ->line('Severidade: '.ucfirst($p['severidade']))
            ->action('Ver na plataforma', $this->link())
            ->salutation('Equipe '.config('app.name'));
    }

    /**
     * Ciclo 21, Etapa 21.4 — metadados pro ledger de entrega por canal
     * (`App\Models\SituacaoComunicacaoEntrega`), consumidos só por
     * `SituacaoLedgerMailChannel` — nunca aparecem em
     * `toArray()`/`toBroadcast()` (payload exposto ao usuário nunca
     * carrega `tenant_id` cru). `tenant_id` é obrigatório aqui pelo
     * mesmo motivo estrutural já documentado em
     * `GrdCopiasObsoletasNotification::metadadosAlertaGrd()` — o canal
     * `mail` roda dentro de um job de fila (worker sem usuário
     * autenticado), então `BelongsToTenant` não tem como auto-resolver o
     * tenant sozinho.
     */
    public function metadadosLedgerSituacao(): array
    {
        return [
            'tenant_id' => $this->payload['tenant_id'] ?? null,
            'obra_id' => $this->payload['obra_id'],
        ];
    }

    private function arrayExibicao(): array
    {
        $p = $this->payload;
        unset($p['canais'], $p['tenant_id']);

        return $p;
    }

    private function link(): string
    {
        return route('notificacoes.abrir', ['notification' => $this->id]);
    }
}
