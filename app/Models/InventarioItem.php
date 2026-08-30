<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ciclo 20, Etapa 20.7 — 1 posição (Material, ou Material+UnidadeEstoque)
 * dentro de um `InventarioEstoque`. Ver docblock da migration
 * `2026_09_09_000002_create_inventario_itens_table.php`.
 *
 * `ultimaContagem()`/`diferenca()`/`temAjuste()` são a fonte canônica de
 * divergência — sempre DERIVADA (nunca uma coluna "diferença" persistida,
 * Seção 5 do pedido: "preferência: derivada"). Para listagens (evitar
 * N+1), usar `App\Support\Estoque\ConciliacaoInventario::porItens()`.
 */
class InventarioItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'inventario_itens';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'inventario_estoque_id',
        'material_id',
        'unidade_estoque_id',
        'quantidade_sistema_snapshot',
        'serial_texto_inesperado',
        'created_at',
    ];

    protected $casts = [
        'quantidade_sistema_snapshot' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function inventario(): BelongsTo
    {
        return $this->belongsTo(InventarioEstoque::class, 'inventario_estoque_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function unidadeEstoque(): BelongsTo
    {
        return $this->belongsTo(UnidadeEstoque::class);
    }

    public function contagens(): HasMany
    {
        return $this->hasMany(ContagemInventario::class, 'inventario_item_id');
    }

    public function ajuste(): HasOne
    {
        return $this->hasOne(InventarioAjuste::class, 'inventario_item_id');
    }

    public function ehSerialInesperado(): bool
    {
        return ! is_null($this->serial_texto_inesperado);
    }

    /**
     * Mesma ordem canônica de "registro" já usada em
     * `GrdDistribuicao::estado()` (created_at DESC, id DESC) — nunca
     * `contado_em` (data informada, pode ser retroativa) decide qual
     * contagem é a "adotada".
     */
    public function ultimaContagem(): ?ContagemInventario
    {
        if ($this->relationLoaded('contagens')) {
            return $this->contagens
                ->sortBy([['created_at', 'desc'], ['id', 'desc']])
                ->first();
        }

        return $this->contagens()->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function diferenca(): ?float
    {
        $ultima = $this->ultimaContagem();
        if (! $ultima) {
            return null;
        }

        return round((float) $ultima->quantidade_contada - (float) $this->quantidade_sistema_snapshot, 3);
    }

    public function temAjuste(): bool
    {
        return $this->relationLoaded('ajuste') ? ! is_null($this->ajuste) : $this->ajuste()->exists();
    }
}
