<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 18, Etapa 18.5.6 — ledger append-only de idempotência por CANAL
 * EXTERNO (mail/whatsapp) dos alertas de distribuição GRD. `evento_usuario_id`
 * é o MESMO UUIDv5 determinístico usado como `notifications.id`
 * (App\Support\Grd\AlertaDistribuicaoGrd::idAlerta()) — nunca uma segunda
 * identidade. Criado exclusivamente pelos 2 channels wrapper
 * (App\Notifications\Channels\GrdLedgerMailChannel/GrdLedgerZApiChannel),
 * sempre DEPOIS de uma tentativa de envio sem exceção.
 */
class GrdAlertaEntrega extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'grd_alerta_entregas';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'usuario_id',
        'tipo_alerta',
        'documento_engenharia_id',
        'revisao_id',
        'evento_usuario_id',
        'canal',
        'enviado_em',
    ];

    protected $casts = [
        'enviado_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
