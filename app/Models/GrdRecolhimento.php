<?php

namespace App\Models;

use App\Enums\ResultadoRecolhimento;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 18, Etapa 18.5.1 — evento append-only de recolhimento sobre uma
 * GrdDistribuicao (mesmo espírito de PlanoAcaoReconciliacao/
 * DocumentoEngenhariaReprogramacao: nunca editado nem apagado). Criado
 * exclusivamente por App\Actions\Engenharia\RegistrarRecolhimento.
 */
class GrdRecolhimento extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'grd_recolhimentos';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'grd_distribuicao_id',
        'resultado',
        'quantidade',
        'ocorrido_em',
        'registrado_por',
        'observacao',
    ];

    protected $casts = [
        'resultado' => ResultadoRecolhimento::class,
        'quantidade' => 'integer',
        'ocorrido_em' => 'datetime',
    ];

    public function distribuicao(): BelongsTo
    {
        return $this->belongsTo(GrdDistribuicao::class, 'grd_distribuicao_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
