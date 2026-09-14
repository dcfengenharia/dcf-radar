<?php

namespace App\Models;

use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Enums\StatusPedidoCompra;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rastreabilidade Quantitativa — Etapa 1. Quanto de uma
 * `RequisicaoCompraItem` atende uma `AtividadeNecessidadeMaterial`
 * específica — a ponte entre a parcela canônica (Posto Operacional) e a
 * cadeia comercial de Suprimentos. N:1 pros dois lados (uma RCItem pode
 * reunir várias parcelas — merge; a mesma parcela pode ser referenciada
 * por RCItens de RCs diferentes — split).
 *
 * Único ponto de escrita: `App\Actions\Suprimentos\
 * AtualizarDistribuicaoParcelaRequisicaoCompra` — nunca criado/editado
 * direto por UI/Livewire.
 */
class RequisicaoCompraItemParcela extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'requisicao_compra_item_parcelas';

    protected $fillable = [
        'tenant_id',
        'requisicao_compra_item_id',
        'atividade_necessidade_material_id',
        'quantidade',
        'created_by_id',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
    ];

    public function requisicaoCompraItem(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompraItem::class, 'requisicao_compra_item_id');
    }

    public function necessidade(): BelongsTo
    {
        return $this->belongsTo(AtividadeNecessidadeMaterial::class, 'atividade_necessidade_material_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * "Quota" que esta fatia de RC oferece pra Pedidos — ÚNICO ponto de
     * verdade (Seção 6 do pedido: "Pedido só pode consumir a
     * distribuição que sua própria RC lhe ofereceu"). Mesmo padrão exato
     * de `RequisicaoCompraItem::quantidadeConsumidaOficialPorPedido()`/
     * `saldoOficialParaPedido()` — só Pedido `Emitido` conta como
     * consumo oficial, nunca Rascunho (múltiplos rascunhos de Pedido
     * podem reservar até o saldo oficial cheio cada um — só a emissão
     * revalida de verdade e serializa).
     */
    public function quantidadeConsumidaOficialPorPedido(?string $excluirItemParcelaId = null): float
    {
        $query = PedidoCompraItemParcela::query()
            ->where('atividade_necessidade_material_id', $this->atividade_necessidade_material_id)
            ->whereHas('pedidoCompraItem', function ($q) {
                $q->where('requisicao_compra_item_id', $this->requisicao_compra_item_id)
                    ->whereHas('pedidoCompra', fn ($q2) => $q2->where('status', StatusPedidoCompra::Emitido->value));
            });

        if ($excluirItemParcelaId) {
            $query->where('id', '!=', $excluirItemParcelaId);
        }

        return (float) $query->sum('quantidade');
    }

    public function saldoOficialParaPedido(?string $excluirItemParcelaId = null): float
    {
        return round((float) $this->quantidade - $this->quantidadeConsumidaOficialPorPedido($excluirItemParcelaId), 3);
    }

    /** Etapa 2 (Adjudicação) — linhas de adjudicação que apontam pra esta parcela específica. */
    public function adjudicacaoItens(): HasMany
    {
        return $this->hasMany(RequisicaoCompraAdjudicacaoItem::class, 'requisicao_compra_item_parcela_id');
    }

    /** Etapa 2 — soma das adjudicações ATIVAS sobre esta parcela (excluindo uma linha, quando editando). */
    public function quantidadeAdjudicadaAtiva(?string $excluirAdjudicacaoItemId = null): float
    {
        $query = RequisicaoCompraAdjudicacaoItem::where('requisicao_compra_item_parcela_id', $this->id)
            ->whereHas('adjudicacao', fn ($q) => $q->where('status', StatusAdjudicacaoRequisicaoCompra::Ativa->value));

        if ($excluirAdjudicacaoItemId) {
            $query->where('id', '!=', $excluirAdjudicacaoItemId);
        }

        return (float) $query->sum('quantidade');
    }

    public function saldoAdjudicavel(?string $excluirAdjudicacaoItemId = null): float
    {
        return round((float) $this->quantidade - $this->quantidadeAdjudicadaAtiva($excluirAdjudicacaoItemId), 3);
    }

    /** Etapa 2 (Pedido × Adjudicação, Seção 12) — quanto desta parcela foi adjudicado ATIVAMENTE a UM fornecedor específico. */
    public function quantidadeAdjudicadaAoFornecedor(string $fornecedorId, ?string $excluirAdjudicacaoItemId = null): float
    {
        $query = RequisicaoCompraAdjudicacaoItem::where('requisicao_compra_item_parcela_id', $this->id)
            ->whereHas('adjudicacao', fn ($q) => $q->where('status', StatusAdjudicacaoRequisicaoCompra::Ativa->value)->where('fornecedor_id', $fornecedorId));

        if ($excluirAdjudicacaoItemId) {
            $query->where('id', '!=', $excluirAdjudicacaoItemId);
        }

        return (float) $query->sum('quantidade');
    }

    /**
     * Etapa 2 — quanto desta quota adjudicada a este fornecedor já foi
     * consumido oficialmente por Pedido `Emitido` DESTE MESMO fornecedor,
     * pra esta mesma necessidade/item (mesmo espírito de
     * `quantidadeConsumidaOficialPorPedido()`, agora filtrado por
     * fornecedor).
     */
    public function quantidadeConsumidaOficialPorPedidoDoFornecedor(string $fornecedorId, ?string $excluirItemParcelaId = null): float
    {
        $query = PedidoCompraItemParcela::query()
            ->where('atividade_necessidade_material_id', $this->atividade_necessidade_material_id)
            ->whereHas('pedidoCompraItem', function ($q) use ($fornecedorId) {
                $q->where('requisicao_compra_item_id', $this->requisicao_compra_item_id)
                    ->whereHas('pedidoCompra', fn ($q2) => $q2->where('status', StatusPedidoCompra::Emitido->value)->where('fornecedor_id', $fornecedorId));
            });

        if ($excluirItemParcelaId) {
            $query->where('id', '!=', $excluirItemParcelaId);
        }

        return (float) $query->sum('quantidade');
    }

    /** Etapa 2 (Pedido × Adjudicação) — o 3º teto: saldo que este fornecedor ainda tem pra pedir desta parcela. */
    public function saldoAdjudicadoParaFornecedor(string $fornecedorId, ?string $excluirItemParcelaId = null): float
    {
        return round(
            $this->quantidadeAdjudicadaAoFornecedor($fornecedorId) - $this->quantidadeConsumidaOficialPorPedidoDoFornecedor($fornecedorId, $excluirItemParcelaId),
            3
        );
    }
}
