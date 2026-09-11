<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rastreabilidade Quantitativa — Etapa 1. Quanto de um `PedidoCompraItem`
 * atende uma `AtividadeNecessidadeMaterial` específica — nunca inferido
 * por proporcionalidade a partir da RCItem mãe (Revisão Arquitetural 2,
 * Seção 2, decisão fechada): sempre uma escolha explícita, validada
 * contra a "quota" que a `RequisicaoCompraItemParcela` correspondente
 * (mesmo par RCItem+necessidade da RCItem mãe deste PedidoItem)
 * realmente oferece.
 *
 * Único ponto de escrita: `App\Actions\Suprimentos\
 * AtualizarDistribuicaoParcelaPedidoCompra`.
 */
class PedidoCompraItemParcela extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'pedido_compra_item_parcelas';

    protected $fillable = [
        'tenant_id',
        'pedido_compra_item_id',
        'atividade_necessidade_material_id',
        'quantidade',
        'created_by_id',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
    ];

    public function pedidoCompraItem(): BelongsTo
    {
        return $this->belongsTo(PedidoCompraItem::class, 'pedido_compra_item_id');
    }

    public function necessidade(): BelongsTo
    {
        return $this->belongsTo(AtividadeNecessidadeMaterial::class, 'atividade_necessidade_material_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
