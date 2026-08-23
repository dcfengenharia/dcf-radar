<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 18, Etapa 18.3 — evento append-only de liberação/revogação de uma
 * DocumentoEngenhariaRevisao para construção. Nunca atualizado, só
 * criado — cada mudança de estado (liberar ou revogar) é uma linha nova.
 * Ver docblock da migration pra justificativa completa da decisão.
 */
class RevisaoLiberacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'revisao_liberacoes';

    protected $fillable = [
        'tenant_id',
        'revisao_id',
        'liberada_para_construcao',
        'alterado_por',
        'ocorrido_em',
        'observacao',
    ];

    protected $casts = [
        'liberada_para_construcao' => 'boolean',
        'ocorrido_em' => 'datetime',
    ];

    public function revisao(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenhariaRevisao::class, 'revisao_id');
    }

    public function alteradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alterado_por');
    }

    /**
     * Ciclo 18, Etapa 18.4.CORREÇÃO — filtra a query pra conter APENAS o
     * ÚLTIMO evento de cada revisão (mesmo critério de
     * `DocumentoEngenhariaRevisao::ultimaLiberacao()`, que usa `ofMany
     * (created_at MAX, id MAX)` — nunca `ocorrido_em`). Via NOT EXISTS,
     * mesmo mecanismo de `DocumentoEngenhariaRevisao::scopeVigentes()`,
     * necessário pra expressar "estado atual de liberação" dentro de um
     * `whereHas` SQL-only (o `ofMany` de `ultimaLiberacao()` não é
     * utilizável dentro de `whereHas` pelo mesmo motivo já documentado
     * lá: vira um EXISTS que ignora a ordenação).
     *
     * ATENÇÃO: deve permanecer idêntico ao critério de
     * `ultimaLiberacao()` (created_at DESC, id DESC).
     */
    public function scopeUltimoEvento(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('revisao_liberacoes as mais_novo')
                ->whereColumn('mais_novo.revisao_id', 'revisao_liberacoes.revisao_id')
                ->whereRaw(
                    '(mais_novo.created_at, mais_novo.id) > (revisao_liberacoes.created_at, revisao_liberacoes.id)'
                );
        });
    }
}
