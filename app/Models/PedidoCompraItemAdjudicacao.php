<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etapa 2.CORREÇÃO — a ponte explícita de proveniência entre um consumo
 * de Pedido (item ou parcela) e a `RequisicaoCompraAdjudicacaoItem`
 * específica de onde ele veio. Único ponto de escrita: `App\Actions\
 * Suprimentos\AtualizarProvenienciaAdjudicacaoPedidoCompra`.
 */
class PedidoCompraItemAdjudicacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'pedido_compra_item_adjudicacoes';

    protected $fillable = [
        'tenant_id',
        'pedido_compra_item_id',
        'pedido_compra_item_parcela_id',
        'requisicao_compra_adjudicacao_item_id',
        'quantidade',
        'created_by_id',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
    ];

    public function pedidoCompraItem(): BelongsTo
    {
        return $this->belongsTo(PedidoCompraItem::class);
    }

    public function parcela(): BelongsTo
    {
        return $this->belongsTo(PedidoCompraItemParcela::class, 'pedido_compra_item_parcela_id');
    }

    public function adjudicacaoItem(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompraAdjudicacaoItem::class, 'requisicao_compra_adjudicacao_item_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
