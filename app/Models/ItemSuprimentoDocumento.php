<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * Pivô N:N item_suprimento_documentos. Precisa existir como model (e ser
 * indicado via ->using() nas duas pontas) porque a chave é ULID sem valor
 * default no banco — attach()/sync() sem using() faz insert bruto que
 * pula os eventos de model (HasUlids/BelongsToTenant) e falha.
 */
class ItemSuprimentoDocumento extends Model
{
    use AsPivot, BelongsToTenant, HasUlids;

    protected $table = 'item_suprimento_documentos';

    protected $fillable = [
        'tenant_id',
        'item_suprimento_id',
        'documento_engenharia_id',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenharia::class, 'documento_engenharia_id');
    }
}
