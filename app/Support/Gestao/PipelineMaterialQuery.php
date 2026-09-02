<?php

namespace App\Support\Gestao;

use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRequisicaoCompra;
use App\Enums\StatusRequisicaoPlanejamento;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\ItemTakeOff;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoPlanejamentoItem;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.1 — representação gerencial reutilizável do
 * pipeline de materiais: Necessidade → Requisitado → Alocado → Em RC →
 * Em Pedido → Recebido → Físico → Reservado → Disponível → Aplicado →
 * Déficit, agregada por MATERIAL e escopada por OBRA.
 *
 * **Por que agregar por Material, não por ItemTakeOff**: `Necessidade`/
 * `Requisitado`/`Alocado`/`Em RC`/`Em Pedido`/`Recebido` são naturais no
 * nível de `ItemTakeOff` (cada LM tem seu próprio requisitado/alocado/
 * etc., via a cadeia formal RP→Alocação→RC→Pedido→Recebimento — mesma
 * matemática já provada e testada em
 * `App\Support\Suprimentos\ConciliacaoRecebimento::cadeiaCompletaPorItemTakeOff()`,
 * aqui apenas AGREGADA um nível acima, por `material_id`, em lote SQL,
 * nunca reimplementada). Já `Físico`/`Reservado`/`Aplicado` só existem
 * no nível de `Material` (o ledger de estoque — `MovimentacaoEstoque`/
 * `ReservaEstoque`/`AplicacaoMaterialEstoque` — nunca conhece
 * `ItemTakeOff`, por design desde 20.1: duas LMs diferentes apontando
 * pro MESMO Material consolidam saldo físico automaticamente). Agregar
 * TUDO por Material é o único jeito de nunca fazer double-counting
 * quando 2+ `ItemTakeOff` (de listas/documentos diferentes) apontam pro
 * mesmo `Material` — o saldo físico desse Material nunca é somado duas
 * vezes, mesmo que o "necessário" seja a soma de várias origens
 * documentais distintas.
 *
 * **Nunca soma transferência de custódia como nova disponibilidade**:
 * `Físico` vem de `SaldoEstoque::porMateriaisNaObra()`, que já usa a
 * MESMA expressão de sinal central (`fatorSaldo()` por
 * `TipoMovimentacaoEstoque`) — uma Transferência já é 1 Saída + 1
 * Entrada que se cancelam no SALDO GLOBAL da obra (mesma unidade só
 * muda de Local), nunca duas entradas positivas. Este serviço nunca
 * soma `MovimentacaoEstoque` "por tipo" isoladamente — sempre delega a
 * soma com sinal pra `SaldoEstoque`, nunca reimplementa a fórmula.
 *
 * **Tudo 100% DERIVADO, nunca persistido** (mesmo princípio de toda a
 * Etapa 20) — cada chamada recalcula do zero a partir do ledger.
 */
class PipelineMaterialQuery
{
    /**
     * @param  Collection<int, \App\Models\Material>  $materiais
     * @return Collection<string, array> chave = material_id, valores:
     *   necessidade, requisitado, alocado, em_rc, em_pedido, recebido,
     *   fisico, reservado, disponivel, aplicado, deficit (todos float,
     *   arredondados a 3 casas — exceto deficit/disponivel, que nunca
     *   ficam negativos: `disponivel = max(0, fisico - reservado)`,
     *   `deficit = max(0, reservado - fisico)`)
     */
    public static function porMateriais(Collection $materiais, string $obraId): Collection
    {
        if ($materiais->isEmpty()) {
            return collect();
        }

        $materialIds = $materiais->pluck('id')->all();

        // --- Necessidade/Requisitado/Alocado/EmRc/EmPedido/Recebido ---
        // Mesma matemática de cadeiaCompletaPorItemTakeOff(), agregada
        // por material_id em vez de item_take_off_id, sempre em UMA
        // query por estágio (nunca 1 por ItemTakeOff/Material).

        $necessidade = ItemTakeOff::query()
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->selectRaw('material_id, SUM(quantidade) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        $requisitado = RequisicaoPlanejamentoItem::query()
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereHas('requisicao', fn ($q) => $q->where('status', StatusRequisicaoPlanejamento::Emitida->value))
            ->groupBy('itens_take_off.material_id')
            ->selectRaw('itens_take_off.material_id as material_id, SUM(requisicao_planejamento_itens.quantidade_requisitada) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        $alocado = AlocacaoRequisicaoPacote::query()
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->groupBy('itens_take_off.material_id')
            ->selectRaw('itens_take_off.material_id as material_id, SUM(alocacoes_requisicao_pacote.quantidade_alocada) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        $emRc = RequisicaoCompraItem::query()
            ->join('alocacoes_requisicao_pacote', 'alocacoes_requisicao_pacote.id', '=', 'requisicao_compra_itens.alocacao_requisicao_pacote_id')
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereHas('requisicaoCompra', fn ($q) => $q->whereIn('status', [
                StatusRequisicaoCompra::Emitida->value,
                StatusRequisicaoCompra::Concluida->value,
            ]))
            ->groupBy('itens_take_off.material_id')
            ->selectRaw('itens_take_off.material_id as material_id, SUM(requisicao_compra_itens.quantidade) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        $emPedido = PedidoCompraItem::query()
            ->join('requisicao_compra_itens', 'requisicao_compra_itens.id', '=', 'pedido_compra_itens.requisicao_compra_item_id')
            ->join('alocacoes_requisicao_pacote', 'alocacoes_requisicao_pacote.id', '=', 'requisicao_compra_itens.alocacao_requisicao_pacote_id')
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value))
            ->groupBy('itens_take_off.material_id')
            ->selectRaw('itens_take_off.material_id as material_id, SUM(pedido_compra_itens.quantidade_pedida) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        $recebido = RecebimentoPedido::query()
            ->join('pedido_compra_itens', 'pedido_compra_itens.id', '=', 'recebimentos_pedido.pedido_compra_item_id')
            ->join('requisicao_compra_itens', 'requisicao_compra_itens.id', '=', 'pedido_compra_itens.requisicao_compra_item_id')
            ->join('alocacoes_requisicao_pacote', 'alocacoes_requisicao_pacote.id', '=', 'requisicao_compra_itens.alocacao_requisicao_pacote_id')
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->groupBy('itens_take_off.material_id')
            ->selectRaw('itens_take_off.material_id as material_id, SUM(recebimentos_pedido.quantidade_recebida) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        // --- Físico/Reservado/Aplicado: nível de Material, obra-escopado ---
        $fisico = SaldoEstoque::porMateriaisNaObra($materialIds, $obraId);
        $reservado = SaldoReserva::porMateriaisNaObra($materialIds, $obraId);
        // ConciliacaoAplicacao::pendenteAgregadaPorObra() retorna uma
        // Collection indexada (->values(), pensada pra listagem) — aqui
        // precisamos indexar de novo por material_id pra lookup O(1).
        $aplicadoBruto = \App\Support\Estoque\ConciliacaoAplicacao::pendenteAgregadaPorObra($obraId)->keyBy('material_id');

        return $materiais->mapWithKeys(function ($material) use (
            $necessidade, $requisitado, $alocado, $emRc, $emPedido, $recebido, $fisico, $reservado, $aplicadoBruto
        ) {
            $id = $material->id;
            $valFisico = (float) ($fisico[$id] ?? 0.0);
            $valReservado = (float) ($reservado[$id] ?? 0.0);
            $valAplicado = (float) ($aplicadoBruto[$id]['total_aplicado'] ?? 0.0);

            return [$id => [
                'material_id' => $id,
                'necessidade' => (float) ($necessidade[$id] ?? 0.0),
                'requisitado' => (float) ($requisitado[$id] ?? 0.0),
                'alocado' => (float) ($alocado[$id] ?? 0.0),
                'em_rc' => (float) ($emRc[$id] ?? 0.0),
                'em_pedido' => (float) ($emPedido[$id] ?? 0.0),
                'recebido' => (float) ($recebido[$id] ?? 0.0),
                'fisico' => $valFisico,
                'reservado' => $valReservado,
                'disponivel' => round(max(0, $valFisico - $valReservado), 3),
                'aplicado' => $valAplicado,
                'deficit' => round(max(0, $valReservado - $valFisico), 3),
            ]];
        });
    }

    /**
     * Ciclo 21, Etapa 21.1 — mesma cadeia de compra (Requisitado→Alocado→
     * Em RC→Em Pedido→Recebido), mas agregada por PAR (Pacote, Material)
     * — necessário pra `CoberturaMaterialAtividadeQuery`, que precisa
     * saber o progresso de compra ESPECÍFICO de um Pacote, nunca o total
     * da obra inteira (um Material que atende 2 Pacotes diferentes tem
     * progressos de compra independentes por Pacote — cada Alocação já
     * carrega `item_suprimento_id` diretamente, então agrupar por ele é
     * só estender o `groupBy` das mesmas queries acima, nunca uma
     * segunda regra). Físico/Reservado/Disponível/Aplicado continuam
     * SEMPRE no nível de Material (nunca fatiados por Pacote — são
     * fatos físicos da obra, não uma "cota" de um Pacote específico;
     * quem representa o compromisso ESPECÍFICO de um Pacote sobre esse
     * físico é `App\Support\Estoque\ConciliacaoDestinacao`/a Reserva
     * amarrada a `item_suprimento_id`, consumida separadamente por
     * `CoberturaMaterialAtividadeQuery`).
     *
     * @param  Collection<int, array{item_suprimento_id: string, material_id: string}>  $pares
     * @return Collection<string, array> chave = "{pacoteId}|{materialId}"
     */
    public static function porPacotesEMateriais(Collection $pares, string $obraId): Collection
    {
        if ($pares->isEmpty()) {
            return collect();
        }

        $pacoteIds = $pares->pluck('item_suprimento_id')->unique()->values()->all();
        $materialIds = $pares->pluck('material_id')->unique()->values()->all();
        $chave = fn (string $pacoteId, string $materialId) => "{$pacoteId}|{$materialId}";

        $requisitado = RequisicaoPlanejamentoItem::query()
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->join('alocacoes_requisicao_pacote', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id', '=', 'requisicao_planejamento_itens.id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereIn('alocacoes_requisicao_pacote.item_suprimento_id', $pacoteIds)
            ->whereHas('requisicao', fn ($q) => $q->where('status', StatusRequisicaoPlanejamento::Emitida->value))
            ->groupBy('alocacoes_requisicao_pacote.item_suprimento_id', 'itens_take_off.material_id')
            ->selectRaw('alocacoes_requisicao_pacote.item_suprimento_id as pacote_id, itens_take_off.material_id as material_id, SUM(requisicao_planejamento_itens.quantidade_requisitada) as total')
            ->get()
            ->mapWithKeys(fn ($r) => [$chave($r->pacote_id, $r->material_id) => round((float) $r->total, 3)]);

        $alocado = AlocacaoRequisicaoPacote::query()
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereIn('alocacoes_requisicao_pacote.item_suprimento_id', $pacoteIds)
            ->groupBy('alocacoes_requisicao_pacote.item_suprimento_id', 'itens_take_off.material_id')
            ->selectRaw('alocacoes_requisicao_pacote.item_suprimento_id as pacote_id, itens_take_off.material_id as material_id, SUM(alocacoes_requisicao_pacote.quantidade_alocada) as total')
            ->get()
            ->mapWithKeys(fn ($r) => [$chave($r->pacote_id, $r->material_id) => round((float) $r->total, 3)]);

        $emRc = RequisicaoCompraItem::query()
            ->join('alocacoes_requisicao_pacote', 'alocacoes_requisicao_pacote.id', '=', 'requisicao_compra_itens.alocacao_requisicao_pacote_id')
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereIn('alocacoes_requisicao_pacote.item_suprimento_id', $pacoteIds)
            ->whereHas('requisicaoCompra', fn ($q) => $q->whereIn('status', [
                StatusRequisicaoCompra::Emitida->value,
                StatusRequisicaoCompra::Concluida->value,
            ]))
            ->groupBy('alocacoes_requisicao_pacote.item_suprimento_id', 'itens_take_off.material_id')
            ->selectRaw('alocacoes_requisicao_pacote.item_suprimento_id as pacote_id, itens_take_off.material_id as material_id, SUM(requisicao_compra_itens.quantidade) as total')
            ->get()
            ->mapWithKeys(fn ($r) => [$chave($r->pacote_id, $r->material_id) => round((float) $r->total, 3)]);

        $emPedido = PedidoCompraItem::query()
            ->join('requisicao_compra_itens', 'requisicao_compra_itens.id', '=', 'pedido_compra_itens.requisicao_compra_item_id')
            ->join('alocacoes_requisicao_pacote', 'alocacoes_requisicao_pacote.id', '=', 'requisicao_compra_itens.alocacao_requisicao_pacote_id')
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereIn('alocacoes_requisicao_pacote.item_suprimento_id', $pacoteIds)
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value))
            ->groupBy('alocacoes_requisicao_pacote.item_suprimento_id', 'itens_take_off.material_id')
            ->selectRaw('alocacoes_requisicao_pacote.item_suprimento_id as pacote_id, itens_take_off.material_id as material_id, SUM(pedido_compra_itens.quantidade_pedida) as total')
            ->get()
            ->mapWithKeys(fn ($r) => [$chave($r->pacote_id, $r->material_id) => round((float) $r->total, 3)]);

        $recebido = RecebimentoPedido::query()
            ->join('pedido_compra_itens', 'pedido_compra_itens.id', '=', 'recebimentos_pedido.pedido_compra_item_id')
            ->join('requisicao_compra_itens', 'requisicao_compra_itens.id', '=', 'pedido_compra_itens.requisicao_compra_item_id')
            ->join('alocacoes_requisicao_pacote', 'alocacoes_requisicao_pacote.id', '=', 'requisicao_compra_itens.alocacao_requisicao_pacote_id')
            ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
            ->join('itens_take_off', 'itens_take_off.id', '=', 'requisicao_planejamento_itens.item_take_off_id')
            ->whereIn('itens_take_off.material_id', $materialIds)
            ->whereIn('alocacoes_requisicao_pacote.item_suprimento_id', $pacoteIds)
            ->groupBy('alocacoes_requisicao_pacote.item_suprimento_id', 'itens_take_off.material_id')
            ->selectRaw('alocacoes_requisicao_pacote.item_suprimento_id as pacote_id, itens_take_off.material_id as material_id, SUM(recebimentos_pedido.quantidade_recebida) as total')
            ->get()
            ->mapWithKeys(fn ($r) => [$chave($r->pacote_id, $r->material_id) => round((float) $r->total, 3)]);

        return $pares->mapWithKeys(function (array $par) use ($chave, $requisitado, $alocado, $emRc, $emPedido, $recebido) {
            $k = $chave($par['item_suprimento_id'], $par['material_id']);

            return [$k => [
                'item_suprimento_id' => $par['item_suprimento_id'],
                'material_id' => $par['material_id'],
                'requisitado' => (float) ($requisitado[$k] ?? 0.0),
                'alocado' => (float) ($alocado[$k] ?? 0.0),
                'em_rc' => (float) ($emRc[$k] ?? 0.0),
                'em_pedido' => (float) ($emPedido[$k] ?? 0.0),
                'recebido' => (float) ($recebido[$k] ?? 0.0),
            ]];
        });
    }
}
