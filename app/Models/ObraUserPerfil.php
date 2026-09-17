<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FASE 2B — a atribuição "usuário TEM o perfil X nesta obra", separada
 * da membresia (`obra_user`, intocada). 1 usuário pode ter N linhas
 * aqui pra uma MESMA obra (múltiplos perfis) — a união das permissões
 * de todos eles é a permissão efetiva (nunca DENY explícito).
 *
 * Fonte primária consultada pelo resolver central
 * (`App\Models\Concerns\HasObraPapel`), com fallback de leitura pra
 * `obra_user.perfil_id` legado — ver docblock de
 * `HasObraPapel::perfisIdsNaObra()` pra autoridade completa. Escrita
 * sempre via `App\Support\AtribuicaoPerfilObra` (nunca `create()`/
 * `delete()` direto fora dela, pra manter o espelho legado em
 * `obra_user.perfil_id` sempre consistente com a UI atual de "1 perfil
 * por membro").
 */
class ObraUserPerfil extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'obra_user_perfil';

    protected $fillable = [
        'tenant_id',
        'work_id',
        'user_id',
        'perfil_id',
    ];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
