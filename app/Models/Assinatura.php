<?php

namespace App\Models;

use App\Enums\StatusAssinatura;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ATENÇÃO: este model tem `tenant_id` mas NÃO usa BelongsToTenant de
 * propósito — o dono da plataforma precisa enxergar as assinaturas de
 * TODOS os tenants ao mesmo tempo. Se este trait for adicionado aqui, o
 * TenantScope global vai filtrar a query pelo tenant que estiver no
 * TenantContext no momento, escondendo os outros tenants da área
 * administrativa (o oposto do que essa tela precisa fazer).
 *
 * Uma conta pode ter várias linhas ao longo do tempo — cada troca de
 * plano ou de status cria uma linha nova em vez de mutar a antiga, então
 * esta tabela já é o próprio histórico. A assinatura vigente é sempre a
 * mais recente por `inicio`.
 */
class Assinatura extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'plano_id',
        'status',
        'inicio',
        'fim_trial',
        'cancelada_em',
        'motivo_cancelamento',
    ];

    protected $casts = [
        'status' => StatusAssinatura::class,
        'inicio' => 'date',
        'fim_trial' => 'date',
        'cancelada_em' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function estaAtiva(): bool
    {
        return $this->status->concedeAcesso();
    }
}
