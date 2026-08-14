<?php

namespace App\Models;

use App\Enums\TipoCronogramaImportacao;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CronogramaImportacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'cronograma_importacoes';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'user_id',
        'arquivo',
        'data_status',
        'metodo_distribuicao',
        'tipo',
        'criadas',
        'atualizadas',
        'removidas',
        'importado_em',
    ];

    protected $casts = [
        'data_status'  => 'date',
        'importado_em' => 'datetime',
        'tipo'         => TipoCronogramaImportacao::class,
        'criadas'      => 'integer',
        'atualizadas'  => 'integer',
        'removidas'    => 'integer',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function avancoPeriodos(): HasMany
    {
        return $this->hasMany(AvancoPeriodo::class);
    }

    public function atividadeSnapshots(): HasMany
    {
        return $this->hasMany(AtividadeSnapshot::class);
    }

    public function linhaBase(): HasOne
    {
        return $this->hasOne(LinhaBase::class, 'cronograma_importacao_id');
    }

    public function healthCheck(): HasOne
    {
        return $this->hasOne(CronogramaImportacaoHealthCheck::class, 'cronograma_importacao_id');
    }

    /**
     * Importação imediatamente anterior da MESMA obra e do MESMO `tipo`
     * (Fase 3.1 — Evolução do Score) — usada só pra exibir "desde a última
     * importação", nunca recalcula nada. "Mesmo tipo" é comparação exata
     * (Baseline com Baseline, Avanço com Avanço, Ambos com Ambos) — não
     * mistura Baseline com Ambos, para não comparar Scores calculados
     * sobre universos de findings potencialmente diferentes.
     */
    public function importacaoAnterior(): ?self
    {
        return static::where('obra_id', $this->obra_id)
            ->where('tipo', $this->tipo)
            ->where('importado_em', '<', $this->importado_em)
            ->orderByDesc('importado_em')
            ->with('healthCheck')
            ->first();
    }
}
