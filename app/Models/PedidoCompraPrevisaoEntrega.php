<?php

namespace App\Models;

use App\Enums\OrigemPrevisaoEntregaPedido;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etapa 3 — evento append-only: "em `registrado_em`, `registrado_por_id`
 * registrou que a previsão de entrega deste Pedido é `data_prevista`".
 * Único ponto de escrita: `App\Actions\Suprimentos\
 * AtualizarPrevisaoEntregaPedidoCompra` (revisões) e um bloco equivalente
 * dentro de `App\Actions\Suprimentos\EmitirPedidoCompra` (a linha
 * `Inicial`, criada no instante da emissão). Nunca editado/apagado —
 * `App\Observers\PedidoCompraPrevisaoEntregaObserver` bloqueia
 * `updating()`/`deleting()` incondicionalmente (mesmo padrão de
 * `RecebimentoPedidoObserver`/`RequisicaoCompraAdjudicacaoObserver`).
 */
class PedidoCompraPrevisaoEntrega extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'pedido_compra_previsoes_entrega';

    protected $fillable = [
        'tenant_id',
        'pedido_compra_id',
        'data_prevista',
        'origem',
        'registrado_por_id',
        'registrado_em',
        'motivo',
        'observacao',
    ];

    protected $casts = [
        'data_prevista' => 'date',
        'origem' => OrigemPrevisaoEntregaPedido::class,
        'registrado_em' => 'datetime',
    ];

    public function pedidoCompra(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_id');
    }
}
