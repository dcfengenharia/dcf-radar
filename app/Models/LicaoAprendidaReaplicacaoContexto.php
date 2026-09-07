<?php

namespace App\Models;

use App\Enums\TipoEntidadeVinculoLicao;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 5) — contexto operacional OPCIONAL de
 * uma reaplicação (0..N por reaplicação, nunca 1 coluna singular na
 * própria reaplicação — ver docblock da migration). `entidade_tipo`/
 * `entidade_id` são referência solta, sempre resolvida via
 * `App\Support\LicoesAprendidas\VinculoLicaoResolver` — nunca aqui
 * dentro, mesmo padrão de `LicaoAprendidaVinculo`.
 */
class LicaoAprendidaReaplicacaoContexto extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'licao_aprendida_reaplicacao_contextos';

    protected $fillable = [
        'tenant_id',
        'reaplicacao_id',
        'entidade_tipo',
        'entidade_id',
        'titulo_snapshot',
        'created_by_id',
    ];

    protected $casts = [
        'entidade_tipo' => TipoEntidadeVinculoLicao::class,
    ];

    public function reaplicacao(): BelongsTo
    {
        return $this->belongsTo(LicaoAprendidaReaplicacao::class, 'reaplicacao_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
