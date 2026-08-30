<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.5 — evento append-only de "produto passa a existir
 * fisicamente, em custódia do terceiro" (fabricação). Ver docblock da
 * migration pra fundamentos completos.
 */
class ProducaoIndustrializada extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'producoes_industrializadas';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'produto_industrializado_id',
        'quantidade',
        'unidade_estoque_id',
        'ocorrido_em',
        'movimentacao_entrada_id',
        'registrado_por',
        'observacao',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
        'ocorrido_em' => 'date',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoIndustrializado::class, 'produto_industrializado_id');
    }

    public function unidadeEstoque(): BelongsTo
    {
        return $this->belongsTo(UnidadeEstoque::class);
    }

    public function movimentacaoEntrada(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_entrada_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
