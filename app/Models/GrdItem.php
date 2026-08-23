<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 18, Etapa 18.5.1 — item de uma GRD: uma revisão EXATA
 * (documento_engenharia_revisao_id, PK fixa — nunca a revisão vigente
 * resolvida dinamicamente) incluída num cabeçalho de GRD. Ver docblock da
 * migration `create_grd_itens_table` para a justificativa de FK.
 */
class GrdItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'grd_itens';

    protected $fillable = [
        'tenant_id',
        'grd_id',
        'documento_engenharia_revisao_id',
        'codigo_documento_snapshot',
        'descricao_documento_snapshot',
        'revisao_snapshot',
    ];

    public function grd(): BelongsTo
    {
        return $this->belongsTo(Grd::class);
    }

    public function revisao(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenhariaRevisao::class, 'documento_engenharia_revisao_id');
    }

    public function distribuicoes(): HasMany
    {
        return $this->hasMany(GrdDistribuicao::class);
    }
}
