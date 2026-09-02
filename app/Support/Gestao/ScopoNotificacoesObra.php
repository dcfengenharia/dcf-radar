<?php

namespace App\Support\Gestao;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Ciclo 21, Etapa 21.3 — Seção 12/23 do pedido: "o badge representa
 * comunicações não lidas, não quantidade de problemas ativos" e "badge
 * respeita acesso atual" (nunca conta/lista uma comunicação de uma obra
 * que o usuário já não acessa mais). Reaproveitado pelo sino
 * (`notificacoes-dropdown.blade.php`) e pela Central completa
 * (`pages/notificacoes/⚡index.blade.php`) — uma única regra, nunca
 * duplicada nos dois lugares.
 *
 * `notifications.data` continua o schema NATIVO do Laravel (`text`,
 * Seção 25 — "não adicione colunas aos modelos operacionais") — o
 * filtro usa a sintaxe `->` do Eloquent (`JSON_EXTRACT` em MySQL, que
 * funciona sobre qualquer coluna com JSON válido, independente do tipo
 * físico da coluna). Notificação SEM `obra_id` no payload (legado que
 * nunca carregou esse conceito) nunca é escondida por esta regra —
 * `obra_id` ausente é tratado como "não tem obra pra restringir",
 * nunca como "obra inacessível".
 */
class ScopoNotificacoesObra
{
    /** @return array<int, string> */
    public static function obraIdsAcessiveis(User $user): array
    {
        return $user->works()->pluck('works.id')->all();
    }

    /**
     * `$query` aceita tanto um `Builder` quanto uma `Relation`
     * (`$user->notifications()`/`unreadNotifications()` retornam
     * `MorphMany`, nunca `Builder` diretamente) — achado da
     * implementação: tipar só `Builder` quebra os dois únicos
     * chamadores reais desta classe com `TypeError` em tempo de
     * execução (nunca em tempo de análise estática).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>|Relation<TModel, *, *>  $query
     * @return Builder<TModel>|Relation<TModel, *, *>
     */
    public static function aplicar(Builder|Relation $query, User $user): Builder|Relation
    {
        $ids = self::obraIdsAcessiveis($user);

        return $query->where(function (Builder|QueryBuilder $q) use ($ids) {
            $q->whereNull('data->obra_id');

            if (! empty($ids)) {
                $q->orWhereIn('data->obra_id', $ids);
            }
        });
    }
}
