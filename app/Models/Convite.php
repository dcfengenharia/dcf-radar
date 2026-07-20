<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Convite extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'convites';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'email',
        'papel',
        'perfil_id',
        'token',
        'convidado_por_id',
        'status',
        'expira_em',
        'aceito_em',
    ];

    protected $casts = [
        'expira_em' => 'datetime',
        'aceito_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    public function convidadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convidado_por_id');
    }

    public function expirado(): bool
    {
        return $this->expira_em !== null && $this->expira_em->isPast();
    }
}
