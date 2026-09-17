<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Perfis\AtribuicaoPerfilConvite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * FASE 2C, Seção 4 — os N perfis que este convite concede (via
     * `convite_perfis`). Relação SÓ PRA LEITURA — nunca chamar
     * `attach()`/`sync()`/`detach()` nela (a pivot tem PK própria em
     * ULID, que esses métodos não geram — ver docblock de
     * `App\Models\ConvitePerfil`). Toda escrita passa por
     * `App\Support\Perfis\AtribuicaoPerfilConvite::gravar()`.
     */
    public function perfis(): BelongsToMany
    {
        return $this->belongsToMany(Perfil::class, 'convite_perfis', 'convite_id', 'perfil_id')->withTimestamps();
    }

    public function convidadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convidado_por_id');
    }

    public function expirado(): bool
    {
        return $this->expira_em !== null && $this->expira_em->isPast();
    }

    /**
     * Nomes de TODOS os perfis que este convite concede, prontos pra
     * exibição (nunca IDs/slugs técnicos) — usado no e-mail de convite,
     * na tela de aceite e na listagem de "Convites pendentes". Cai pro
     * `perfil` legado sozinho quando o convite não tem nenhuma linha em
     * `convite_perfis` (convite anterior a esta funcionalidade).
     */
    public function nomesPerfis(): string
    {
        return AtribuicaoPerfilConvite::perfisParaExibicao($this)->pluck('nome')->implode(', ');
    }
}
