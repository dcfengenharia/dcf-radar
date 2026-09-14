<?php

namespace App\Models;

use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Etapa 2 — cabeçalho de UMA decisão comercial: "o Fornecedor X ganhou
 * determinada(s) quantidade(s) desta RC, decidido por Fulano, em tal
 * data, com tal justificativa". Nunca um "vencedor único da RC" (Seção
 * 3) — várias linhas desta tabela, cada uma com seu próprio fornecedor,
 * podem coexistir pra UMA mesma RC.
 *
 * Só existe sobre uma RC já `Emitida`/`Concluída` — nunca Rascunho
 * (`App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra`), mesma
 * fronteira de lifecycle já usada por `CriarPedidoCompra` (a quantidade
 * de `RequisicaoCompraItem` só é real/congelada a partir da emissão).
 *
 * `status` (Ativa|Cancelada) é o único mecanismo de "reconsideração" —
 * nunca deletada de verdade (`App\Observers\
 * RequisicaoCompraAdjudicacaoObserver` bloqueia delete/forceDelete
 * incondicionalmente).
 */
class RequisicaoCompraAdjudicacao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'requisicao_compra_adjudicacoes';

    protected $fillable = [
        'tenant_id',
        'requisicao_compra_id',
        'fornecedor_id',
        'status',
        'decidido_por_id',
        'decidido_em',
        'justificativa',
        'observacao',
        'anexo_id',
        'cancelado_por_id',
        'cancelado_em',
        'motivo_cancelamento',
    ];

    protected $casts = [
        'status' => StatusAdjudicacaoRequisicaoCompra::class,
        'decidido_em' => 'datetime',
        'cancelado_em' => 'datetime',
    ];

    public function requisicaoCompra(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompra::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por_id');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por_id');
    }

    public function anexo(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompraAnexo::class, 'anexo_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(RequisicaoCompraAdjudicacaoItem::class, 'requisicao_compra_adjudicacao_id');
    }

    public function estaAtiva(): bool
    {
        return $this->status === StatusAdjudicacaoRequisicaoCompra::Ativa;
    }
}
