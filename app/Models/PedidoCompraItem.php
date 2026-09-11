<?php

namespace App\Models;

use App\Enums\StatusRecebimentoItem;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 19, Etapa 19.5 — linha de consumo de um Pedido sobre um
 * `RequisicaoCompraItem`. Origem canônica de descrição/unidade/lista/
 * documento continua sendo `requisicaoCompraItem` (ao vivo, congelada
 * em snapshot próprio só na emissão do Pedido).
 *
 * Ciclo 19, Etapa 19.6 — `quantidade_pedida` é o compromisso comercial
 * original e NUNCA é sobrescrita por "quantidade recebida" — o
 * recebimento físico é sempre um evento separado (`recebimentos()`),
 * nunca um campo desta linha.
 */
class PedidoCompraItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'pedido_compra_itens';

    protected $fillable = [
        'tenant_id',
        'pedido_compra_id',
        'requisicao_compra_item_id',
        'quantidade_pedida',
        'codigo_item_snapshot',
        'descricao_snapshot',
        'unidade_snapshot',
        'lista_codigo_snapshot',
        'tipo_lista_snapshot',
        'documento_codigo_snapshot',
        'revisao_snapshot',
    ];

    protected $casts = [
        'quantidade_pedida' => 'decimal:3',
    ];

    public function pedidoCompra(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class);
    }

    public function requisicaoCompraItem(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompraItem::class);
    }

    /** Rastreabilidade Quantitativa, Etapa 1 — distribuição deste item por Atividade (parcelas de necessidade). */
    public function parcelas(): HasMany
    {
        return $this->hasMany(PedidoCompraItemParcela::class, 'pedido_compra_item_id');
    }

    /**
     * Ciclo 19, Etapa 19.6 — ordenado pela ORDEM DE REGISTRO (created_at/id
     * desc, nunca `recebido_em` — mesmo princípio de `GrdDistribuicao::
     * recolhimentos()`, 18.5.1).
     */
    public function recebimentos(): HasMany
    {
        return $this->hasMany(RecebimentoPedido::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Soma de todos os eventos de recebimento — dual-path (mesmo padrão de
     * `GrdDistribuicao::quantidadeRecolhida()`): usa a relação já carregada
     * quando disponível (evita N+1 em listagens/conciliação em lote), cai
     * pra uma query direta quando chamado isoladamente.
     */
    public function quantidadeRecebida(): float
    {
        return (float) ($this->relationLoaded('recebimentos')
            ? $this->recebimentos->sum('quantidade_recebida')
            : $this->recebimentos()->sum('quantidade_recebida'));
    }

    public function saldoAReceber(): float
    {
        return round((float) $this->quantidade_pedida - $this->quantidadeRecebida(), 3);
    }

    public function percentualRecebido(): float
    {
        $pedida = (float) $this->quantidade_pedida;
        if ($pedida <= 0) {
            return 0.0;
        }

        return round(min(100, ($this->quantidadeRecebida() / $pedida) * 100), 2);
    }

    /** Status DERIVADO (nunca persistido) — ver App\Enums\StatusRecebimentoItem. */
    public function statusRecebimento(): StatusRecebimentoItem
    {
        $recebida = $this->quantidadeRecebida();

        if ($recebida <= 0.0005) {
            return StatusRecebimentoItem::NaoRecebido;
        }

        if ($recebida >= (float) $this->quantidade_pedida - 0.0005) {
            return StatusRecebimentoItem::Recebido;
        }

        return StatusRecebimentoItem::ParcialmenteRecebido;
    }

    public function primeiraEntregaEm(): ?Carbon
    {
        return $this->recebimentosOrdenadosPorData()->first()?->recebido_em;
    }

    public function ultimaEntregaEm(): ?Carbon
    {
        return $this->recebimentosOrdenadosPorData()->last()?->recebido_em;
    }

    /**
     * Data em que este item foi EFETIVAMENTE completado, na CRONOLOGIA
     * FÍSICA real (Ciclo 19, Etapa 19.6.CORREÇÃO — achado C2 da auditoria
     * adversarial): "em que data a soma dos recebimentos ACUMULADOS
     * atingiu `quantidade_pedida`?" é uma pergunta sobre a linha do tempo
     * FÍSICA dos fatos (`recebido_em`), nunca sobre a ordem
     * ADMINISTRATIVA em que foram digitados no sistema — a versão
     * anterior usava `created_at`/`id` como critério PRIMÁRIO (copiado,
     * incorretamente, do princípio de `GrdDistribuicao::estado()`, que
     * protege o ESTADO ATUAL contra reescrita retroativa — uma pergunta
     * diferente desta). Confirmado empiricamente que isso produzia uma
     * data de conclusão ERRADA sob backdating fora de ordem de registro
     * (10/10=40 registrado 1º, 12/10=30 registrado 2º, 11/10=30
     * backdatado registrado 3º — cronologia física conclui em 12/10,
     * a versão anterior retornava 11/10).
     *
     * Ordena por `recebido_em ASC` (critério PRIMÁRIO — a cronologia
     * física real), com `created_at ASC, id ASC` só como DESEMPATE
     * determinístico entre eventos do MESMO dia (nunca decide a DATA de
     * conclusão — só a ordem interna de acumulação dentro do mesmo dia,
     * o que nunca muda qual dia é o dia da conclusão). `null` se o item
     * nunca chegou a ser completado.
     */
    public function dataConclusaoRecebimento(): ?Carbon
    {
        $pedida = (float) $this->quantidade_pedida;
        $acumulado = 0.0;

        // Nunca via $this->recebimentos() — a relação já carrega
        // ->orderByDesc(...) embutido, e chamar ->orderBy() em cima só
        // ACRESCENTA colunas de ordenação (nunca substitui as existentes),
        // deixando o DESC original dominante. Query fresh e independente
        // aqui, sempre em cronologia física ASCENDENTE.
        $eventosPorCronologia = $this->relationLoaded('recebimentos')
            ? $this->recebimentos->sortBy([['recebido_em', 'asc'], ['created_at', 'asc'], ['id', 'asc']])
            : RecebimentoPedido::where('pedido_compra_item_id', $this->id)
                ->orderBy('recebido_em')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

        foreach ($eventosPorCronologia as $evento) {
            $acumulado += (float) $evento->quantidade_recebida;
            if ($acumulado >= $pedida - 0.0005) {
                return $evento->recebido_em;
            }
        }

        return null;
    }

    private function recebimentosOrdenadosPorData(): \Illuminate\Support\Collection
    {
        $eventos = $this->relationLoaded('recebimentos') ? $this->recebimentos : $this->recebimentos()->get();

        return $eventos->sortBy(fn (RecebimentoPedido $r) => $r->recebido_em?->timestamp);
    }
}
