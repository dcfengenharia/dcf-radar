<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.5 — genealogia quantitativa N:N ProdutoIndustrializado
 * ↔ RemessaIndustrializacao. Append-only. Ver docblock da migration
 * pra fundamentos completos (over-consumo, movimentacao_consumo_id).
 */
class ProdutoIndustrializadoConsumo extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'produto_industrializado_consumos';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'produto_industrializado_id',
        'remessa_industrializacao_id',
        'quantidade_consumida',
        'ocorrido_em',
        'movimentacao_consumo_id',
        'registrado_por',
        'observacao',
    ];

    protected $casts = [
        'quantidade_consumida' => 'decimal:3',
        'ocorrido_em' => 'date',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoIndustrializado::class, 'produto_industrializado_id');
    }

    public function remessa(): BelongsTo
    {
        return $this->belongsTo(RemessaIndustrializacao::class, 'remessa_industrializacao_id');
    }

    public function movimentacaoConsumo(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_consumo_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
