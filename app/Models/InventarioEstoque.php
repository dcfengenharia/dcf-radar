<?php

namespace App\Models;

use App\Enums\StatusInventarioEstoque;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 20, Etapa 20.7 — sessão formal de Inventário Físico, escopada a
 * Obra + LocalEstoque. Ver docblock da migration
 * `2026_09_09_000001_create_inventarios_estoque_table.php` pra todas as
 * decisões arquiteturais (STOP-and-ask) desta etapa.
 */
class InventarioEstoque extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'inventarios_estoque';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'local_estoque_id',
        'numero',
        'status',
        'titulo',
        'contagem_cega',
        'iniciado_em',
        'iniciado_por',
        'concluido_em',
        'concluido_por',
        'cancelado_em',
        'cancelado_por',
        'motivo_cancelamento',
        'observacao',
        'created_by_id',
    ];

    protected $casts = [
        'status' => StatusInventarioEstoque::class,
        'contagem_cega' => 'boolean',
        'iniciado_em' => 'datetime',
        'concluido_em' => 'datetime',
        'cancelado_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function localEstoque(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(InventarioItem::class, 'inventario_estoque_id');
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function concluidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function estaAberto(): bool
    {
        return $this->status->estaAberto();
    }
}
