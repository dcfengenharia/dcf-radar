<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtividadeSnapshot extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'cronograma_importacao_id',
        'atividade_id',
        'inicio_planejado',
        'data_termino',
        'baseline_inicio',
        'baseline_termino',
    ];

    protected $casts = [
        'inicio_planejado' => 'date',
        'data_termino' => 'date',
        'baseline_inicio' => 'date',
        'baseline_termino' => 'date',
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
