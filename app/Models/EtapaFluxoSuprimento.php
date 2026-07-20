<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtapaFluxoSuprimento extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'etapas_fluxo_suprimento';

    protected $fillable = [
        'tenant_id',
        'fluxo_suprimento_id',
        'ordem',
        'nome',
        'prazo_dias_uteis',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'prazo_dias_uteis' => 'integer',
    ];

    public function fluxo(): BelongsTo
    {
        return $this->belongsTo(FluxoSuprimento::class, 'fluxo_suprimento_id');
    }
}
