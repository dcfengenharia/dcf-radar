<?php

namespace App\Models;

use App\Enums\TipoAceiteGrd;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 18, Etapa 18.5.9 — evidência histórica e imutável de aceite/
 * assinatura de recebimento de UM `GrdDestinatario` (ver docblock da
 * migration `create_grd_aceites_entrega_table` pra cardinalidade,
 * garantia estrutural de unicidade e semântica de `token`). NÃO é
 * assinatura digital ICP-Brasil.
 *
 * `invalidado_em`/`invalidado_por`/`motivo_invalidacao` são a ÚNICA
 * mutação permitida depois da criação — deliberadamente FORA de
 * `$fillable` (só `App\Actions\Engenharia\InvalidarAceiteEntrega` grava
 * esses 3 campos, via `update()` condicional atômico, nunca mass
 * assignment). Todo o resto (nome/empresa/setor/tipo/assinatura/
 * ocorrido_em) nunca muda depois de criado.
 */
class GrdAceiteEntrega extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'grd_aceites_entrega';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'grd_destinatario_id',
        'nome_recebedor_snapshot',
        'empresa_snapshot',
        'setor_snapshot',
        'tipo_aceite',
        'assinatura_path',
        'assinatura_hash',
        'token',
        'registrado_por',
        'ocorrido_em',
        'observacao',
        'created_at',
    ];

    protected $casts = [
        'tipo_aceite' => TipoAceiteGrd::class,
        'ocorrido_em' => 'datetime',
        'invalidado_em' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function grdDestinatario(): BelongsTo
    {
        return $this->belongsTo(GrdDestinatario::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function invalidadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidado_por');
    }

    public function estaAtivo(): bool
    {
        return $this->invalidado_em === null;
    }
}
