<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fechamento Adversarial Etapa 3 (Seções 6-22) — ponte EXPLÍCITA e
 * append-only entre um `RecebimentoPedido` (fato físico, granularidade
 * de `PedidoCompraItem`) e a `PedidoCompraItemParcela` (destino lógico,
 * granularidade de necessidade) que aquela quantidade efetivamente
 * atendeu. Único ponto de escrita: `App\Actions\Suprimentos\
 * DistribuirRecebimentoPedidoPorParcela` — nunca criado/editado direto
 * por UI/Livewire.
 *
 * **Nunca proporcional/inferida** — sempre uma escolha EXPLÍCITA de
 * quem concilia o recebimento (mesmo princípio já usado em
 * `AtualizarDistribuicaoParcelaPedidoCompra`, Rastreabilidade
 * Quantitativa Etapa 1). Ausência de distribuição pra um recebimento é
 * um estado válido e permanente — nunca inferida automaticamente.
 *
 * **Editável/excluível SÓ enquanto "aberto"** (`App\Observers\
 * RecebimentoPedidoParcelaObserver`, mesmo mecanismo "aberto/fechado"
 * derivado — nunca uma coluna de status — já usado por
 * `App\Models\AplicacaoMaterialEstoque`, Ciclo 20.4): "aberto" =
 * `SUM(distribuições do recebimento) < quantidade_recebida` daquele
 * evento. Assim que fecha (100% distribuído), NENHUMA distribuição
 * daquele recebimento pode ser criada/editada/excluída — nem mesmo pra
 * "corrigir" um erro (decisão explícita: reabrir uma conciliação
 * fechada não é permitido). Identidade (`recebimento_pedido_id`/
 * `pedido_compra_item_parcela_id`/`tenant_id`/`created_by_id`) nunca é
 * reatribuível via `update()`, mesmo enquanto aberto — só `quantidade`.
 */
class RecebimentoPedidoParcela extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'recebimento_pedido_parcelas';

    protected $fillable = [
        'tenant_id',
        'recebimento_pedido_id',
        'pedido_compra_item_parcela_id',
        'quantidade',
        'created_by_id',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
    ];

    public function recebimentoPedido(): BelongsTo
    {
        return $this->belongsTo(RecebimentoPedido::class);
    }

    public function pedidoCompraItemParcela(): BelongsTo
    {
        return $this->belongsTo(PedidoCompraItemParcela::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
