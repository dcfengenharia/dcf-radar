<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.7 — correlator de rastreabilidade entre um
 * `InventarioItem` divergente e a `MovimentacaoEstoque` (Entrada/Saida
 * comuns, nunca um tipo novo no enum) que formalizou o Ajuste. Ver
 * docblock da migration `2026_09_09_000004_create_inventario_ajustes_table.php`.
 *
 * Direção sempre lida via `movimentacaoEstoque.tipo` (relação) — nunca
 * duplicada como coluna própria aqui.
 */
class InventarioAjuste extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'inventario_ajustes';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'inventario_item_id',
        'movimentacao_estoque_id',
        'quantidade',
        'justificativa',
        'aprovado_por',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventarioItem::class, 'inventario_item_id');
    }

    public function movimentacaoEstoque(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class);
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }
}
