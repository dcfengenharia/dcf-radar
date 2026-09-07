<?php

namespace App\Models;

use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoLicaoAprendida;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 23, Etapa 23.1 — núcleo da Memória Operacional Corporativa.
 * Nasce numa obra (`obra_origem_id`), nunca cruza tenant. Workflow e
 * imutabilidade pós-publicação enforçados por
 * `App\Observers\LicaoAprendidaObserver` + as Actions dedicadas em
 * `App\Actions\LicoesAprendidas\*` — nunca um `update()` genérico de
 * formulário na coluna `status`.
 */
class LicaoAprendida extends Model
{
    use BelongsToTenant, HasAuthorship, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'licoes_aprendidas';

    protected $fillable = [
        'tenant_id',
        'obra_origem_id',
        'disciplina_id',
        'titulo',
        'situacao_observada',
        'causa',
        'impacto',
        'acao_adotada',
        'resultado',
        'recomendacao_futura',
        'tipo',
        'criticidade',
        'area_funcional',
        'status',
        'data_ocorrencia',
        'data_ocorrencia_fim',
        'observacoes_internas',
        'created_by_id',
        'enviado_validacao_em',
        'publicado_por_id',
        'publicado_em',
        'arquivado_por_id',
        'arquivado_em',
    ];

    protected $casts = [
        'tipo' => TipoLicaoAprendida::class,
        'criticidade' => CriticidadeLicao::class,
        'area_funcional' => AreaFuncionalLicao::class,
        'status' => StatusLicaoAprendida::class,
        'data_ocorrencia' => 'date',
        'data_ocorrencia_fim' => 'date',
        'enviado_validacao_em' => 'datetime',
        'publicado_em' => 'datetime',
        'arquivado_em' => 'datetime',
    ];

    public function obraOrigem(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_origem_id');
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function publicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por_id');
    }

    public function arquivadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arquivado_por_id');
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(LicaoAprendidaVinculo::class);
    }

    public function vinculoOrigem(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LicaoAprendidaVinculo::class)->where('e_origem', true);
    }

    public function vinculosComplementares(): HasMany
    {
        return $this->hasMany(LicaoAprendidaVinculo::class)->where('e_origem', false);
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(LicaoAprendidaEvidencia::class);
    }

    public function estaEditavel(): bool
    {
        return $this->status->estaEditavel();
    }

    public function estaImutavel(): bool
    {
        return $this->status->estaImutavel();
    }

    public function estaPublicada(): bool
    {
        return $this->status === StatusLicaoAprendida::Publicada;
    }
}
