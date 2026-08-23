<?php

namespace App\Models;

use App\Enums\StatusGrd;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 18, Etapa 18.5.1 — cabeçalho de GRD (fato de distribuição física
 * de revisões de Documento de Engenharia). Rascunho/Emitida — mesmo
 * espírito de dupla trava de Report::rascunho/emitido, mas SEM camada de
 * permissão combinada nesta fase (só domínio; autorização fica com o
 * chamador — ver App\Actions\Engenharia\EmitirGrd / RegistrarRecolhimento).
 *
 * Distribuição física é um domínio DELIBERADAMENTE independente da
 * liberação para construção (Etapa 18.3/18.4) e da prontidão operacional
 * (Atividade::scopeProntas()) — nada aqui altera nenhum dos dois.
 */
class Grd extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'grds';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'numero',
        'status',
        'emitida_em',
        'emitida_por',
        'criado_por',
        'observacao',
    ];

    protected $casts = [
        'numero' => 'integer',
        'status' => StatusGrd::class,
        'emitida_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function emitidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(GrdItem::class);
    }

    public function destinatarios(): HasMany
    {
        return $this->hasMany(GrdDestinatario::class);
    }

    /**
     * Etapa 18.5.2 — relação de LEITURA pura pra UI (contagem de
     * distribuições na listagem) — nunca usada por nenhuma Action de
     * mutação, que continuam resolvendo GrdDistribuicao diretamente por
     * `grd_item_id`/`grd_destinatario_id`.
     */
    public function distribuicoes(): HasManyThrough
    {
        return $this->hasManyThrough(GrdDistribuicao::class, GrdItem::class, 'grd_id', 'grd_item_id');
    }

    public function estaRascunho(): bool
    {
        return $this->status === StatusGrd::Rascunho;
    }

    public function estaEmitida(): bool
    {
        return $this->status === StatusGrd::Emitida;
    }
}
