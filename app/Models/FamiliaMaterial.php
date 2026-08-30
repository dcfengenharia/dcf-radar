<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 19, Etapa 19.1.HARDENING — normalização de identidade textual.
 * `nome` é a identidade real (código é opcional) — só `trim()`, NUNCA
 * mexe em maiúscula/acento (preservaria a semântica do texto digitado).
 * A collation da coluna (`utf8mb4_unicode_ci`, confirmada empiricamente
 * antes de decidir) já é case- E accent-insensitive na comparação —
 * "Tubulação"/"TUBULACAO"/"tubulacao" colidem sozinhos no
 * `unique(tenant_id, nome)` sem precisar de nenhuma coluna computada.
 * `codigo` (quando presente) segue o mesmo padrão de
 * `UnidadeMedida::setCodigoAttribute()` (trim+maiúsculo) — consistência
 * entre os dois catálogos do domínio de Take Off.
 */
class FamiliaMaterial extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'familias_material';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nome',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function setNomeAttribute(string $value): void
    {
        $this->attributes['nome'] = trim($value);
    }

    public function setCodigoAttribute(?string $value): void
    {
        $this->attributes['codigo'] = $value !== null ? mb_strtoupper(trim($value)) : null;
    }

    public function itensTakeOff(): HasMany
    {
        return $this->hasMany(ItemTakeOff::class);
    }
}
