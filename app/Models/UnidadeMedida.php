<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnidadeMedida extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'unidades_medida';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nome',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    /**
     * Ciclo 19, Etapa 19.1.CORREÇÃO — normaliza sempre (trim + maiúsculo)
     * pra "kg"/"KG"/"Kg" nunca virarem 3 unidades diferentes, seja o
     * dado vindo do cadastro manual ou da importação de planilha — um
     * único ponto de normalização, nunca duplicado no chamador.
     */
    public function setCodigoAttribute(string $value): void
    {
        $this->attributes['codigo'] = mb_strtoupper(trim($value));
    }

    public function itensTakeOff(): HasMany
    {
        return $this->hasMany(ItemTakeOff::class);
    }
}
