<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 17, A.9.3 — Fotografia O: 1 linha por item de prontidão que estava
 * PENDENTE (sem row `atividade_itens_prontidao` com concluido=true — mesma
 * regra canônica de Atividade::estaPronta()) no instante de uma importação
 * Avanço/Ambos. Nunca todos os itens — só os pendentes naquele momento.
 * `atividade_item_prontidao_id` é nullable: preserva o ID da row real
 * quando ela existia (ex.: concluido=false explícito), fica null quando a
 * atividade nunca teve nenhuma row pra esse item (ausência de row também é
 * "pendente", mesma semântica já usada em toda a Central de Prontidão).
 */
class AtividadeSnapshotProntidao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'atividade_snapshot_prontidao';

    protected $fillable = [
        'tenant_id',
        'cronograma_importacao_id',
        'atividade_id',
        'item_prontidao_id',
        'atividade_item_prontidao_id',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class, 'atividade_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemProntidao::class, 'item_prontidao_id');
    }

    public function registroOriginal(): BelongsTo
    {
        return $this->belongsTo(AtividadeItemProntidao::class, 'atividade_item_prontidao_id');
    }
}
