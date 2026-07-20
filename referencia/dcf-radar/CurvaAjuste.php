<?php

namespace App\Models;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste manual de um ponto da curva. O valor exibido na curva e nos
 * relatórios passa a ser o valor_ajustado; o valor calculado continua
 * disponível para mostrar o "antes/depois" e para auditoria.
 */
class CurvaAjuste extends Model
{
    use HasUlids, BelongsToTenant;

    protected $table = 'curva_ajustes';

    protected $fillable = [
        'tenant_id', 'obra_id', 'serie', 'granularidade', 'periodo_inicio',
        'valor_ajustado', 'valor_calculado_no_ajuste', 'motivo', 'ajustado_por',
    ];

    protected function casts(): array
    {
        return [
            'serie' => SerieAvanco::class,
            'granularidade' => GranularidadePeriodo::class,
            'periodo_inicio' => 'date',
            'valor_ajustado' => 'decimal:2',
            'valor_calculado_no_ajuste' => 'decimal:2',
        ];
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ajustado_por');
    }

    /**
     * O ajuste ficou obsoleto se a base recalculada divergir do valor
     * calculado que existia quando ele foi feito (ex.: após reimportar).
     */
    public function obsoletoFrente(float $valorCalculadoAtual, float $tolerancia = 0.01): bool
    {
        if ($this->valor_calculado_no_ajuste === null) {
            return false;
        }
        return abs((float) $this->valor_calculado_no_ajuste - $valorCalculadoAtual) > $tolerancia;
    }
}
