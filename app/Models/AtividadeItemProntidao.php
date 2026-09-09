<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtividadeItemProntidao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'atividade_itens_prontidao';

    protected $fillable = [
        'tenant_id',
        'atividade_id',
        'item_prontidao_id',
        'concluido',
        'concluido_por',
        'concluido_em',
        'atendido_pela_importacao_id',
    ];

    protected $casts = [
        'concluido'    => 'boolean',
        'concluido_em' => 'datetime',
    ];

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemProntidao::class, 'item_prontidao_id');
    }

    public function conclusor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }

    /**
     * Ciclo 24 — importação de avanço que reconciliou este item
     * automaticamente (atividade reportada como fisicamente concluída).
     * `null` = marcação manual (via `⚡restricoes.blade.php`, que sempre
     * preenche `concluido_por`) ou item ainda pendente.
     */
    public function importacaoQueAtendeu(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'atendido_pela_importacao_id');
    }

    /**
     * Ciclo 24 — nunca confundir "atendido automaticamente pela importação"
     * com uma marcação humana: os dois campos são mutuamente exclusivos por
     * construção (`concluido_por` sempre null quando este id está
     * preenchido, e vice-versa).
     */
    public function foiAtendidoAutomaticamente(): bool
    {
        return $this->concluido && $this->atendido_pela_importacao_id !== null;
    }
}
