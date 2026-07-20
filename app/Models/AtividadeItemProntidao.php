<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtividadeItemProntidao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'atividade_itens_prontidao';

    protected $fillable = [
        'tenant_id',
        'atividade_id',
        'item_prontidao_id',
        'concluido',
        'concluido_por',
        'concluido_em',
    ];

    protected $casts = [
        'concluido'    => 'boolean',
        'concluido_em' => 'datetime',
    ];

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemProntidao::class, 'item_prontidao_id');
    }

    public function conclusor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }
}
