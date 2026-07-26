<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Indicadores de Restrições/Engenharia/Suprimentos da semana anterior e da
 * próxima semana, relativas ao periodo_referencia do Report — mesma
 * filosofia de "fotografia" do resto do Report: calculado e gravado uma
 * única vez em ReportGerador::gerarRascunho(), nunca recalculado depois.
 */
class ReportIndicadorSemana extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'report_indicadores_semana';

    protected $fillable = [
        'tenant_id',
        'report_id',
        'categoria',
        'janela',
        'periodo_inicio',
        'periodo_fim',
        'total_previsto',
        'total_concluido',
        'detalhes',
    ];

    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fim' => 'date',
        'detalhes' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function percentualConcluido(): ?float
    {
        if ($this->total_concluido === null || $this->total_previsto === 0) {
            return null;
        }

        return round($this->total_concluido / $this->total_previsto * 100, 1);
    }
}
