<?php

namespace App\Models;

use App\Enums\StatusPedidoCompra;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Etapa 2 — composição quantitativa de uma `RequisicaoCompraAdjudicacao`
 * (Seção 5/6 do pedido). `requisicao_compra_item_parcela_id` nulo
 * significa "sem detalhamento de Atividade" (só permitido quando o
 * próprio `RequisicaoCompraItem` nunca foi detalhado por Atividade —
 * ver `App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra::
 * garantirGranularidadeCoerente()`); não-nulo aponta pra uma parcela
 * (Atividade) específica.
 *
 * Único ponto de escrita: `App\Actions\Suprimentos\
 * AtualizarAdjudicacaoRequisicaoCompra`.
 */
class RequisicaoCompraAdjudicacaoItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'requisicao_compra_adjudicacao_itens';

    protected $fillable = [
        'tenant_id',
        'requisicao_compra_adjudicacao_id',
        'requisicao_compra_item_id',
        'requisicao_compra_item_parcela_id',
        'quantidade',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
    ];

    public function adjudicacao(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompraAdjudicacao::class, 'requisicao_compra_adjudicacao_id');
    }

    public function requisicaoCompraItem(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompraItem::class);
    }

    public function parcela(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompraItemParcela::class, 'requisicao_compra_item_parcela_id');
    }

    /**
     * Etapa 2.CORREÇÃO — a proveniência explícita: quais consumos de
     * Pedido foram atribuídos EXATAMENTE a esta linha de adjudicação
     * (nunca inferido/agregado por fornecedor).
     */
    public function consumosPedido(): HasMany
    {
        return $this->hasMany(PedidoCompraItemAdjudicacao::class, 'requisicao_compra_adjudicacao_item_id');
    }

    /**
     * Soma OFICIAL (só Pedido `Emitido`, nunca Rascunho — mesma
     * filosofia de sempre) já atribuída explicitamente a ESTA linha de
     * adjudicação, via a ponte de proveniência. Esta é a fonte de
     * verdade PRECISA pra "quanto desta decisão específica já foi
     * consumido" — nunca mais a soma agregada por fornecedor (que não
     * distingue entre duas adjudicações Ativas/Canceladas do MESMO
     * fornecedor sobre o MESMO alvo).
     */
    public function quantidadeConsumidaViaBridge(?string $excluirConsumoId = null): float
    {
        $query = PedidoCompraItemAdjudicacao::where('requisicao_compra_adjudicacao_item_id', $this->id)
            ->whereHas('pedidoCompraItem.pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value));

        if ($excluirConsumoId) {
            $query->where('id', '!=', $excluirConsumoId);
        }

        return (float) $query->sum('quantidade');
    }

    public function saldoNaoConsumidoViaBridge(?string $excluirConsumoId = null): float
    {
        return round((float) $this->quantidade - $this->quantidadeConsumidaViaBridge($excluirConsumoId), 3);
    }
}
