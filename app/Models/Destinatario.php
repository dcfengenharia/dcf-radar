<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 18, Etapa 18.5.1 — cadastro de destinatário de GRD, obra-scoped
 * (decisão de produto 18.5.0/18.5.1). Ver docblock da migration
 * `create_destinatarios_table` para a justificativa completa.
 */
class Destinatario extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'destinatarios';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'user_id',
        'nome',
        'empresa',
        'setor',
        'email',
        'telefone',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function grdDestinatarios(): HasMany
    {
        return $this->hasMany(GrdDestinatario::class, 'destinatario_id');
    }
}
