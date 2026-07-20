<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

class ItemSuprimentoAtividade extends Model
{
    use AsPivot, BelongsToTenant, HasUlids;

    protected $table = 'item_suprimento_atividades';

    protected $fillable = [
        'tenant_id',
        'item_suprimento_id',
        'atividade_id',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }
}
