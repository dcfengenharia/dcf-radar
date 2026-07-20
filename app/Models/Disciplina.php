<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Disciplina extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'nome',
    ];

    public function atividades(): HasMany
    {
        return $this->hasMany(Atividade::class);
    }
}
