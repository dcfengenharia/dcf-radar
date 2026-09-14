<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Home Executiva (Ciclo 25, Fechamento) — 1 linha por (obra, usuário):
 * quando este usuário abriu a Home desta obra pela última vez. Nunca lido
 * nem escrito diretamente por telas/Actions de negócio — só por
 * `App\Support\Gestao\UltimoAcessoHomeTracker`.
 */
class UltimoAcessoHome extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'ultimo_acesso_home_por_usuario_obra';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'user_id',
        'ultimo_acesso_em',
    ];

    protected $casts = [
        'ultimo_acesso_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
