<?php

namespace App\Models;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\OrigemCadastroMaterial;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 20, Etapa 20.1 — catálogo mestre de Material/SKU (decisão do
 * usuário, investigação 20.0). Tenant-scoped, mesmo escopo de
 * FamiliaMaterial/UnidadeMedida — o mesmo SKU pode ser usado em
 * múltiplas obras do tenant.
 *
 * ItemTakeOff NUNCA é o Material — é ocorrência documental de
 * necessidade (LM-001, LM-002...) que pode, opcionalmente, apontar pra
 * este catálogo mestre via item_take_off.material_id. Duas LMs
 * diferentes apontando pro mesmo Material consolidam saldo de estoque
 * automaticamente (via MovimentacaoEstoque.material_id), sem que o Take
 * Off/histórico documental seja alterado.
 */
class Material extends Model
{
    use BelongsToTenant, HasAuthorship, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'materiais';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'descricao',
        'unidade_medida_id',
        'familia_material_id',
        'modo_rastreabilidade',
        'origem_cadastro',
        'ativo',
        'created_by_id',
    ];

    protected $casts = [
        'modo_rastreabilidade' => ModoRastreabilidadeMaterial::class,
        'origem_cadastro' => OrigemCadastroMaterial::class,
        'ativo' => 'boolean',
    ];

    public function unidadeMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadeMedida::class);
    }

    public function familiaMaterial(): BelongsTo
    {
        return $this->belongsTo(FamiliaMaterial::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function itensTakeOff(): HasMany
    {
        return $this->hasMany(ItemTakeOff::class);
    }

    public function unidadesEstoque(): HasMany
    {
        return $this->hasMany(UnidadeEstoque::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoEstoque::class);
    }
}
