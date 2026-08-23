<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 18, Etapa 18.5.1 — destinatário incluído numa GRD específica
 * (junção Grd↔Destinatario), com snapshot de identidade congelado na
 * emissão. Ver docblock da migration `create_grd_destinatarios_table`.
 */
class GrdDestinatario extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'grd_destinatarios';

    protected $fillable = [
        'tenant_id',
        'grd_id',
        'destinatario_id',
        'nome_snapshot',
        'empresa_snapshot',
        'setor_snapshot',
    ];

    public function grd(): BelongsTo
    {
        return $this->belongsTo(Grd::class);
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Destinatario::class, 'destinatario_id');
    }

    public function distribuicoes(): HasMany
    {
        return $this->hasMany(GrdDistribuicao::class);
    }
}
