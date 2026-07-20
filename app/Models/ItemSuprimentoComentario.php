<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemSuprimentoComentario extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'item_suprimento_comentarios';

    protected $fillable = [
        'tenant_id',
        'item_suprimento_id',
        'autor_id',
        'comentario',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
