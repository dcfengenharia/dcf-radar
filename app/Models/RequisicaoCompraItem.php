<?php

namespace App\Models;

use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Enums\StatusPedidoCompra;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 19, Etapa 19.4 — linha de consumo de uma RC sobre uma
 * `AlocacaoRequisicaoPacote` (N:N + quantidade, nunca consumo total
 * assumido). Origem canônica de descrição/unidade/lista/documento
 * continua sendo `alocacao->requisicaoItem->itemTakeOff` (ao vivo) —
 * `*_snapshot` só existem pra exibição histórica de uma RC já Emitida.
 */
class RequisicaoCompraItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'requisicao_compra_itens';

    protected $fillable = [
        'tenant_id',
        'requisicao_compra_id',
        'alocacao_requisicao_pacote_id',
        'quantidade',
        'codigo_item_snapshot',
        'descricao_snapshot',
        'unidade_snapshot',
        'lista_codigo_snapshot',
        'tipo_lista_snapshot',
        'documento_codigo_snapshot',
        'revisao_snapshot',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
    ];

    public function requisicaoCompra(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompra::class);
    }

    public function alocacao(): BelongsTo
    {
        return $this->belongsTo(AlocacaoRequisicaoPacote::class, 'alocacao_requisicao_pacote_id');
    }

    /** Ciclo 19, Etapa 19.5 — consumo deste item de RC por itens de Pedido. */
    public function itensPedido(): HasMany
    {
        return $this->hasMany(PedidoCompraItem::class, 'requisicao_compra_item_id');
    }

    /** Rastreabilidade Quantitativa, Etapa 1 — distribuição deste item por Atividade (parcelas de necessidade). */
    public function parcelas(): HasMany
    {
        return $this->hasMany(RequisicaoCompraItemParcela::class, 'requisicao_compra_item_id');
    }

    /**
     * Ciclo 19, Etapa 19.5.CORREÇÃO-preventiva — mesma filosofia já
     * estabelecida em `AlocacaoRequisicaoPacote::
     * quantidadeConsumidaOficialPorRc()` (19.4.CORREÇÃO): Pedido
     * Rascunho NUNCA conta como consumo oficial, só `Emitido`.
     * Centralizado aqui por ser usado em 3+ pontos (validação de novo
     * item de Pedido rascunho, revalidação na emissão, UI).
     */
    public function quantidadeConsumidaOficialPorPedido(?string $excluirItemId = null): float
    {
        $query = PedidoCompraItem::query()
            ->where('requisicao_compra_item_id', $this->id)
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value));

        if ($excluirItemId) {
            $query->where('id', '!=', $excluirItemId);
        }

        return (float) $query->sum('quantidade_pedida');
    }

    public function saldoOficialParaPedido(?string $excluirItemId = null): float
    {
        return round((float) $this->quantidade - $this->quantidadeConsumidaOficialPorPedido($excluirItemId), 3);
    }

    /** Etapa 2 (Adjudicação) — todas as linhas de adjudicação que apontam pra este item (com ou sem parcela). */
    public function adjudicacaoItens(): HasMany
    {
        return $this->hasMany(RequisicaoCompraAdjudicacaoItem::class, 'requisicao_compra_item_id');
    }

    /**
     * Etapa 2 — soma das adjudicações ATIVAS "sem detalhamento de
     * Atividade" (`requisicao_compra_item_parcela_id` nulo) sobre este
     * item. Só relevante quando este item NUNCA foi detalhado por
     * Atividade (Etapa 1) — a granularidade coerente é garantida em
     * `App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra::
     * garantirGranularidadeCoerente()`, nunca aqui.
     */
    public function quantidadeAdjudicadaAtivaSemParcela(?string $excluirAdjudicacaoItemId = null): float
    {
        $query = RequisicaoCompraAdjudicacaoItem::where('requisicao_compra_item_id', $this->id)
            ->whereNull('requisicao_compra_item_parcela_id')
            ->whereHas('adjudicacao', fn ($q) => $q->where('status', StatusAdjudicacaoRequisicaoCompra::Ativa->value));

        if ($excluirAdjudicacaoItemId) {
            $query->where('id', '!=', $excluirAdjudicacaoItemId);
        }

        return (float) $query->sum('quantidade');
    }

    public function saldoAdjudicavelSemParcela(?string $excluirAdjudicacaoItemId = null): float
    {
        return round((float) $this->quantidade - $this->quantidadeAdjudicadaAtivaSemParcela($excluirAdjudicacaoItemId), 3);
    }

    /**
     * Etapa 2 (Pedido × Adjudicação, Seção 12) — quanto foi adjudicado
     * ATIVAMENTE a UM fornecedor específico, sem detalhamento de
     * Atividade.
     */
    public function quantidadeAdjudicadaAoFornecedorSemParcela(string $fornecedorId, ?string $excluirAdjudicacaoItemId = null): float
    {
        $query = RequisicaoCompraAdjudicacaoItem::where('requisicao_compra_item_id', $this->id)
            ->whereNull('requisicao_compra_item_parcela_id')
            ->whereHas('adjudicacao', fn ($q) => $q->where('status', StatusAdjudicacaoRequisicaoCompra::Ativa->value)->where('fornecedor_id', $fornecedorId));

        if ($excluirAdjudicacaoItemId) {
            $query->where('id', '!=', $excluirAdjudicacaoItemId);
        }

        return (float) $query->sum('quantidade');
    }

    /**
     * Etapa 2 — quanto da quota adjudicada a este fornecedor (sem
     * parcela) já foi consumido oficialmente por Pedido `Emitido` DESTE
     * MESMO fornecedor sobre este item. Só faz sentido quando o item não
     * tem parcela — `PedidoCompraItemParcela` só existe quando o item
     * TEM parcela (`garantirParcelaExisteNaRc()`), mutuamente exclusivo.
     */
    public function quantidadeConsumidaOficialPorPedidoDoFornecedor(string $fornecedorId, ?string $excluirItemId = null): float
    {
        $query = PedidoCompraItem::where('requisicao_compra_item_id', $this->id)
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value)->where('fornecedor_id', $fornecedorId));

        if ($excluirItemId) {
            $query->where('id', '!=', $excluirItemId);
        }

        return (float) $query->sum('quantidade_pedida');
    }

    /** Etapa 2 (Pedido × Adjudicação) — o 3º teto: saldo que este fornecedor ainda tem pra pedir deste item (sem parcela). */
    public function saldoAdjudicadoParaFornecedor(string $fornecedorId, ?string $excluirItemId = null): float
    {
        return round(
            $this->quantidadeAdjudicadaAoFornecedorSemParcela($fornecedorId) - $this->quantidadeConsumidaOficialPorPedidoDoFornecedor($fornecedorId, $excluirItemId),
            3
        );
    }
}
