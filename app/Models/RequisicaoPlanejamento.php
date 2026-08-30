<?php

namespace App\Models;

use App\Enums\StatusRequisicaoPlanejamento;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 19, Etapa 19.2 — cabeçalho da Requisição do Planejamento (RP):
 * "o Planejamento formalizou que estas quantidades do Take Off devem
 * seguir para Suprimentos." Nunca representa compra/cotação/pedido/
 * entrega — isso é 19.3+ (Pacote de Compra/RequisicaoCompra), fora de
 * escopo aqui. Schema espelha `Grd` (18.5.1) — mesma dupla trava
 * Rascunho/Emitida, mesma numeração via lock (ver
 * App\Actions\Suprimentos\EmitirRequisicaoPlanejamento).
 */
class RequisicaoPlanejamento extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'requisicoes_planejamento';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'numero',
        'status',
        'emitida_em',
        'emitida_por',
        'created_by_id',
        'observacao',
    ];

    protected $casts = [
        'numero' => 'integer',
        'status' => StatusRequisicaoPlanejamento::class,
        'emitida_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function emitidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(RequisicaoPlanejamentoItem::class);
    }

    public function estaRascunho(): bool
    {
        return $this->status === StatusRequisicaoPlanejamento::Rascunho;
    }

    public function estaEmitida(): bool
    {
        return $this->status === StatusRequisicaoPlanejamento::Emitida;
    }
}
