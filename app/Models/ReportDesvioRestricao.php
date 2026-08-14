<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot do impacto de Restrições sobre uma linha de ReportDesvio (Fase
 * 5, Etapa C2) — 1:1 com ReportDesvio, gravado uma única vez por
 * App\Services\ImpactoRestricoesGerador no momento da geração do Report.
 * `detalhes` congela descrição/status/categoria/responsável como STRING,
 * nunca FK viva — a Restricao referenciada pode mudar de status, ser
 * reaberta ou até excluída depois, sem afetar o que este Report mostra
 * (mesma filosofia de "fotografia" do resto do Report).
 */
class ReportDesvioRestricao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'report_desvio_restricoes';

    protected $fillable = [
        'tenant_id',
        'report_desvio_id',
        'total_abertas',
        'total_vencidas',
        'total_criticas',
        'detalhes',
    ];

    protected $casts = [
        'total_abertas' => 'integer',
        'total_vencidas' => 'integer',
        'total_criticas' => 'integer',
        'detalhes' => 'array',
    ];

    public function reportDesvio(): BelongsTo
    {
        return $this->belongsTo(ReportDesvio::class);
    }
}
