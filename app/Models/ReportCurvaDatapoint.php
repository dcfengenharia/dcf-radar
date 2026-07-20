<?php

namespace App\Models;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um ponto (período) da curva S de um ReportCurva — mesma forma de
 * AvancoPeriodo, mas snapshotado: gravado uma vez na geração do rascunho
 * e nunca mais recalculado, mesmo que o cronograma seja reimportado depois.
 */
class ReportCurvaDatapoint extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'report_curva_id',
        'granularidade',
        'serie',
        'periodo_inicio',
        'horas',
        'percentual_acumulado',
    ];

    protected $casts = [
        'granularidade' => GranularidadePeriodo::class,
        'serie' => SerieAvanco::class,
        'periodo_inicio' => 'date',
        'horas' => 'decimal:2',
        'percentual_acumulado' => 'decimal:2',
    ];

    public function reportCurva(): BelongsTo
    {
        return $this->belongsTo(ReportCurva::class);
    }
}
