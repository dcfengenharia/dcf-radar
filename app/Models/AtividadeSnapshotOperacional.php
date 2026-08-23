<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 17, A.9.3 — Fotografia O (1 linha por atividade tocada por uma
 * importação Avanço/Ambos): status/fora_do_cronograma capturados ANTES
 * desta importação (null quando a atividade foi criada NESTA própria
 * importação — genuinamente não existia "antes"); `pronta` é o resultado
 * congelado de Atividade::scopeProntas() no instante da importação, nunca
 * recalculado depois. Nunca confundir com AtividadeSnapshot (Fotografia F,
 * A.9.2) — aquela é o arquivo, esta é a plataforma.
 */
class AtividadeSnapshotOperacional extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'atividade_snapshot_operacionais';

    protected $fillable = [
        'tenant_id',
        'cronograma_importacao_id',
        'atividade_id',
        'status',
        'fora_do_cronograma',
        'pronta',
    ];

    protected $casts = [
        'fora_do_cronograma' => 'boolean',
        'pronta' => 'boolean',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class, 'atividade_id');
    }
}
