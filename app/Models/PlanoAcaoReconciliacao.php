<?php

namespace App\Models;

use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de eventos de reconciliação de um PlanoAcao contra uma
 * nova importação (mesmo espírito de DocumentoEngenhariaReprogramacao) —
 * nunca editado depois de criado. Existe justamente pra "Agravado"/
 * "Alterado"/"Persistente"/"Resolvido" não precisarem virar status
 * permanentes do PlanoAcao (decisão do usuário, Fase 4).
 */
class PlanoAcaoReconciliacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'plano_acao_reconciliacoes';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'plano_acao_id',
        'cronograma_importacao_id',
        'resultado',
        'status_anterior',
        'status_novo',
        'uids_anteriores',
        'uids_atuais',
        'quantidade_anterior',
        'quantidade_atual',
        'impacto_anterior',
        'impacto_atual',
    ];

    protected $casts = [
        'resultado' => ResultadoReconciliacaoPlanoAcao::class,
        'status_anterior' => StatusPlanoAcao::class,
        'status_novo' => StatusPlanoAcao::class,
        'uids_anteriores' => 'array',
        'uids_atuais' => 'array',
        'quantidade_anterior' => 'integer',
        'quantidade_atual' => 'integer',
        'impacto_anterior' => 'float',
        'impacto_atual' => 'float',
    ];

    public function planoAcao(): BelongsTo
    {
        return $this->belongsTo(PlanoAcao::class);
    }

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }
}
