<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtividadeComentario extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'atividade_comentarios';

    protected $fillable = [
        'tenant_id',
        'atividade_id',
        'autor_id',
        'comentario',
    ];

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
