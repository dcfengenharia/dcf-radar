<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma curva do report — o pacote da obra (ou null = obra inteira) que o
 * planejamento escolheu destacar naquela semana. título/términos/total de
 * HH são snapshots gravados na geração (ver App\Services\ReportGerador),
 * não relações "ao vivo" que mudam se o pacote for renomeado depois.
 */
class ReportCurva extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'report_id',
        'pacote_trabalho_id',
        'ordem',
        'titulo_exibicao',
        'termino_linha_base',
        'termino_tendencia',
        'total_hh_previsto',
        'total_atividades',
        'atividades_concluidas',
        'atividades_atrasadas',
    ];

    protected $casts = [
        'termino_linha_base' => 'date',
        'termino_tendencia' => 'date',
        'total_hh_previsto' => 'decimal:2',
        'total_atividades' => 'integer',
        'atividades_concluidas' => 'integer',
        'atividades_atrasadas' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function pacoteTrabalho(): BelongsTo
    {
        return $this->belongsTo(PacoteTrabalho::class);
    }

    public function datapoints(): HasMany
    {
        return $this->hasMany(ReportCurvaDatapoint::class);
    }

    public function desvios(): HasMany
    {
        return $this->hasMany(ReportDesvio::class)->orderBy('ordem');
    }

    public function pontosAtencao(): HasMany
    {
        return $this->hasMany(ReportPontoAtencao::class)->orderBy('ordem');
    }
}
