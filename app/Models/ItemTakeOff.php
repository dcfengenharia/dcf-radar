<?php

namespace App\Models;

use App\Enums\OrigemItemTakeOff;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — item de Take Off (LM/LI). Pertence à
 * LISTA (`lista_engenharia_id`), nunca diretamente à Revisão — a
 * revisão continua acessível via `item->lista->revisao`, sem duplicar a
 * FK aqui (evita 2 fontes da mesma informação podendo divergir).
 *
 * `tipo` NÃO existe mais nesta tabela (era duplicado da 19.1 original,
 * sem nada garantindo consistência com a lista) — sempre lido via
 * `$item->lista->tipo`.
 *
 * Ciclo 20, Etapa 20.1/20.1.CORREÇÃO — `material_id` (nullable) associa
 * opcionalmente este item ao catálogo mestre de Material/SKU. ItemTakeOff
 * continua sendo só a ocorrência documental de necessidade (nunca o
 * material físico em si) — duas LMs diferentes apontando pro MESMO
 * Material consolidam saldo de estoque via MovimentacaoEstoque.material_id,
 * sem que este model precise saber disso.
 *
 * **API oficial de escrita de material_id é
 * App\Actions\Estoque\AssociarMaterialAoItemTakeOff — NUNCA escrever
 * material_id diretamente pela UI.** A regra "pode alterar?" vive
 * inteiramente em App\Support\Estoque\PoliticaAssociacaoMaterial (fonte
 * única, reaproveitada pela Action e por App\Observers\ItemTakeOffObserver)
 * — primeira associação é sempre permitida; uma TROCA só é bloqueada
 * depois que existir um Pedido de Compra Emitido ou uma entrada em
 * estoque na cadeia deste item (20.1.CORREÇÃO — o corte antigo, "só
 * depois de usado em estoque", foi provado insuficiente pela auditoria
 * adversarial da 20.1).
 */
class ItemTakeOff extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'itens_take_off';

    protected $fillable = [
        'tenant_id',
        'lista_engenharia_id',
        'codigo',
        'descricao',
        'unidade_medida_id',
        'familia_material_id',
        'material_id',
        'disciplina_id',
        'quantidade',
        'observacoes',
        'origem',
        'created_by_id',
    ];

    protected $casts = [
        'origem' => OrigemItemTakeOff::class,
        'quantidade' => 'decimal:3',
    ];

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaEngenharia::class, 'lista_engenharia_id');
    }

    public function unidadeMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadeMedida::class);
    }

    public function familiaMaterial(): BelongsTo
    {
        return $this->belongsTo(FamiliaMaterial::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Melhoria "Posto Operacional" — distribuição deste item às Atividades que o necessitam (ver App\Support\Estoque\ConciliacaoNecessidadeAtividade). */
    public function necessidadesAtividade(): HasMany
    {
        return $this->hasMany(AtividadeNecessidadeMaterial::class);
    }
}
