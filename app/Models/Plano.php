<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano de assinatura da plataforma. Não pertence a nenhum tenant — é um
 * model de nível de plataforma, visível igualmente pra todos.
 */
class Plano extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * Default de limite de upload (MB) pra planos novos e fallback pra
     * tenant sem nenhuma Assinatura ainda. Ver Tenant::limiteUploadMb().
     */
    public const LIMITE_UPLOAD_PADRAO_MB = 100;

    protected $fillable = [
        'nome',
        'descricao',
        'preco_mensal',
        'max_obras',
        'max_usuarios',
        'limite_upload_mb',
        'ativo',
    ];

    protected $casts = [
        'preco_mensal' => 'decimal:2',
        'ativo' => 'boolean',
    ];

    public function assinaturas(): HasMany
    {
        return $this->hasMany(Assinatura::class);
    }

    public function emUsoPorAlgumTenant(): bool
    {
        return $this->assinaturas()->exists();
    }
}
