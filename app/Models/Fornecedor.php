<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fornecedor extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'fornecedores';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'nome',
        'cnpj',
        'contato_nome',
        'contato_email',
        'contato_telefone',
        'observacoes',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function itensSuprimento(): HasMany
    {
        return $this->hasMany(ItemSuprimento::class);
    }
}
