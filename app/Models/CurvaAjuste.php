<?php

namespace App\Models;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurvaAjuste extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'curva_ajustes';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'serie',
        'granularidade',
        'periodo_inicio',
        'pacote_trabalho_id',
        'etapa_id',
        'disciplina_id',
        'frente_trabalho_id',
        'entregavel_id',
        'equipe_responsavel_id',
        'personalizado_1_id',
        'personalizado_2_id',
        'personalizado_3_id',
        'personalizado_4_id',
        'personalizado_5_id',
        'faturamento_direto',
        'valor_ajustado',
        'valor_calculado_no_ajuste',
        'motivo',
        'ajustado_por',
    ];

    protected $casts = [
        'serie'                      => SerieAvanco::class,
        'granularidade'              => GranularidadePeriodo::class,
        'periodo_inicio'             => 'date',
        'valor_ajustado'             => 'decimal:2',
        'valor_calculado_no_ajuste'  => 'decimal:2',
        'faturamento_direto'         => 'boolean',
    ];

    /**
     * 'escopo_extra_hash' nunca é setado à mão: pacote/etapa/disciplina/frente
     * continuam indexados crus no unique de curva_ajustes, mas as 8 dimensões
     * novas (7 FKs + faturamento_direto) não cabem cruas no mesmo índice —
     * MySQL recusa por estourar o limite de 3072 bytes de chave. Por isso elas
     * entram no índice único só via este hash, recalculado a cada save.
     */
    public static function booted(): void
    {
        static::saving(function (CurvaAjuste $ajuste) {
            $ajuste->escopo_extra_hash = md5(implode('|', [
                $ajuste->entregavel_id ?? '',
                $ajuste->equipe_responsavel_id ?? '',
                $ajuste->personalizado_1_id ?? '',
                $ajuste->personalizado_2_id ?? '',
                $ajuste->personalizado_3_id ?? '',
                $ajuste->personalizado_4_id ?? '',
                $ajuste->personalizado_5_id ?? '',
                $ajuste->faturamento_direto === null ? '' : ($ajuste->faturamento_direto ? '1' : '0'),
            ]));
        });
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ajustado_por');
    }

    /**
     * Retorna true quando o valor calculado na base mudou além da tolerância
     * após uma reimportação — sinal de que este ajuste manual pode estar obsoleto.
     */
    public function obsoletoFrente(float $valorCalculadoAtual, float $tolerancia = 0.01): bool
    {
        if ($this->valor_calculado_no_ajuste === null) {
            return false;
        }

        return abs((float) $this->valor_calculado_no_ajuste - $valorCalculadoAtual) > $tolerancia;
    }
}
