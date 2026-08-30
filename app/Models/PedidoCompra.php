<?php

namespace App\Models;

use App\Enums\SituacaoEntregaPedido;
use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRecebimentoItem;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 19, Etapa 19.5 — Pedido/Ordem de Compra: compromisso comercial
 * formal derivado de UMA Requisição de Compra (nunca cruza RCs). Campos
 * de "Contrato" (`numero_contrato`/`data_contrato`) vivem aqui mesmo,
 * opcionais — sem entidade própria (decisão do usuário: sem requisito
 * adicional além de número/data/fornecedor, que o Pedido já cobre).
 */
class PedidoCompra extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'pedidos_compra';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'requisicao_compra_id',
        'fornecedor_id',
        'fornecedor_nome_snapshot',
        'fornecedor_cnpj_snapshot',
        'numero',
        'status',
        'data_prevista_entrega',
        'numero_contrato',
        'data_contrato',
        'observacao',
        'created_by_id',
        'emitido_em',
        'emitido_por',
    ];

    protected $casts = [
        'numero' => 'integer',
        'status' => StatusPedidoCompra::class,
        'data_prevista_entrega' => 'date',
        'data_contrato' => 'date',
        'emitido_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function requisicaoCompra(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompra::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function emitidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitido_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoCompraItem::class);
    }

    public function estaRascunho(): bool
    {
        return $this->status === StatusPedidoCompra::Rascunho;
    }

    public function estaEmitido(): bool
    {
        return $this->status === StatusPedidoCompra::Emitido;
    }

    /**
     * Situação de ENTREGA FÍSICA (Ciclo 19, Etapa 19.6) — DERIVADA, nunca
     * confundida com `status` (documental/comercial, Rascunho|Emitido).
     * Sem itens (nunca deveria acontecer num Pedido Emitido, mas Rascunho
     * pode não ter nenhum ainda) -> NaoIniciada.
     */
    public function situacaoEntrega(): SituacaoEntregaPedido
    {
        $itens = $this->itensParaCalculo();

        if ($itens->isEmpty()) {
            return SituacaoEntregaPedido::NaoIniciada;
        }

        $statusPorItem = $itens->map(fn (PedidoCompraItem $item) => $item->statusRecebimento());

        if ($statusPorItem->every(fn (StatusRecebimentoItem $s) => $s === StatusRecebimentoItem::NaoRecebido)) {
            return SituacaoEntregaPedido::NaoIniciada;
        }

        if ($statusPorItem->every(fn (StatusRecebimentoItem $s) => $s === StatusRecebimentoItem::Recebido)) {
            return SituacaoEntregaPedido::Completa;
        }

        return SituacaoEntregaPedido::Parcial;
    }

    /**
     * `data_entrega_completa` = MAX, entre todos os itens, do evento de
     * recebimento que efetivamente completou CADA item
     * (`PedidoCompraItem::dataConclusaoRecebimento()`) — nunca sobrescreve
     * `data_prevista_entrega`. `null` enquanto `situacaoEntrega() !==
     * Completa`.
     */
    public function dataEntregaCompleta(): ?Carbon
    {
        if ($this->situacaoEntrega() !== SituacaoEntregaPedido::Completa) {
            return null;
        }

        return $this->itensParaCalculo()
            ->map(fn (PedidoCompraItem $item) => $item->dataConclusaoRecebimento())
            ->filter()
            ->max();
    }

    /**
     * Atraso ATUAL: hoje já passou da previsão e ainda há saldo em aberto
     * (entrega não completa). `null` sem previsão, sem atraso, ou já
     * completo.
     */
    public function diasAtrasoAtual(): ?int
    {
        if (! $this->data_prevista_entrega || $this->situacaoEntrega() === SituacaoEntregaPedido::Completa) {
            return null;
        }

        $hoje = Carbon::today();
        if ($hoje->lte($this->data_prevista_entrega)) {
            return null;
        }

        return (int) $this->data_prevista_entrega->diffInDays($hoje);
    }

    /**
     * Atraso HISTÓRICO/FINAL: entrega já completa, mas terminou depois da
     * previsão. `null` sem previsão, sem conclusão, ou concluído no prazo/
     * antecipado.
     */
    public function diasAtrasoFinal(): ?int
    {
        $conclusao = $this->dataEntregaCompleta();
        if (! $this->data_prevista_entrega || ! $conclusao) {
            return null;
        }

        if ($conclusao->lte($this->data_prevista_entrega)) {
            return null;
        }

        return (int) $this->data_prevista_entrega->diffInDays($conclusao);
    }

    private function itensParaCalculo(): \Illuminate\Support\Collection
    {
        return $this->relationLoaded('itens') ? $this->itens : $this->itens()->get();
    }
}
