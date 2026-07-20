<?php

namespace App\Models;

use App\Enums\TipoAvisoPlataforma;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Aviso da plataforma (popup importante do dono do negócio). Não
 * pertence a nenhum tenant — é conteúdo de nível de plataforma, mas
 * pode ser DIRECIONADO a empresas específicas via tenants() (sem
 * nenhuma linha em aviso_plataforma_tenants = todas as empresas).
 */
class AvisoPlataforma extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'avisos_plataforma';

    protected $fillable = [
        'titulo',
        'mensagem',
        'tipo',
        'ativo',
        'criado_por_id',
        'imagem_promocional',
        'link_url',
        'link_texto',
    ];

    protected $casts = [
        'tipo' => TipoAvisoPlataforma::class,
        'ativo' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $aviso) {
            if ($aviso->imagem_promocional) {
                Storage::disk('public')->delete($aviso->imagem_promocional);
            }
        });
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }

    public function dispensas(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'aviso_plataforma_dispensas')->withTimestamps();
    }

    /** Empresas (tenants) direcionadas — vazio significa "todas as empresas". */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'aviso_plataforma_tenants')->withTimestamps();
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    /** Sem direcionamento (nenhuma linha em tenants()) = aparece pra qualquer tenant. */
    public function scopeParaTenant(Builder $query, ?string $tenantId): Builder
    {
        return $query->where(function (Builder $q) use ($tenantId) {
            $q->whereDoesntHave('tenants')
                ->orWhereHas('tenants', fn (Builder $t) => $t->where('tenants.id', $tenantId));
        });
    }

    /**
     * Mensagem vem do editor rico (Quill) no admin — sempre sanitizada
     * antes de gravar, único ponto de entrada, pra nunca depender de
     * quem está chamando lembrar de limpar o HTML. Tags permitidas
     * batem exatamente com a toolbar do Quill configurada em
     * ⚡admin/avisos/index.blade.php (negrito, itálico, sublinhado,
     * listas, link, imagem) — nada além disso passa. Opcional: aviso
     * promocional (só imagem em tela cheia + botão) pode não ter texto.
     */
    public function setMensagemAttribute(?string $value): void
    {
        $this->attributes['mensagem'] = $value === null || $value === ''
            ? null
            : $this->sanitizarMensagemHtml($value);
    }

    private function sanitizarMensagemHtml(string $html): string
    {
        $cachePath = storage_path('app/htmlpurifier');
        File::ensureDirectoryExists($cachePath);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,ol,li,ul,a[href|rel|target],img[src|alt|width|height]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);

        return (new HTMLPurifier($config))->purify($html);
    }
}
