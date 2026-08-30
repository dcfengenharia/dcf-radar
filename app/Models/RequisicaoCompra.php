<?php

namespace App\Models;

use App\Enums\StatusRequisicaoCompra;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ciclo 19, Etapa 19.4 — cabeçalho da Requisição de Compra (RC): filha
 * de exatamente 1 Pacote de Compra (`ItemSuprimento`), nunca cruza
 * Pacotes. Domínio 100% novo e paralelo ao mecanismo legado do Pacote
 * (fluxo_suprimento_id/etapas()/status via SuprimentoScheduler) — os
 * dois nunca se sincronizam (decisão do usuário, ver CLAUDE.md).
 */
class RequisicaoCompra extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'requisicoes_compra';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'item_suprimento_id',
        'numero',
        'status',
        'fluxo_suprimento_id',
        'fluxo_nome_snapshot',
        'observacao',
        'created_by_id',
        'emitida_em',
        'emitida_por',
        'concluida_em',
    ];

    protected $casts = [
        'numero' => 'integer',
        'status' => StatusRequisicaoCompra::class,
        'emitida_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function fluxo(): BelongsTo
    {
        return $this->belongsTo(FluxoSuprimento::class, 'fluxo_suprimento_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function emitidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(RequisicaoCompraItem::class);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(RequisicaoCompraEtapa::class)->orderBy('ordem');
    }

    /** Ciclo 19, Etapa 19.5 — Pedidos/Ordens de Compra gerados a partir desta RC. */
    public function pedidos(): HasMany
    {
        return $this->hasMany(PedidoCompra::class);
    }

    public function estaRascunho(): bool
    {
        return $this->status === StatusRequisicaoCompra::Rascunho;
    }

    public function estaEmitida(): bool
    {
        return $this->status === StatusRequisicaoCompra::Emitida;
    }

    public function estaConcluida(): bool
    {
        return $this->status === StatusRequisicaoCompra::Concluida;
    }

    /**
     * "Risco de atendimento" — nunca "impacto no cronograma" (terminologia
     * explícita do pedido). Fim previsto do processo = última etapa (por
     * ordem), preferindo o realizado quando já existe (estimativa mais
     * precisa que a data congelada na emissão).
     */
    public function fimPrevisto(): ?Carbon
    {
        $ultima = $this->etapas->sortByDesc('ordem')->first();

        if (! $ultima) {
            return null;
        }

        return $ultima->data_realizada ?? $ultima->data_prevista;
    }

    /**
     * Ciclo 19, Etapa 19.5 — "data projetada de atendimento" desta RC
     * (decisão do usuário, regra por RC): a MAIOR `data_prevista_entrega`
     * entre os Pedidos `Emitido` desta RC (Pedido em Rascunho NUNCA
     * entra nessa conta) — a melhor previsão comercial concreta que
     * existe. Sem nenhum Pedido Emitido ainda, cai pra `fimPrevisto()`
     * (fim do processo de aquisição) como fallback provisório.
     *
     * `fimPrevisto()` continua existindo e sendo exibida separadamente
     * como previsão do PROCESSO de aquisição — uma nunca substitui nem
     * apaga a outra.
     */
    public function dataProjetadaAtendimento(): ?Carbon
    {
        $maiorEntregaPedidoEmitido = $this->pedidos
            ->where('status', \App\Enums\StatusPedidoCompra::Emitido)
            ->pluck('data_prevista_entrega')
            ->filter()
            ->max();

        return $maiorEntregaPedidoEmitido ?? $this->fimPrevisto();
    }
}
