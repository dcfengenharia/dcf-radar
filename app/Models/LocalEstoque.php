<?php

namespace App\Models;

use App\Enums\TipoLocalEstoque;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 20, Etapa 20.1 — LocalEstoque: posição física/custódia dentro da
 * obra (nunca FrenteTrabalho, que é aplicação operacional — investigação
 * 20.0, Seção 8). Obra-scoped, mesmo padrão de FrenteTrabalho/
 * EquipeResponsavel.
 *
 * Ciclo 20, Etapa 20.5 — `fornecedor_id` (nullable) representa a
 * custódia externa: só preenchido quando `tipo=Terceiro`
 * (`App\Observers\LocalEstoqueObserver` garante a coerência). Um
 * `LocalEstoque` tipo Terceiro é, em tudo mais, um LocalEstoque comum —
 * `SaldoEstoque`/`MovimentacaoEstoque` nunca precisaram de nenhuma
 * mudança (Seção 33/12: "saldo em terceiro" = `SaldoEstoque::
 * porMaterialLocal($material, $localTerceiro)`, mesma chamada de
 * sempre). `fornecedor()` usa `withTrashed()` na definição (mesmo
 * padrão de `DestinacaoPlanejadaMaterial`/`MovimentacaoEstoque`) —
 * histórico de remessas sobrevive a um Fornecedor soft-deletado depois.
 */
class LocalEstoque extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'locais_estoque';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'nome',
        'tipo',
        'ativo',
        'fornecedor_id',
    ];

    protected $casts = [
        'tipo' => TipoLocalEstoque::class,
        'ativo' => 'boolean',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class)->withTrashed();
    }

    public function unidadesEstoque(): HasMany
    {
        return $this->hasMany(UnidadeEstoque::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoEstoque::class);
    }

    public function ehTerceiro(): bool
    {
        return $this->tipo === TipoLocalEstoque::Terceiro;
    }
}
