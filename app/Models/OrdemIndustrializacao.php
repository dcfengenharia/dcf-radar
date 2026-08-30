<?php

namespace App\Models;

use App\Enums\StatusOrdemIndustrializacao;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 20, Etapa 20.5 — o processo/contrato operacional com UM
 * Fornecedor pra fabricar/industrializar material enviado pela obra.
 * 1 Ordem → N Produtos previstos (Seção 3) → N Remessas → N
 * Produções/Consumos/Entregas. Ver docblock da migration pra
 * fundamentos de schema/imutabilidade.
 */
class OrdemIndustrializacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'ordens_industrializacao';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'fornecedor_id',
        'local_terceiro_id',
        'item_suprimento_id',
        'numero',
        'status',
        'observacao',
        'created_by_id',
        'emitida_em',
        'emitida_por',
        'concluida_em',
    ];

    protected $casts = [
        'status' => StatusOrdemIndustrializacao::class,
        'emitida_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class)->withTrashed();
    }

    public function localTerceiro(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_terceiro_id');
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function emissor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(ProdutoIndustrializado::class, 'ordem_industrializacao_id');
    }

    public function remessas(): HasMany
    {
        return $this->hasMany(RemessaIndustrializacao::class, 'ordem_industrializacao_id');
    }

    public function estaEmitida(): bool
    {
        return $this->status === StatusOrdemIndustrializacao::Emitida;
    }

    public function estaRascunho(): bool
    {
        return $this->status === StatusOrdemIndustrializacao::Rascunho;
    }
}
