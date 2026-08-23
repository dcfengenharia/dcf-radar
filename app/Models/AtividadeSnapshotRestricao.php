<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 17, A.9.3 — Fotografia O: 1 linha por Restrição que estava ABERTA
 * (aberta/em_tratamento/aguardando_terceiros — mesmo conjunto canônico de
 * App\Models\Atividade::estaPronta()/scopeProntas()) no instante de uma
 * importação Avanço/Ambos. Nunca todas as restrições históricas da
 * atividade — só as pendentes naquele momento (decisão registrada no
 * relatório da A.9.3). `bloqueante`/`status` são o valor exato daquele
 * instante, preservado mesmo que a Restrição mude depois.
 */
class AtividadeSnapshotRestricao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'atividade_snapshot_restricoes';

    protected $fillable = [
        'tenant_id',
        'cronograma_importacao_id',
        'atividade_id',
        'restricao_id',
        'bloqueante',
        'status',
    ];

    protected $casts = [
        'bloqueante' => 'boolean',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class, 'atividade_id');
    }

    public function restricao(): BelongsTo
    {
        return $this->belongsTo(Restricao::class, 'restricao_id');
    }
}
