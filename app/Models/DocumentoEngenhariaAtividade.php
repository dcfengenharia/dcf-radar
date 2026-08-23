<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * Ciclo 18, Etapa 18.1 — pivô N:N documento_engenharia_atividades. Precisa
 * existir como model (indicado via ->using() nas duas pontas) porque a
 * chave é ULID sem valor default no banco — attach()/sync() sem using()
 * faz insert bruto que pula os eventos de model (HasUlids/BelongsToTenant)
 * e falha. Mesmo padrão de ItemSuprimentoAtividade/ItemSuprimentoDocumento.
 */
class DocumentoEngenhariaAtividade extends Model
{
    use AsPivot, BelongsToTenant, HasUlids;

    protected $table = 'documento_engenharia_atividades';

    protected $fillable = [
        'tenant_id',
        'documento_engenharia_id',
        'atividade_id',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenharia::class, 'documento_engenharia_id');
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }
}
