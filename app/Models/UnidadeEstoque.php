<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 20, Etapa 20.1 — identidade opcional de uma unidade física
 * rastreável (bobina/lote/heat/serial). NÃO existe pra todo Material —
 * modo Quantitativo nunca precisa de uma linha aqui (a movimentação
 * aponta só material_id+local_estoque_id, unidade_estoque_id fica
 * null). Só criada pelos modos Lote/Serializado, sempre por
 * App\Actions\Estoque\RegistrarEntradaEstoque (ou
 * App\Actions\Estoque\RegistrarProducaoIndustrializada, pra um lote/
 * serial produzido por um Terceiro).
 *
 * Saldo é sempre DERIVADO (nunca coluna própria) — soma das
 * MovimentacaoEstoque que apontam pra esta unidade.
 *
 * **Ciclo 20, Etapa 20.5.CORREÇÃO — `local_estoque_id` NÃO é mais a
 * fonte de verdade de "onde esta unidade está agora"** (fecha o Achado
 * C1 da auditoria adversarial da 20.5): representa só o Local de
 * CRIAÇÃO/ORIGEM da unidade (imutável, informativo — usado só como
 * checagem de duplicidade em `RegistrarEntradaEstoque`/
 * `RegistrarProducaoIndustrializada`, nunca reescrito depois). Uma
 * bobina/lote pode ter saldo físico em MAIS DE UM Local ao mesmo tempo
 * (ex.: remessa parcial pra um Terceiro deixa parte na obra e parte no
 * Terceiro, ambas a MESMA identidade/`UnidadeEstoque`, nunca duas
 * linhas separadas) — "quanto esta unidade tem em cada Local" é sempre
 * derivado do ledger via `App\Support\Estoque\SaldoEstoque::
 * porUnidadeLocal()`/`unidadesComPresencaNoLocal()`, nunca comparando
 * este campo. `saldo()` abaixo continua correto sem alteração — já
 * soma TODAS as movimentações da unidade, em qualquer Local.
 */
class UnidadeEstoque extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'unidades_estoque';

    protected $fillable = [
        'tenant_id',
        'material_id',
        'local_estoque_id',
        'codigo_lote',
        'serial_unico',
        'identificador_logistico',
        'recebimento_pedido_id',
        'created_by_id',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function localEstoque(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class);
    }

    public function recebimentoPedido(): BelongsTo
    {
        return $this->belongsTo(RecebimentoPedido::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoEstoque::class);
    }

    public function saldo(): float
    {
        return (float) ($this->relationLoaded('movimentacoes')
            ? $this->movimentacoes->sum('quantidade')
            : $this->movimentacoes()->sum('quantidade'));
    }
}
