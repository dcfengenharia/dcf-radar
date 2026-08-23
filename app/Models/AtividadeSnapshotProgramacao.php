<?php

namespace App\Models;

use App\Enums\EventoFotografiaProgramacao;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 17, A.9.5 — Fotografia P: se uma atividade que iniciou/concluiu
 * NESTA importação (Fotografia F) fazia parte da Programação Semanal
 * historicamente aplicável ao instante desse evento. Irmã de F
 * (`AtividadeSnapshot`) e O (`AtividadeSnapshotOperacional`) — nunca as
 * altera, nunca é alterada por elas. `programacao_semanal_versao` é
 * denormalizado de propósito (sobrevive mesmo se `programacaoSemanal()`
 * virar `null` por uma edição/remoção futura do cabeçalho referenciado —
 * FK é `nullOnDelete()`, nunca `cascadeOnDelete()`).
 */
class AtividadeSnapshotProgramacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'atividade_snapshot_programacoes';

    protected $fillable = [
        'tenant_id',
        'cronograma_importacao_id',
        'atividade_id',
        'evento',
        'data_factual',
        'semana_inicio_resolvida',
        'programacao_semanal_id',
        'programacao_semanal_versao',
        'atividade_estava_na_programacao',
        'programacao_semanal_item_id',
    ];

    protected $casts = [
        'evento' => EventoFotografiaProgramacao::class,
        'data_factual' => 'date',
        'semana_inicio_resolvida' => 'date',
        'programacao_semanal_versao' => 'integer',
        'atividade_estava_na_programacao' => 'boolean',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class, 'atividade_id');
    }

    public function programacaoSemanal(): BelongsTo
    {
        return $this->belongsTo(ProgramacaoSemanal::class, 'programacao_semanal_id');
    }

    public function programacaoSemanalItem(): BelongsTo
    {
        return $this->belongsTo(ProgramacaoSemanalItem::class, 'programacao_semanal_item_id');
    }
}
