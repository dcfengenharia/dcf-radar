<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfilPermissao extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'perfil_permissoes';

    protected $fillable = [
        'tenant_id',
        'perfil_id',
        'funcionalidade',
        'acao',
    ];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }
}
