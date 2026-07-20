<?php

namespace App\Models;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvancoPeriodo extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'avanco_periodos';

    protected $fillable = [
        'tenant_id',
        'cronograma_importacao_id',
        'atividade_id',
        'granularidade',
        'serie',
        'periodo_inicio',
        'horas',
    ];

    protected $casts = [
        'granularidade'  => GranularidadePeriodo::class,
        'serie'          => SerieAvanco::class,
        'periodo_inicio' => 'date',
        'horas'          => 'decimal:2',
    ];

    public function cronogramaImportacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class);
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }
}
