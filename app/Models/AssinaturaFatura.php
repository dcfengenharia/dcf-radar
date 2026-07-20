<?php

namespace App\Models;

use App\Enums\StatusFatura;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de cobranças (Mercado Pago) — auditoria de quando e quanto
 * o tenant pagou, e âncora de idempotência do scheduler de renovação
 * Pix/Boleto (App\Services\CobrancaScheduler). Sem BelongsToTenant —
 * mesmo padrão de Assinatura/Impersonacao: tabela de nível de
 * plataforma, visível ao admin através de todos os tenants. Toda
 * consulta feita a partir de uma tela do TENANT precisa filtrar
 * tenant_id manualmente.
 */
class AssinaturaFatura extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'assinatura_faturas';

    protected $fillable = [
        'tenant_id',
        'assinatura_id',
        'metodo_pagamento',
        'valor',
        'status',
        'vencimento',
        'pago_em',
        'mp_payment_id',
        'mp_preapproval_id',
        'link_pagamento',
        'qr_code',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'status' => StatusFatura::class,
        'vencimento' => 'date',
        'pago_em' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assinatura(): BelongsTo
    {
        return $this->belongsTo(Assinatura::class);
    }
}
