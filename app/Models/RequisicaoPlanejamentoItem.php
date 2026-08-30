<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 19, Etapa 19.2 — linha de uma RP. A origem canônica de
 * descrição/unidade/família/lista/documento/revisão é sempre
 * `itemTakeOff` (ao vivo) — os campos `*_snapshot` só existem pra
 * exibição histórica de uma RP já Emitida (congelados por
 * App\Actions\Suprimentos\EmitirRequisicaoPlanejamento), nunca uma
 * segunda fonte de verdade pra conciliação.
 */
class RequisicaoPlanejamentoItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'requisicao_planejamento_itens';

    protected $fillable = [
        'tenant_id',
        'requisicao_planejamento_id',
        'item_take_off_id',
        'quantidade_requisitada',
        'codigo_item_snapshot',
        'descricao_snapshot',
        'unidade_snapshot',
        'lista_codigo_snapshot',
        'tipo_lista_snapshot',
        'documento_codigo_snapshot',
        'revisao_snapshot',
    ];

    protected $casts = [
        'quantidade_requisitada' => 'decimal:3',
    ];

    public function requisicao(): BelongsTo
    {
        return $this->belongsTo(RequisicaoPlanejamento::class, 'requisicao_planejamento_id');
    }

    public function itemTakeOff(): BelongsTo
    {
        return $this->belongsTo(ItemTakeOff::class, 'item_take_off_id');
    }

    /** Ciclo 19, Etapa 19.3 — alocações deste item a Pacotes de Compra. */
    public function alocacoes(): HasMany
    {
        return $this->hasMany(AlocacaoRequisicaoPacote::class, 'requisicao_planejamento_item_id');
    }
}
