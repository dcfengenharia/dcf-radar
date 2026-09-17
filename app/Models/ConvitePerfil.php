<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FASE 2C, Seção 4-8 — a atribuição "este Convite concede o Perfil X",
 * separada do Convite em si (mesmo espírito de `App\Models\
 * ObraUserPerfil` pra `obra_user_perfil`). 1 Convite pode ter N linhas
 * aqui (1..N perfis) — nunca acessado via `belongsToMany()->attach()`/
 * `->sync()` (pivô com PK ULID própria exige geração via Eloquent
 * `create()`, não via insert cru do query builder que esses métodos
 * fazem — mesma lição já documentada pra `ObraUserPerfil`). Escrita
 * sempre via `App\Support\Perfis\AtribuicaoPerfilConvite`.
 */
class ConvitePerfil extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'convite_perfis';

    protected $fillable = [
        'tenant_id',
        'convite_id',
        'perfil_id',
    ];

    public function convite(): BelongsTo
    {
        return $this->belongsTo(Convite::class);
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }
}
