<?php

namespace App\Support\Suprimentos;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\ItemSuprimento;
use App\Models\PedidoCompra;
use App\Models\Restricao;
use App\Models\User;
use App\Models\Work;
use App\Notifications\SuprimentosPedidoAtrasadoNotification;
use App\Notifications\SuprimentosRestricaoCriadaNotification;
use App\Notifications\SuprimentosRiscoProjetadoNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Ciclo 19, Etapa 19.7 — orquestra os 3 alertas internos da cadeia formal
 * de Suprimentos (risco projetado / Pedido comercialmente atrasado /
 * Restrição automática criada). Mesmo padrão EXATO de
 * `App\Support\Grd\AlertaDistribuicaoGrd` (18.5.5/18.5.5.HARDENING):
 * idempotência via UUIDv5 DETERMINÍSTICO atribuído a `$notification->id`
 * antes de `$user->notify()` — `notifications.id` é a PRIMARY KEY, então
 * uma 2ª tentativa de gravar a MESMA chave nunca pode virar 2 linhas,
 * mesmo sob corrida (`exists()` é só atalho, não a defesa real).
 *
 * **Chave lógica intencionalmente estável através do tempo** — diferente
 * de um evento único (GRD: "revisão nasceu"), o fenômeno aqui é um ESTADO
 * que persiste por dias/semanas (Pedido continua atrasado, risco
 * continua projetado). Para satisfazer "entra em risco → alerta; continua
 * igual amanhã → NÃO novo alerta; resolve → fim do estado; volta depois →
 * novo alerta" (seção 21 do pedido) SEM precisar de uma tabela nova de
 * "último estado conhecido", a chave de cada alerta usa campos que só
 * mudam quando o fenômeno GENUINAMENTE muda:
 * - Risco projetado: `(pacote_id, necessidade, atendimento_projetado)` —
 *   evento IDÊNTICO enquanto a projeção não mudar; se um novo Pedido
 *   mudar a projeção (mesmo por 1 dia), é um novo alerta.
 * - Pedido atrasado: `(pedido_id, data_prevista_entrega)` —
 *   `data_prevista_entrega` é IMUTÁVEL desde a emissão (19.5), então esta
 *   chave nasce UMA VEZ na vida do Pedido — nunca reenviada nos dias
 *   seguintes enquanto ele continuar atrasado, e nunca reaberta depois
 *   (Pedido completo nunca "reatrasa" na mesma data de previsão).
 * - Restrição criada: `(restricao_id, aberta_em)` — `aberta_em` só muda
 *   quando a Restrição é CRIADA ou REABERTA (novo episódio, seção 11 do
 *   pedido) — nunca em atualizações de rotina (ex.: prazo_limite
 *   ajustado enquanto já aberta).
 */
class AlertaCadeiaSuprimento
{
    private const NAMESPACE_ALERTA = 'suprimentos-cadeia-alerta';

    public static function idAlerta(string $tipo, string $chaveLogica, string $userId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::NAMESPACE_ALERTA.":{$tipo}:{$chaveLogica}:{$userId}")->toString();
    }

    private static function enviarComIdempotencia(User $user, string $tipo, string $chaveLogica, \Closure $fabricaNotification): void
    {
        $id = self::idAlerta($tipo, $chaveLogica, $user->id);

        if (DB::table('notifications')->where('id', $id)->exists()) {
            return;
        }

        $notification = $fabricaNotification();
        $notification->id = $id;

        try {
            $user->notify($notification);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // Corrida concorrente — outro processo já gravou a mesma
            // chave. A PRIMARY KEY protegeu de verdade; isto é
            // idempotência, não erro (mesmo critério já usado em GRD).
        }
    }

    public function dispararRiscoProjetado(ItemSuprimento $pacote): void
    {
        // necessidade()/dataProjetadaAtendimento() leem $this->atividades e
        // $this->requisicoesCompra->pedidos — mesmo achado já corrigido em
        // ConciliacaoRecebimento::porPacote() (19.6): sem eager-load em
        // lote, o acesso indireto a `pedidos` viola preventLazyLoading.
        $pacote->loadMissing(['atividades', 'requisicoesCompra.pedidos']);

        $necessidade = $pacote->necessidade();
        $atendimento = $pacote->dataProjetadaAtendimento();

        if (! $necessidade || ! $atendimento || ! $atendimento->gt($necessidade)) {
            return;
        }

        $obra = $pacote->obra()->firstOrFail();
        $chave = "{$pacote->id}:{$necessidade->toDateString()}:{$atendimento->toDateString()}";

        foreach ($this->destinatarios($obra) as $user) {
            $this->enviarComIdempotencia($user, 'risco_projetado', $chave, fn () => new SuprimentosRiscoProjetadoNotification($pacote, $necessidade, $atendimento));
        }
    }

    public function dispararPedidoAtrasado(PedidoCompra $pedido): void
    {
        if ($pedido->diasAtrasoAtual() === null) {
            return;
        }

        $obra = $pedido->obra()->firstOrFail();
        $chave = "{$pedido->id}:{$pedido->data_prevista_entrega->toDateString()}";

        foreach ($this->destinatarios($obra) as $user) {
            $this->enviarComIdempotencia($user, 'pedido_atrasado', $chave, fn () => new SuprimentosPedidoAtrasadoNotification($pedido));
        }
    }

    public function dispararRestricaoCriada(Restricao $restricao, ItemSuprimento $pacote, Atividade $atividade): void
    {
        if (! $restricao->aberta_em) {
            return;
        }

        $obra = $pacote->obra()->firstOrFail();
        $chave = "{$restricao->id}:{$restricao->aberta_em->toIso8601String()}";

        foreach ($this->destinatarios($obra) as $user) {
            $this->enviarComIdempotencia($user, 'restricao_criada', $chave, fn () => new SuprimentosRestricaoCriadaNotification($restricao, $pacote, $atividade));
        }
    }

    /**
     * Ciclo 19, Etapa 19.7 — 3 grupos, resolvidos por permissão/perfil,
     * SEMPRE escopados pela OBRA (nunca tenant inteiro — `$obra->users()`,
     * mesmo padrão de `AlertaDistribuicaoGrd::usuariosComPermissaoNaObra()`),
     * unidos e deduplicados por `id` — mesmo usuário em 2+ grupos recebe
     * só 1 Notification por evento lógico:
     * - Gestão: perfil da obra com `slug_padrao === Papel::Admin->value`
     *   (`User::perfilNaObra()`, API semântica já existente — nunca
     *   `podeGerenciarTenant()`, que é escopo de TENANT, não da obra).
     * - Planejamento: `planejamento.requisicoes|ver`.
     * - Suprimentos: `suprimentos.mapa|ver`.
     *
     * @return Collection<int, User>
     */
    public function destinatarios(Work $obra): Collection
    {
        return $obra->users()
            ->where('users.ativo', true)
            ->get()
            ->filter(function (User $user) use ($obra) {
                return $user->perfilNaObra($obra)?->slug_padrao === Papel::Admin->value
                    || $user->temPermissaoNaObra($obra, 'planejamento.requisicoes', 'ver')
                    || $user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'ver');
            })
            ->unique('id')
            ->values();
    }
}
