<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Tenant extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'convite_email_assunto',
        'convite_email_mensagem',
        'logo_path',
        'cnpj',
        'razao_social',
        'telefone',
        'email_comercial',
        'criado_por_id',
    ];

    protected static function booted(): void
    {
        static::created(function (Tenant $tenant) {
            Perfil::seedPadrao($tenant);
        });
    }

    public function getLogoUrlAttribute(): string
    {
        if ($this->logo_path && Storage::disk('public')->exists($this->logo_path)) {
            return Storage::disk('public')->url($this->logo_path);
        }

        return asset('assets/img/logos/logo_oficial.png');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function assinaturas(): HasMany
    {
        return $this->hasMany(Assinatura::class);
    }

    public function assinaturaAtual(): ?Assinatura
    {
        return $this->assinaturas()->latest('inicio')->first();
    }

    /**
     * Fallback pra tenant sem nenhuma Assinatura ainda (situação de todo
     * tenant hoje — o sistema de Planos/Assinaturas existe mas ainda não
     * foi aplicado a nenhuma conta real). Segue o teto técnico da
     * plataforma (php.ini), não o valor-sugestão de plano novo — evita
     * regredir o limite de upload de quem já importava arquivos maiores
     * antes deste recurso existir.
     */
    public const LIMITE_UPLOAD_SEM_PLANO_MB = 300;

    public function limiteUploadMb(): int
    {
        return $this->assinaturaAtual()?->plano?->limite_upload_mb
            ?? self::LIMITE_UPLOAD_SEM_PLANO_MB;
    }

    /**
     * `null` = ilimitado, tanto pra tenant sem Assinatura (situação de
     * todo tenant hoje) quanto pra plano cujo `max_obras`/`max_usuarios`
     * está vazio (mesma semântica já usada na tela de Planos, onde
     * `null` exibe "Ilimitado") — ao contrário de `limiteUploadMb()`,
     * aqui não há teto técnico pra usar de fallback.
     */
    public function limiteObras(): ?int
    {
        return $this->assinaturaAtual()?->plano?->max_obras;
    }

    public function limiteUsuarios(): ?int
    {
        return $this->assinaturaAtual()?->plano?->max_usuarios;
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }

    /**
     * Todos os usuários que podem trocar pra este tenant (via tenant_user) —
     * superconjunto que inclui o criador e qualquer futuro usuário com
     * acesso adicional. Não confundir com users(), que é "usuários cuja
     * CASA (tenant_id direto) é este tenant".
     */
    public function usuariosComAcesso(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')->withTimestamps();
    }
}
