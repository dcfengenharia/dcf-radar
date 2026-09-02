<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Ciclo 21, Etapa 21.4 — ledger append-only de entrega por canal EXTERNO
 * (generalização de `GrdAlertaEntrega`, Ciclo 18.5.6). Escrito
 * EXCLUSIVAMENTE por `App\Notifications\Channels\SituacaoLedgerMailChannel`,
 * sempre DEPOIS de uma tentativa de envio sem exceção — nunca antes.
 */
class SituacaoComunicacaoEntrega extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'situacao_comunicacao_entregas';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'usuario_id',
        'evento_usuario_id',
        'canal',
        'enviado_em',
    ];

    protected $casts = [
        'enviado_em' => 'datetime',
    ];
}
