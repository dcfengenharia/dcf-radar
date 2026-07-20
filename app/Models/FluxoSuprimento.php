<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FluxoSuprimento extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'fluxos_suprimento';

    protected $fillable = [
        'tenant_id',
        'nome',
        'descricao',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaFluxoSuprimento::class)->orderBy('ordem');
    }

    public function itensSuprimento(): HasMany
    {
        return $this->hasMany(ItemSuprimento::class);
    }
}
