<?php

namespace App\Support\Suprimentos;

use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRecebimentoItem;
use App\Enums\StatusRequisicaoPlanejamento;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoPlanejamentoItem;
use Illuminate\Support\Collection;

/**
 * Ciclo 19, Etapa 19.6 — conciliação quantitativa da camada Pedido ×
 * Recebimento Físico. Tudo DERIVADO, nunca persistido — mesma filosofia
 * de `ConciliacaoTakeOff`/`ConciliacaoAlocacao`. Toda entrada aceita
 * coleção já carregada pelo chamador; as agregações em lote usam 1 query
 * `GROUP BY`, nunca 1 SUM por linha em loop.
 */
class ConciliacaoRecebimento
{
    /**
     * @param  Collection<int, PedidoCompraItem>  $itens
     * @return Collection<string, array> chave = pedido_compra_item_id
     */
    public static function porPedidoItens(Collection $itens): Collection
    {
        $ids = $itens->pluck('id')->all();

        $recebido = empty($ids)
            ? collect()
            : RecebimentoPedido::query()
                ->whereIn('pedido_compra_item_id', $ids)
                ->groupBy('pedido_compra_item_id')
                ->selectRaw('pedido_compra_item_id, SUM(quantidade_recebida) as total')
                ->pluck('total', 'pedido_compra_item_id');

        return $itens->map(function (PedidoCompraItem $item) use ($recebido) {
            $pedida = (float) $item->quantidade_pedida;
            $recebida = round((float) ($recebido[$item->id] ?? 0), 3);
            $saldo = round($pedida - $recebida, 3);
            $percentual = $pedida > 0 ? round(min(100, ($recebida / $pedida) * 100), 2) : ($recebida > 0 ? 100.0 : 0.0);

            return [
                'pedido_compra_item_id' => $item->id,
                'quantidade_pedida' => $pedida,
                'quantidade_recebida' => $recebida,
                'saldo' => $saldo,
                'percentual_recebido' => $percentual,
                'status' => self::statusPara($recebida, $pedida),
            ];
        })->keyBy('pedido_compra_item_id');
    }

    public static function porPedidoItem(PedidoCompraItem $item): array
    {
        return self::porPedidoItens(collect([$item]))->get($item->id);
    }

    /**
     * Resumo de um Pedido: quantos itens não recebidos/parciais/completos,
     * situação de entrega, atraso atual/final.
     */
    public static function porPedido(PedidoCompra $pedido): array
    {
        $itens = $pedido->itens()->get();
        $conciliacao = self::porPedidoItens($itens);

        $completos = $parciais = $naoRecebidos = 0;
        foreach ($conciliacao as $linha) {
            match ($linha['status']) {
                StatusRecebimentoItem::Recebido => $completos++,
                StatusRecebimentoItem::ParcialmenteRecebido => $parciais++,
                default => $naoRecebidos++,
            };
        }

        return [
            'total_itens' => $itens->count(),
            'itens_nao_recebidos' => $naoRecebidos,
            'itens_parciais' => $parciais,
            'itens_completos' => $completos,
            'situacao' => $pedido->situacaoEntrega(),
            'dias_atraso_atual' => $pedido->diasAtrasoAtual(),
            'dias_atraso_final' => $pedido->diasAtrasoFinal(),
            'data_entrega_completa' => $pedido->dataEntregaCompleta(),
        ];
    }

    /**
     * Resumo de recebimento de um Pacote de Compra: agrega TODOS os
     * Pedidos de TODAS as RCs formais (Emitida/Concluida — Rascunho nunca
     * representa demanda formal) do Pacote. Contagem de itens (nunca soma
     * de quantidade entre unidades incompatíveis).
     */
    public static function porPacote(ItemSuprimento $pacote): array
    {
        // necessidade()/dataProjetadaAtendimento()/folgaAtendimento() (em
        // ItemSuprimento) leem $this->atividades e
        // $this->requisicoesCompra->pedidos — nunca confiar em lazy load
        // (bloqueado em não-produção): carrega tudo em 1 lote antes.
        $pacote->loadMissing(['atividades', 'requisicoesCompra.pedidos']);

        // Ciclo 19, Etapa 19.7 — achado de performance (pré-existente desde
        // 19.6, exposto em escala pelo Command diário desta etapa):
        // `PedidoCompraItem::statusRecebimento()`/`quantidadeRecebida()`
        // faz fallback pra uma query própria quando `recebimentos` não
        // está eager-loaded — sem `itens.recebimentos` aqui, cada item
        // (chamado 2x: uma vez neste loop, outra dentro de
        // `PedidoCompra::diasAtrasoAtual()`) disparava sua PRÓPRIA query.
        $pedidos = PedidoCompra::query()
            ->whereHas('requisicaoCompra', fn ($q) => $q->where('item_suprimento_id', $pacote->id))
            ->where('status', StatusPedidoCompra::Emitido->value)
            ->with('itens.recebimentos')
            ->get();

        $completos = $parciais = $naoRecebidos = 0;
        $algumAtrasado = false;

        foreach ($pedidos as $pedido) {
            foreach ($pedido->itens as $item) {
                match ($item->statusRecebimento()) {
                    StatusRecebimentoItem::Recebido => $completos++,
                    StatusRecebimentoItem::ParcialmenteRecebido => $parciais++,
                    default => $naoRecebidos++,
                };
            }

            if ($pedido->diasAtrasoAtual() !== null) {
                $algumAtrasado = true;
            }
        }

        $necessidade = $pacote->necessidade();
        $atendimento = $pacote->dataProjetadaAtendimento();
        $folga = $pacote->folgaAtendimento();

        return [
            'total_pedidos' => $pedidos->count(),
            'itens_nao_recebidos' => $naoRecebidos,
            'itens_parciais' => $parciais,
            'itens_completos' => $completos,
            'necessidade' => $necessidade,
            'atendimento_projetado' => $atendimento,
            'folga' => $folga,
            'risco' => self::riscoPara($folga),
            'algum_pedido_atrasado' => $algumAtrasado,
        ];
    }

    /**
     * Cadeia quantitativa completa — TakeOff previsto -> RP Emitida ->
     * Pacote alocado -> RC formal -> Pedido Emitido -> Recebido
     * fisicamente — pra UM `ItemTakeOff`. Uso pontual (tela de detalhe),
     * nunca em listagem em massa. Só considera RP Emitida, RC
     * Emitida/Concluida e Pedido Emitido em cada estágio (mesmo princípio
     * "rascunho nunca é demanda oficial" já estabelecido em toda a
     * cadeia).
     */
    public static function cadeiaCompletaPorItemTakeOff(ItemTakeOff $itemTakeOff): array
    {
        $previsto = (float) $itemTakeOff->quantidade;

        $requisitado = (float) RequisicaoPlanejamentoItem::query()
            ->where('item_take_off_id', $itemTakeOff->id)
            ->whereHas('requisicao', fn ($q) => $q->where('status', StatusRequisicaoPlanejamento::Emitida->value))
            ->sum('quantidade_requisitada');

        $rpItemIds = RequisicaoPlanejamentoItem::query()
            ->where('item_take_off_id', $itemTakeOff->id)
            ->pluck('id');

        $alocado = (float) \App\Models\AlocacaoRequisicaoPacote::query()
            ->whereIn('requisicao_planejamento_item_id', $rpItemIds)
            ->sum('quantidade_alocada');

        $alocacaoIds = \App\Models\AlocacaoRequisicaoPacote::query()
            ->whereIn('requisicao_planejamento_item_id', $rpItemIds)
            ->pluck('id');

        $emRc = (float) RequisicaoCompraItem::query()
            ->whereIn('alocacao_requisicao_pacote_id', $alocacaoIds)
            ->whereHas('requisicaoCompra', fn ($q) => $q->whereIn('status', [
                \App\Enums\StatusRequisicaoCompra::Emitida->value,
                \App\Enums\StatusRequisicaoCompra::Concluida->value,
            ]))
            ->sum('quantidade');

        $rcItemIds = RequisicaoCompraItem::query()
            ->whereIn('alocacao_requisicao_pacote_id', $alocacaoIds)
            ->pluck('id');

        $emPedido = (float) PedidoCompraItem::query()
            ->whereIn('requisicao_compra_item_id', $rcItemIds)
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value))
            ->sum('quantidade_pedida');

        $pedidoItemIds = PedidoCompraItem::query()
            ->whereIn('requisicao_compra_item_id', $rcItemIds)
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value))
            ->pluck('id');

        $recebido = (float) RecebimentoPedido::query()
            ->whereIn('pedido_compra_item_id', $pedidoItemIds)
            ->sum('quantidade_recebida');

        return [
            'item_take_off_id' => $itemTakeOff->id,
            'previsto' => round($previsto, 3),
            'requisitado' => round($requisitado, 3),
            'saldo_a_requisitar' => round($previsto - $requisitado, 3),
            'alocado' => round($alocado, 3),
            'saldo_a_alocar' => round($requisitado - $alocado, 3),
            'em_rc' => round($emRc, 3),
            'saldo_a_colocar_em_rc' => round($alocado - $emRc, 3),
            'em_pedido' => round($emPedido, 3),
            'saldo_a_colocar_em_pedido' => round($emRc - $emPedido, 3),
            'recebido' => round($recebido, 3),
            'saldo_a_receber' => round($emPedido - $recebido, 3),
        ];
    }

    private static function statusPara(float $recebida, float $pedida): StatusRecebimentoItem
    {
        if ($recebida <= 0.0005) {
            return StatusRecebimentoItem::NaoRecebido;
        }

        if ($recebida >= $pedida - 0.0005) {
            return StatusRecebimentoItem::Recebido;
        }

        return StatusRecebimentoItem::ParcialmenteRecebido;
    }

    private static function riscoPara(?int $folga): string
    {
        if ($folga === null) {
            return 'indefinido';
        }

        return $folga < 0 ? 'em_risco' : 'dentro_do_prazo';
    }
}
