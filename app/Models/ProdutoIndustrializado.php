<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 20, Etapa 20.5 — produto previsto/fabricado dentro de uma
 * OrdemIndustrializacao. `material_id` obrigatório (decisão do
 * usuário, Opção B — família/tipo de peça = Material mestre, unidade
 * física = UnidadeEstoque). `documento_engenharia_revisao_id`
 * congela a revisão exata usada na fabricação (Seção 4/31). Ver
 * docblock da migration.
 */
class ProdutoIndustrializado extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'produtos_industrializados';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'ordem_industrializacao_id',
        'material_id',
        'documento_engenharia_revisao_id',
        'quantidade_prevista',
        'observacao',
        'created_by_id',
    ];

    protected $casts = [
        'quantidade_prevista' => 'decimal:3',
    ];

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(OrdemIndustrializacao::class, 'ordem_industrializacao_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function documentoRevisao(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenhariaRevisao::class, 'documento_engenharia_revisao_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(ProdutoIndustrializadoConsumo::class, 'produto_industrializado_id');
    }

    public function producoes(): HasMany
    {
        return $this->hasMany(ProducaoIndustrializada::class, 'produto_industrializado_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaProdutoIndustrializado::class, 'produto_industrializado_id');
    }
}
