<?php

namespace App\Models;

use App\Enums\ResultadoAvaliacaoReaplicacao;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 23, Etapa 23.5.B — evidência de que `licao` foi conscientemente
 * reaplicada em `obra` (nunca a obra de origem da própria lição — ver
 * `App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao`). Unidade
 * corporativa Lição×Obra, garantida por
 * `licao_reaplicacoes_tenant_licao_obra_unique`. Identidade imutável
 * depois de criada — bloqueio estrutural em
 * `App\Observers\LicaoAprendidaReaplicacaoObserver`.
 *
 * "Resultado atual" NUNCA é uma coluna própria (Seção 14 — "preferir
 * derivação") — sempre a avaliação mais recente de `avaliacoes()`,
 * resolvida por `ultimaAvaliacao()`/`resultadoAtual()`. Ausência de
 * qualquer avaliação = "aguardando avaliação", um estado puramente de
 * apresentação (nunca gravado no banco).
 */
class LicaoAprendidaReaplicacao extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'licao_aprendida_reaplicacoes';

    protected $fillable = [
        'tenant_id',
        'licao_aprendida_id',
        'obra_id',
        'observacao_inicial',
        'created_by_id',
    ];

    public function licao(): BelongsTo
    {
        return $this->belongsTo(LicaoAprendida::class, 'licao_aprendida_id');
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function contextos(): HasMany
    {
        return $this->hasMany(LicaoAprendidaReaplicacaoContexto::class, 'reaplicacao_id');
    }

    public function avaliacoes(): HasMany
    {
        return $this->hasMany(LicaoAprendidaReaplicacaoAvaliacao::class, 'reaplicacao_id');
    }

    /**
     * A avaliação mais recente, por ordem de REGISTRO
     * (`created_at`/`id`, nunca `avaliado_em` — mesma convenção
     * "ordem operacional é sempre a ordem de registro" já usada em todo
     * o projeto). Se `avaliacoes` já veio eager-loaded, o CHAMADOR é
     * responsável por tê-la ordenado
     * `orderByDesc('created_at')->orderByDesc('id')` — ver
     * `App\Support\LicoesAprendidas\ReaplicacaoLicaoQuery`, único ponto
     * que faz isso em lote — nunca uma 2ª ordenação implícita aqui.
     */
    public function ultimaAvaliacao(): ?LicaoAprendidaReaplicacaoAvaliacao
    {
        if ($this->relationLoaded('avaliacoes')) {
            return $this->avaliacoes->first();
        }

        return $this->avaliacoes()->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function resultadoAtual(): ?ResultadoAvaliacaoReaplicacao
    {
        return $this->ultimaAvaliacao()?->resultado;
    }
}
