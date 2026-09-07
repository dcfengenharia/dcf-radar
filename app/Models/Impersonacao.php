<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha de auditoria de "entrar como tenant" pelo dono da plataforma.
 * Sem BelongsToTenant — é um registro de nível de plataforma.
 */
class Impersonacao extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'impersonacoes';

    protected $fillable = [
        'admin_user_id',
        'tenant_id',
        'motivo',
        'iniciado_em',
        'finalizado_em',
        'ip',
    ];

    protected $casts = [
        'iniciado_em' => 'datetime',
        'finalizado_em' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
