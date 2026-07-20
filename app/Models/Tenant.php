<?php

namespace App\Models;

use App\Enums\StatusAssinatura;
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
            $tenant->criarTrialAutomatico();
        });
    }

    /**
     * Override do plano do trial automático — usado pela página de
     * cadastro (wizard) quando o próprio usuário escolhe um plano no
     * passo "Escolha seu plano", em vez do plano genérico
     * `padrao_trial`. Mesmo padrão de propriedade estática +
     * try/finally já usado em App\Support\TenantContext::actingAs() —
     * só afeta quem chama comPlanoTrialForcado() explicitamente; todo
     * outro chamador de Tenant::create() (admin, "Criar Nova Empresa",
     * testes) continua resolvendo pelo `padrao_trial` de sempre.
     */
    private static ?string $planoTrialForcadoId = null;

    public static function comPlanoTrialForcado(?string $planoId, callable $callback): mixed
    {
        $anterior = self::$planoTrialForcadoId;
        self::$planoTrialForcadoId = $planoId;
        try {
            return $callback();
        } finally {
            self::$planoTrialForcadoId = $anterior;
        }
    }

    /**
     * Trial automático de 7 dias pra todo tenant novo (Fase 10 do roadmap
     * de maturidade SaaS) — só cria a Assinatura se existir um Plano
     * marcado `padrao_trial = true` (ou um plano forçado via
     * `comPlanoTrialForcado()`, ver acima); sem plano resolvido, o
     * tenant simplesmente nasce sem Assinatura (mesmo fallback "sem
     * plano = sem restrição" já usado em todo o resto do sistema, nunca
     * quebra o cadastro por configuração de plano faltando ou inválida).
     * `origem = 'sistema'` — nem `manual` (admin não fez nada) nem
     * `mercadopago` (não passou por pagamento nenhum ainda).
     */
    private function criarTrialAutomatico(): void
    {
        $planoTrial = self::$planoTrialForcadoId
            ? Plano::where('id', self::$planoTrialForcadoId)->where('ativo', true)->first()
            : Plano::where('padrao_trial', true)->first();

        if (! $planoTrial) {
            return;
        }

        $this->assinaturas()->create([
            'plano_id' => $planoTrial->id,
            'status' => StatusAssinatura::Trial->value,
            'origem' => 'sistema',
            'inicio' => now()->toDateString(),
            'fim_trial' => now()->addDays(7)->toDateString(),
        ]);
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
