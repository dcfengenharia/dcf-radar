<?php

namespace App\Models;

use App\Enums\StatusCandidatoLicaoAprendida;
use App\Enums\TipoCandidatoLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 23, Etapa 23.3 — "há evidência de que este fato merece reflexão
 * para projetos futuros." Nunca uma `LicaoAprendida` — snapshot imutável
 * de um fato objetivo (`dados_snapshot`), nunca reescrito depois de
 * criado (Seção 16: mudança de estado atual não apaga/reescreve).
 *
 * Workflow enxuto e terminal (`App\Enums\StatusCandidatoLicaoAprendida`):
 * Pendente → Convertido OU Pendente → Descartado — nunca deletado, nunca
 * volta a Pendente. Mutação sempre via `App\Actions\LicoesAprendidas\
 * ConverterCandidatoEmLicao`/`DescartarCandidatoLicaoAprendida`, nunca
 * `update()` genérico de formulário.
 */
class CandidatoLicaoAprendida extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'licao_aprendida_candidatos';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'tipo',
        'chave_logica',
        'status',
        'entidade_tipo',
        'entidade_id',
        'titulo',
        'descricao',
        'dados_snapshot',
        'gerado_em',
        'descartado_por',
        'descartado_em',
        'motivo_descarte',
        'convertido_em',
        'licao_aprendida_id',
    ];

    protected $casts = [
        'tipo' => TipoCandidatoLicaoAprendida::class,
        'status' => StatusCandidatoLicaoAprendida::class,
        'entidade_tipo' => TipoEntidadeVinculoLicao::class,
        'dados_snapshot' => 'array',
        'gerado_em' => 'datetime',
        'descartado_em' => 'datetime',
        'convertido_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function descartadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'descartado_por');
    }

    public function licaoAprendida(): BelongsTo
    {
        return $this->belongsTo(LicaoAprendida::class);
    }

    public function estaPendente(): bool
    {
        return $this->status === StatusCandidatoLicaoAprendida::Pendente;
    }
}
