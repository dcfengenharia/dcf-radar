<?php

namespace App\Models;

use App\Enums\OrigemNecessidadeMaterialAtividade;
use App\Enums\StatusRequisicaoCompra;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Melhoria "Posto Operacional" — fonte autoritativa da pergunta
 * "quanto desta matéria-prima/material esta Atividade necessita?".
 * NUNCA participa da soma da cadeia já existente (ItemTakeOff →
 * RequisicaoPlanejamentoItem → AlocacaoRequisicaoPacote → RC/Pedido →
 * Recebimento) — ver docblock completo da migration
 * `2026_09_16_000001_create_atividade_necessidades_material_table` pra
 * a arquitetura inteira (origens, unidade, distribuição, invariantes).
 *
 * Único ponto de escrita: `App\Actions\Estoque\
 * AtualizarNecessidadeMaterialAtividade` — este model nunca é
 * criado/editado direto por UI/Livewire.
 *
 * `material()` resolve o Material EFETIVO desta necessidade,
 * independente da origem — nunca lê a coluna `material_id` direto
 * quando `origem=take_off` (que é sempre `null` por invariante; usar
 * este acessor, nunca `$necessidade->material_id`).
 */
class AtividadeNecessidadeMaterial extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'atividade_necessidades_material';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'atividade_id',
        'origem',
        'item_take_off_id',
        'material_id',
        'unidade_medida_id',
        'quantidade_necessaria',
        'observacao',
        'created_by_id',
    ];

    protected $casts = [
        'origem' => OrigemNecessidadeMaterialAtividade::class,
        'quantidade_necessaria' => 'decimal:3',
    ];

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function itemTakeOff(): BelongsTo
    {
        return $this->belongsTo(ItemTakeOff::class);
    }

    /** Só populado quando origem=operacional — usar material() pra resolver o efetivo. */
    public function materialDireto(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    public function unidadeMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadeMedida::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaEstoque::class, 'necessidade_atividade_id');
    }

    /** Rastreabilidade Quantitativa, Etapa 1 — em quais itens de RC esta parcela já foi detalhada. */
    public function parcelasRequisicaoCompra(): HasMany
    {
        return $this->hasMany(RequisicaoCompraItemParcela::class, 'atividade_necessidade_material_id');
    }

    /** Idem, no nível de Pedido — sempre herdado da quota já detalhada na RC (nunca uma segunda fonte). */
    public function parcelasPedidoCompra(): HasMany
    {
        return $this->hasMany(PedidoCompraItemParcela::class, 'atividade_necessidade_material_id');
    }

    /**
     * Material EFETIVO desta necessidade — sempre derivado via
     * ItemTakeOff quando origem=take_off (nunca copiado/cacheado,
     * fecha o risco de divergência caso o ItemTakeOff ainda não tenha
     * seu material_id travado), ou lido diretamente quando
     * origem=operacional. Nunca `null` numa linha válida (garantido
     * pela invariante de origem).
     */
    public function material(): ?Material
    {
        return $this->origem === OrigemNecessidadeMaterialAtividade::TakeOff
            ? $this->itemTakeOff?->material
            : $this->materialDireto;
    }

    public function ehTakeOff(): bool
    {
        return $this->origem === OrigemNecessidadeMaterialAtividade::TakeOff;
    }

    /**
     * Soma das ReservaEstoque ATIVAS rotuladas com esta necessidade —
     * único ponto de leitura, reaproveitado pela query de cobertura e
     * pela UI (mesmo padrão de
     * `DestinacaoPlanejadaMaterial::quantidadeReservadaAtiva()`).
     */
    public function quantidadeReservadaAtiva(): float
    {
        return (float) $this->reservas()
            ->where('status', \App\Enums\StatusReservaEstoque::Ativa->value)
            ->sum('quantidade');
    }

    /**
     * Rastreabilidade Quantitativa, Etapa 1 — "quanto desta necessidade
     * já foi detalhado OFICIALMENTE em Requisições de Compra" — mesma
     * filosofia exata de
     * `AlocacaoRequisicaoPacote::quantidadeConsumidaOficialPorRc()`
     * (19.4.CORREÇÃO): RC `Rascunho` NUNCA conta como consumo oficial,
     * só `Emitida`/`Concluida` — múltiplos rascunhos concorrentes podem
     * cada um detalhar até o saldo cheio; só a emissão
     * (`App\Actions\Suprimentos\EmitirRequisicaoCompra`) revalida de
     * verdade e serializa.
     */
    public function quantidadeDetalhadaOficialEmRc(?string $excluirParcelaId = null): float
    {
        $query = RequisicaoCompraItemParcela::query()
            ->where('atividade_necessidade_material_id', $this->id)
            ->whereHas('requisicaoCompraItem.requisicaoCompra', fn ($q) => $q->whereIn('status', [
                StatusRequisicaoCompra::Emitida->value,
                StatusRequisicaoCompra::Concluida->value,
            ]));

        if ($excluirParcelaId) {
            $query->where('id', '!=', $excluirParcelaId);
        }

        return (float) $query->sum('quantidade');
    }

    public function saldoOficialParaDetalheRc(?string $excluirParcelaId = null): float
    {
        return round((float) $this->quantidade_necessaria - $this->quantidadeDetalhadaOficialEmRc($excluirParcelaId), 3);
    }
}
