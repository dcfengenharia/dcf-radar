<?php

namespace App\Models;

use App\Enums\SeveridadeSituacao;
use App\Enums\StatusSituacaoOcorrencia;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 21, Etapa 21.3 — ciclo de vida de UM fenômeno gerencial
 * (identificado por `chave_logica`, ver `App\DTOs\Gestao\
 * SituacaoGerencial::$chaveLogica`), escrita EXCLUSIVAMENTE por
 * `App\Support\Gestao\SincronizarSituacoesGerenciais` — nunca CRUD de
 * usuário, nunca uma segunda fonte da verdade operacional (essa
 * continua sendo `App\Support\Gestao\SituacoesGerenciaisQuery`, sempre
 * recalculada do zero a cada sincronização).
 */
class SituacaoOcorrencia extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'situacao_ocorrencias';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'tipo',
        'chave_logica',
        'status',
        'episodio',
        'severidade_atual',
        'severidade_peso_comunicado',
        'entidade_tipo',
        'entidade_id',
        'descricao_atual',
        'contexto_atual',
        'primeira_deteccao_em',
        'ultima_deteccao_em',
        'resolvida_em',
        'ultimo_email_em',
    ];

    protected $casts = [
        'tipo' => TipoSituacaoGerencial::class,
        'status' => StatusSituacaoOcorrencia::class,
        'severidade_atual' => SeveridadeSituacao::class,
        'episodio' => 'integer',
        'severidade_peso_comunicado' => 'integer',
        'contexto_atual' => 'array',
        'primeira_deteccao_em' => 'datetime',
        'ultima_deteccao_em' => 'datetime',
        'resolvida_em' => 'datetime',
        'ultimo_email_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    /** Número de reaberturas — sempre derivado, nunca coluna própria. */
    public function vezesReaberta(): int
    {
        return max(0, $this->episodio - 1);
    }

    public function estaAtiva(): bool
    {
        return $this->status === StatusSituacaoOcorrencia::Ativa;
    }
}
