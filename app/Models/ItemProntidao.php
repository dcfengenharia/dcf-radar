<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItemProntidao extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'itens_prontidao';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'nome',
        'ordem',
    ];

    protected $casts = [
        'ordem' => 'integer',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(AtividadeItemProntidao::class, 'item_prontidao_id');
    }
}
