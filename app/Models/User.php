<?php

namespace App\Models;

use App\Notifications\CustomVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\Concerns\HasObraPapel;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use HasObraPapel;
    use HasProfilePhoto;
    use HasUlids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'first_name',
        'last_name',
        'cargo',
        'telefone',
        'email',
        'password',
        'tenant_id',
        'is_platform_admin',
        'ativo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_platform_admin' => 'boolean',
        'ativo' => 'boolean',
    ];

    protected $appends = [
        'profile_photo_url',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Roteamento do canal 'whatsapp' (App\Notifications\Channels\ZApiChannel)
     * — reaproveita o campo `telefone` de contato já existente no Perfil,
     * em vez de duplicar num campo `whatsapp` separado (decisão tomada na
     * Fase 9 do roadmap de maturidade SaaS).
     */
    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->telefone;
    }

    /**
     * Todos os tenants que este usuário pode acessar/trocar (via
     * tenant_user) — inclui o tenant "casa" (tenant_id), que já nasce
     * vinculado via o hook em booted() abaixo.
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')->withTimestamps();
    }

    public function podeGerenciarTenant(Tenant $tenant): bool
    {
        return $tenant->criado_por_id === $this->id;
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'obra_user', 'user_id', 'work_id')
            ->withPivot('papel', 'perfil_id')
            ->withTimestamps();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new CustomVerifyEmail);
    }

    public function avisosPlataformaDispensados(): BelongsToMany
    {
        return $this->belongsToMany(AvisoPlataforma::class, 'aviso_plataforma_dispensas')->withTimestamps();
    }

    /** Avisos ativos da plataforma que este usuário ainda não dispensou permanentemente. */
    public function avisosPlataformaPendentes(): \Illuminate\Support\Collection
    {
        return AvisoPlataforma::ativos()
            ->paraTenant(\App\Support\TenantContext::currentId())
            ->whereDoesntHave('dispensas', fn ($q) => $q->where('user_id', $this->id))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Garante que todo usuário criado com tenant_id já nasce com a linha
     * de pivô correspondente em tenant_user — sem precisar lembrar de
     * inserir manualmente em cada ponto de criação (registro, convite,
     * factories, seeders futuros).
     */
    protected static function booted(): void
    {
        static::created(function (User $user) {
            if ($user->tenant_id && ! $user->tenants()->where('tenants.id', $user->tenant_id)->exists()) {
                $user->tenants()->attach($user->tenant_id);
            }
        });
    }

    /**
     * Sobrescreve o padrão do HasProfilePhoto, que assume um campo "name"
     * único — este schema usa first_name/last_name.
     *
     * Pré-produção, Etapa 2 (auditoria de privacidade — seção 6): até esta
     * mudança, isso chamava ui-avatars.com toda vez que um usuário sem
     * foto própria aparecia em tela (navbar, comentários, listas) —
     * enviando as iniciais do nome + IP/User-Agent do VISITANTE (não do
     * dono do avatar) a um terceiro, nunca declarado em nenhum lugar.
     * Agora o avatar é gerado 100% localmente como um SVG embutido (data
     * URI) — mesmo texto/cores de antes, zero requisição de rede. Como o
     * retorno continua sendo uma URL utilizável em `<img src="...">`
     * (mesma assinatura/contrato do método original), nenhum template que
     * consome `profile_photo_url` precisou mudar.
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $name = trim("{$this->first_name} {$this->last_name}");
        $iniciais = collect(explode(' ', $name))
            ->filter()
            ->map(fn ($segmento) => mb_substr($segmento, 0, 1))
            ->join('');

        $texto = htmlspecialchars(mb_strtoupper($iniciais !== '' ? $iniciais : '?'), ENT_QUOTES | ENT_XML1);

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
                <rect width="200" height="200" fill="#EBF4FF"/>
                <text x="100" y="100" text-anchor="middle" dominant-baseline="central"
                    font-family="IBM Plex Sans, Arial, sans-serif" font-size="80" font-weight="600" fill="#7F9CF5">{$texto}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
