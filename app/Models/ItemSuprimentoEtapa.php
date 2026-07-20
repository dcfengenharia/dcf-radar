<?php

namespace App\Models;

use App\Enums\SerieAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemSuprimentoEtapa extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'itens_suprimento_etapas';

    protected $fillable = [
        'tenant_id',
        'item_suprimento_id',
        'etapa_fluxo_suprimento_id',
        'ordem',
        'nome',
        'prazo_dias_uteis',
        'nao_aplicavel',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'prazo_dias_uteis' => 'integer',
        'nao_aplicavel' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function etapaTemplate(): BelongsTo
    {
        return $this->belongsTo(EtapaFluxoSuprimento::class, 'etapa_fluxo_suprimento_id');
    }

    public function datas(): HasMany
    {
        return $this->hasMany(ItemSuprimentoEtapaData::class, 'item_suprimento_etapa_id');
    }

    public function previsto(): ?ItemSuprimentoEtapaData
    {
        return $this->datas->firstWhere('serie', SerieAvanco::Previsto);
    }

    public function tendencia(): ?ItemSuprimentoEtapaData
    {
        return $this->datas->firstWhere('serie', SerieAvanco::Tendencia);
    }

    public function realizado(): ?ItemSuprimentoEtapaData
    {
        return $this->datas->firstWhere('serie', SerieAvanco::Realizado);
    }
}
