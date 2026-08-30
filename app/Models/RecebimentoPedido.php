<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 19, Etapa 19.6 (imutabilidade real desde 19.6.CORREÇÃO) — evento
 * append-only de recebimento físico de material sobre um
 * `PedidoCompraItem`. Criado exclusivamente por
 * `App\Actions\Suprimentos\RegistrarRecebimentoPedido`.
 *
 * **Imutabilidade — achado C1 da auditoria adversarial, corrigido em
 * 19.6.CORREÇÃO**: a versão original só bloqueava `deleting()` — `save()`/
 * `update()` de instância passavam sem exceção, reescrevendo o fato
 * físico sem trilha. `App\Observers\RecebimentoPedidoObserver` agora
 * bloqueia `updating()` E `deleting()` incondicionalmente — nenhum campo
 * (`quantidade_recebida`/`recebido_em`/`registrado_por`/
 * `local_recebimento`/`observacao`) é alterável depois de criado.
 * **Limitação estrutural residual, documentada e aceita**: Query Builder
 * cru (`DB::table('recebimentos_pedido')->update(...)`) e mass update
 * Eloquent (`RecebimentoPedido::where(...)->update(...)`) bypassam
 * Observers por natureza do framework — nenhum writer de produção usa
 * qualquer uma das duas formas (grep exaustivo, 19.6.CORREÇÃO); ambas são
 * API PROIBIDA por convenção arquitetural pra esta entidade, não por
 * trigger de banco (nenhum motivo real justificou essa complexidade).
 *
 * `recebido_em` é a data do FATO físico — retroativa é sempre permitida,
 * futura é BLOQUEADA na Action (19.6.CORREÇÃO — `RecebimentoPedido`
 * nunca representa programação, só ocorrência já acontecida). A
 * CRONOLOGIA de `recebido_em` (não `created_at`/`id`) é o critério
 * PRIMÁRIO usado por `PedidoCompraItem::dataConclusaoRecebimento()` pra
 * decidir qual evento "completou" o item — `created_at`/`id` servem só
 * de desempate determinístico entre eventos do MESMO dia, nunca decidem
 * a data em si.
 */
class RecebimentoPedido extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'recebimentos_pedido';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'pedido_compra_item_id',
        'quantidade_recebida',
        'recebido_em',
        'registrado_por',
        'local_recebimento',
        'observacao',
    ];

    protected $casts = [
        'quantidade_recebida' => 'decimal:3',
        'recebido_em' => 'date',
    ];

    public function pedidoCompraItem(): BelongsTo
    {
        return $this->belongsTo(PedidoCompraItem::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
