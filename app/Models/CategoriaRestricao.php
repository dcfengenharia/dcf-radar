<?php

namespace App\Models;

use App\Enums\PilarLean;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaRestricao extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'categorias_restricao';

    protected $fillable = [
        'tenant_id',
        'nome',
        'pilar_lean',
    ];

    protected $casts = [
        'pilar_lean' => PilarLean::class,
    ];

    public function restricoes(): HasMany
    {
        return $this->hasMany(Restricao::class, 'categoria_id');
    }
}
