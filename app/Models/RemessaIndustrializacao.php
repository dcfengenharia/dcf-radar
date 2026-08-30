<?php

namespace App\Models;

use App\Enums\DirecaoRemessaIndustrializacao;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 20, Etapa 20.5 — evento FÍSICO append-only de transferência de
 * matéria-prima entre o Local próprio da obra e o Local Terceiro de
 * uma Ordem. Ver docblock da migration pra fundamentos completos.
 */
class RemessaIndustrializacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'remessas_industrializacao';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'ordem_industrializacao_id',
        'material_id',
        'unidade_estoque_id',
        'direcao',
        'quantidade',
        'ocorrido_em',
        'movimentacao_saida_id',
        'movimentacao_entrada_id',
        'registrado_por',
        'observacao',
    ];

    protected $casts = [
        'direcao' => DirecaoRemessaIndustrializacao::class,
        'quantidade' => 'decimal:3',
        'ocorrido_em' => 'date',
    ];

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(OrdemIndustrializacao::class, 'ordem_industrializacao_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function unidadeEstoque(): BelongsTo
    {
        return $this->belongsTo(UnidadeEstoque::class);
    }

    public function movimentacaoSaida(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_saida_id');
    }

    public function movimentacaoEntrada(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_entrada_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(ProdutoIndustrializadoConsumo::class, 'remessa_industrializacao_id');
    }
}
