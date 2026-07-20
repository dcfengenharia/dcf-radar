<?php

namespace App\Models;

use App\Enums\SerieAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemSuprimentoEtapaData extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'itens_suprimento_etapa_datas';

    protected $fillable = [
        'tenant_id',
        'item_suprimento_etapa_id',
        'serie',
        'data',
        'atualizado_por',
    ];

    protected $casts = [
        'serie' => SerieAvanco::class,
        'data' => 'date',
    ];

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimentoEtapa::class, 'item_suprimento_etapa_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por');
    }
}
