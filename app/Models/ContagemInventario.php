<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.7 — evento append-only de contagem/recontagem de UM
 * `InventarioItem`. Ver docblock da migration
 * `2026_09_09_000003_create_contagens_inventario_table.php`.
 */
class ContagemInventario extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'contagens_inventario';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'inventario_item_id',
        'quantidade_contada',
        'contado_em',
        'contador_id',
        'observacao',
    ];

    protected $casts = [
        'quantidade_contada' => 'decimal:3',
        'contado_em' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventarioItem::class, 'inventario_item_id');
    }

    public function contador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contador_id');
    }
}
