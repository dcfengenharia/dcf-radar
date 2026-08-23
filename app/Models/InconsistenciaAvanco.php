<?php

namespace App\Models;

use App\Enums\EntidadeInconsistenciaAvanco;
use App\Enums\SeveridadeInconsistenciaAvanco;
use App\Enums\StatusInconsistenciaAvanco;
use App\Enums\TipoInconsistenciaAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 17, A.9.4 — evidência histórica e imutável de uma divergência entre
 * Fotografia F (o que o cronograma declarou NESTA importação) e Fotografia O
 * (o que a plataforma sabia IMEDIATAMENTE ANTES dela). Nunca uma correção —
 * só um registro pra revisão humana. Ciclo 17, A.9.6 adicionou o primeiro
 * fluxo de tratamento humano (ver App\Actions\InconsistenciaAvanco\
 * TratarInconsistenciaAvanco) — a ocorrência em si continua imutável: tratar
 * NUNCA apaga a linha nem altera tipo/severidade/importação/atividade/
 * entidade/detalhes, só grava os 4 campos de decisão humana.
 *
 * `entidade_id` é o ULID histórico da Restricao/ItemProntidao envolvida —
 * deliberadamente SEM FK física (não pode ter uma FK real apontando pra 2
 * tabelas possíveis, e a A.9.3.CORREÇÃO já provou que referência histórica
 * nunca deve usar `cascadeOnDelete()`). `detalhes` congela os fatos
 * necessários pra entender a ocorrência sem nunca reconsultar a entidade
 * atual (que pode ter mudado ou sido soft-deletada depois).
 */
class InconsistenciaAvanco extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'inconsistencias_avanco';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'atividade_id',
        'cronograma_importacao_id',
        'tipo',
        'severidade',
        'entidade_tipo',
        'entidade_id',
        'titulo',
        'detalhes',
        'detectada_em',
        'status',
        'tratado_por',
        'tratado_em',
        'justificativa',
    ];

    protected $casts = [
        'tipo' => TipoInconsistenciaAvanco::class,
        'severidade' => SeveridadeInconsistenciaAvanco::class,
        'entidade_tipo' => EntidadeInconsistenciaAvanco::class,
        'detalhes' => 'array',
        'detectada_em' => 'datetime',
        'status' => StatusInconsistenciaAvanco::class,
        'tratado_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class, 'atividade_id');
    }

    public function cronogramaImportacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }

    public function tratadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tratado_por');
    }

    public function estaAberta(): bool
    {
        return $this->status === StatusInconsistenciaAvanco::Aberta;
    }
}
