<?php

namespace App\Models;

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
}
