<?php

namespace App\Models;

use App\Enums\TipoEntidadeVinculoLicao;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 23, Etapa 23.1 — vínculo contextual entre uma lição e um fato
 * operacional real. `entidade_tipo`/`entidade_id` são referência SOLTA
 * (sem FK física, mesmo idioma de `InconsistenciaAvanco`/
 * `SituacaoOcorrencia`) — resolução real sempre via
 * `App\Support\LicoesAprendidas\VinculoLicaoResolver`, nunca aqui
 * dentro (o model não sabe resolver a entidade sozinho, de propósito —
 * evita qualquer tentação de reintroduzir polimorfismo Eloquent).
 */
class LicaoAprendidaVinculo extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $fillable = [
        'tenant_id',
        'licao_aprendida_id',
        'entidade_tipo',
        'entidade_id',
        'titulo_snapshot',
        'e_origem',
        'created_by_id',
    ];

    protected $casts = [
        'entidade_tipo' => TipoEntidadeVinculoLicao::class,
        'e_origem' => 'boolean',
    ];

    public function licao(): BelongsTo
    {
        return $this->belongsTo(LicaoAprendida::class, 'licao_aprendida_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
