<?php

namespace App\Support\Estoque;

use App\Models\AtividadeNecessidadeMaterial;
use App\Models\ItemTakeOff;
use Illuminate\Support\Collection;

/**
 * Melhoria "Posto Operacional" — mesma filosofia 100% derivada de
 * `App\Support\Estoque\ConciliacaoDestinacao` (Ciclo 20.2): nada aqui é
 * persistido, toda quantidade distribuída/saldo é recalculada a cada
 * leitura, em lote (`GROUP BY`), nunca 1 query por linha em loop.
 *
 * "Quantidade formal" de um `ItemTakeOff` é o próprio
 * `ItemTakeOff.quantidade` (decisão do usuário — a Produção precisa
 * enxergar a necessidade por atividade ANTES mesmo de Suprimentos ter
 * processado RP/RC/Pedido daquele Pacote, nunca a demanda formal do
 * Pacote via `AlocacaoRequisicaoPacote`, que é sempre um subconjunto
 * possivelmente ainda incompleto do TakeOff).
 */
class ConciliacaoNecessidadeAtividade
{
    /**
     * Soma já distribuída (AtividadeNecessidadeMaterial, origem=take_off)
     * de UM ItemTakeOff, excluindo opcionalmente uma linha (edição) —
     * usado pelos guards de escrita, sempre chamado DEPOIS de travar o
     * ItemTakeOff.
     */
    public static function quantidadeDistribuida(string $itemTakeOffId, ?string $excluirNecessidadeId = null): float
    {
        $query = AtividadeNecessidadeMaterial::query()
            ->where('item_take_off_id', $itemTakeOffId);

        if ($excluirNecessidadeId) {
            $query->where('id', '!=', $excluirNecessidadeId);
        }

        return (float) $query->sum('quantidade_necessaria');
    }

    /**
     * Saldo ainda não distribuído de um ItemTakeOff — pode ser NEGATIVO
     * (sem clamp) quando o TakeOff foi reduzido depois de já distribuído
     * às atividades (mesmo comportamento honesto já aceito em
     * `ConciliacaoTakeOff`/Ciclo 19.2.CORREÇÃO — nunca truncado/corrigido
     * automaticamente).
     */
    public static function saldoADistribuir(ItemTakeOff $itemTakeOff, ?string $excluirNecessidadeId = null): float
    {
        return round(
            (float) $itemTakeOff->quantidade
                - self::quantidadeDistribuida($itemTakeOff->id, $excluirNecessidadeId),
            3
        );
    }

    /**
     * Visão agregada em lote — pra popular o seletor "ItemTakeOff
     * disponível" (Seção 14: "mostrar pelo menos Quantidade TakeOff /
     * Já distribuído / Saldo disponível") sem 1 query por item.
     *
     * @param  array<int, string>  $itemTakeOffIds
     * @return Collection<string, array{quantidade_take_off: float, distribuido: float, saldo: float}>
     */
    public static function porItensTakeOff(array $itemTakeOffIds): Collection
    {
        if (empty($itemTakeOffIds)) {
            return collect();
        }

        $itens = ItemTakeOff::query()
            ->whereIn('id', $itemTakeOffIds)
            ->get(['id', 'quantidade'])
            ->keyBy('id');

        $distribuidoPorItem = AtividadeNecessidadeMaterial::query()
            ->whereIn('item_take_off_id', $itemTakeOffIds)
            ->selectRaw('item_take_off_id, SUM(quantidade_necessaria) as total')
            ->groupBy('item_take_off_id')
            ->get()
            ->keyBy('item_take_off_id')
            ->map(fn ($row) => (float) $row->total);

        return $itens->map(function (ItemTakeOff $item) use ($distribuidoPorItem) {
            $quantidadeTakeOff = (float) $item->quantidade;
            $distribuido = $distribuidoPorItem[$item->id] ?? 0.0;

            return [
                'quantidade_take_off' => $quantidadeTakeOff,
                'distribuido' => $distribuido,
                'saldo' => round($quantidadeTakeOff - $distribuido, 3),
            ];
        });
    }
}
