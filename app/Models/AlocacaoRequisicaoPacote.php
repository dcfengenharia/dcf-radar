<?php

namespace App\Models;

use App\Enums\StatusRequisicaoCompra;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 19, Etapa 19.3 — alocação quantitativa de um
 * `RequisicaoPlanejamentoItem` a um `ItemSuprimento` (Pacote de Compra).
 * A origem canônica de descrição/unidade/lista/documento continua sendo
 * `requisicaoItem->itemTakeOff` (ao vivo, congelada em snapshot só na
 * emissão da RP) — esta linha carrega só `quantidade_alocada`, nunca uma
 * segunda cópia de dado de negócio.
 */
class AlocacaoRequisicaoPacote extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'alocacoes_requisicao_pacote';

    protected $fillable = [
        'tenant_id',
        'requisicao_planejamento_item_id',
        'item_suprimento_id',
        'quantidade_alocada',
        'created_by_id',
    ];

    protected $casts = [
        'quantidade_alocada' => 'decimal:3',
    ];

    public function requisicaoItem(): BelongsTo
    {
        return $this->belongsTo(RequisicaoPlanejamentoItem::class, 'requisicao_planejamento_item_id');
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Ciclo 19, Etapa 19.4 — consumo desta alocação por itens de RC. */
    public function itensRequisicaoCompra(): HasMany
    {
        return $this->hasMany(RequisicaoCompraItem::class, 'alocacao_requisicao_pacote_id');
    }

    /**
     * Ciclo 19, Etapa 19.4.CORREÇÃO — ÚNICA fonte de verdade do "consumo
     * OFICIAL" desta alocação por Requisições de Compra. Mesma filosofia
     * já usada por `RequisicaoPlanejamentoItem`/`AtualizarRascunhoRequisicaoPlanejamento::
     * validarSaldo()`: RC Rascunho NUNCA conta — só RC `Emitida`/`Concluida`
     * representam compromisso formal. Centralizado aqui (em vez de
     * duplicado em cada Action/UI que precisa da mesma soma) por ser
     * usado em 4+ pontos: validação de novo item de RC rascunho, os 2
     * guards de `AlocarRequisicaoAoPacote` (reduzir/remover alocação), e
     * a seleção de alocações com saldo na UI.
     */
    public function quantidadeConsumidaOficialPorRc(?string $excluirItemId = null): float
    {
        $query = RequisicaoCompraItem::query()
            ->where('alocacao_requisicao_pacote_id', $this->id)
            ->whereHas('requisicaoCompra', fn ($q) => $q->whereIn('status', [
                StatusRequisicaoCompra::Emitida->value,
                StatusRequisicaoCompra::Concluida->value,
            ]));

        if ($excluirItemId) {
            $query->where('id', '!=', $excluirItemId);
        }

        return (float) $query->sum('quantidade');
    }

    public function saldoOficialParaRc(?string $excluirItemId = null): float
    {
        return round((float) $this->quantidade_alocada - $this->quantidadeConsumidaOficialPorRc($excluirItemId), 3);
    }
}
