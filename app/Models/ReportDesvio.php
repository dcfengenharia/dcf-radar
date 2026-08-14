<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Uma linha do quadro de análise de desvios — a linha "nível pai" (o
 * próprio pacote da curva, peso=1) ou um dos filhos imediatos dele
 * (nunca mais fundo que isso). Ver App\Services\ReportGerador pra como
 * peso/percentual_previsto/percentual_real/percentual_desvio/
 * percentual_impacto são calculados, com a fórmula verificada contra a
 * planilha real do usuário.
 */
class ReportDesvio extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'report_curva_id',
        'pacote_trabalho_id',
        'eh_nivel_pai',
        'titulo_exibicao',
        'peso',
        'percentual_previsto',
        'percentual_real',
        'percentual_desvio',
        'percentual_impacto',
        'ordem',
    ];

    protected $casts = [
        'eh_nivel_pai' => 'boolean',
        'peso' => 'decimal:4',
        'percentual_previsto' => 'decimal:2',
        'percentual_real' => 'decimal:2',
        'percentual_desvio' => 'decimal:2',
        'percentual_impacto' => 'decimal:2',
    ];

    public function reportCurva(): BelongsTo
    {
        return $this->belongsTo(ReportCurva::class);
    }

    public function pacoteTrabalho(): BelongsTo
    {
        return $this->belongsTo(PacoteTrabalho::class);
    }

    /** Snapshot de Impacto de Restrições desta linha (Fase 5, Etapa C2) — ver App\Models\ReportDesvioRestricao. */
    public function restricaoImpacto(): HasOne
    {
        return $this->hasOne(ReportDesvioRestricao::class);
    }
}
