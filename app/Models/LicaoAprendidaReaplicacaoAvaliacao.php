<?php

namespace App\Models;

use App\Enums\ResultadoAvaliacaoReaplicacao;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 10/13) — 1 avaliação, append-only, de
 * uma `LicaoAprendidaReaplicacao`. Sem `HasAuthorship` de propósito
 * (diferente de `LicaoAprendidaVinculo`/`LicaoAprendidaReaplicacaoContexto`)
 * — o pedido nomeia explicitamente `avaliado_por`/`avaliado_em` como
 * campos de domínio (mesmo idioma de `publicado_por_id`/`publicado_em`
 * em `LicaoAprendida`), sempre carimbados por
 * `App\Actions\LicoesAprendidas\AvaliarReaplicacaoLicao` — nunca um
 * `created_by_id` genérico duplicado.
 *
 * Nunca editável/apagável — bloqueio estrutural em
 * `App\Observers\LicaoAprendidaReaplicacaoAvaliacaoObserver`.
 */
class LicaoAprendidaReaplicacaoAvaliacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'licao_aprendida_reaplicacao_avaliacoes';

    protected $fillable = [
        'tenant_id',
        'reaplicacao_id',
        'resultado',
        'avaliado_por_id',
        'avaliado_em',
        'observacao',
    ];

    protected $casts = [
        'resultado' => ResultadoAvaliacaoReaplicacao::class,
        'avaliado_em' => 'datetime',
    ];

    public function reaplicacao(): BelongsTo
    {
        return $this->belongsTo(LicaoAprendidaReaplicacao::class, 'reaplicacao_id');
    }

    public function avaliadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliado_por_id');
    }
}
