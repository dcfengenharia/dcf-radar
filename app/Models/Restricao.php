<?php

namespace App\Models;

use App\Enums\StatusRestricao;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restricao extends Model
{
    use BelongsToTenant, HasAuthorship, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'restricoes';

    protected $fillable = [
        'tenant_id',
        'atividade_id',
        'categoria_id',
        'responsavel_id',
        'responsavel_externo',
        'created_by_id',
        'descricao',
        'bloqueante',
        'probabilidade',
        'impacto',
        'prazo_limite',
        'status',
        'aberta_em',
        'resolvida_em',
        'origem_suprimento_item_id',
        'origem_plano_acao_id',
        'origem_cadeia_suprimento_id',
    ];

    protected $casts = [
        'status' => StatusRestricao::class,
        'bloqueante' => 'boolean',
        'prazo_limite' => 'date',
        'aberta_em' => 'datetime',
        'resolvida_em' => 'datetime',
    ];

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaRestricao::class, 'categoria_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function acoes(): HasMany
    {
        return $this->hasMany(RestricaoAcao::class);
    }

    public function origemSuprimentoItem(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'origem_suprimento_item_id');
    }

    /**
     * Ciclo 11 (Etapa B): rastreabilidade da Restrição criada por decisão
     * explícita do usuário a partir de um PlanoAcao — nunca sincronização
     * de ciclo de vida (ver App\Models\PlanoAcao::transformarEmRestricoes()).
     */
    public function origemPlanoAcao(): BelongsTo
    {
        return $this->belongsTo(PlanoAcao::class, 'origem_plano_acao_id');
    }

    /**
     * Ciclo 19, Etapa 19.7 — rastreabilidade da Restrição automática
     * originada da cadeia formal de Suprimentos (RP→Pacote→RC→Pedido→
     * Recebimento), sincronizada por
     * `App\Support\SincronizarRestricaoCadeiaSuprimento`. Identidade
     * DEDICADA e estruturalmente separada de `origem_suprimento_item_id`
     * (mecanismo legado, `App\Support\SincronizarRestricaoSuprimento`) —
     * os dois nunca se tocam, nunca resolvem a Restrição um do outro.
     */
    public function origemCadeiaSuprimento(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'origem_cadeia_suprimento_id');
    }
}
