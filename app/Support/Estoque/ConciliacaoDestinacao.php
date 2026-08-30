<?php

namespace App\Support\Estoque;

use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DestinacaoPlanejadaMaterial;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.2 — mesma filosofia 100% derivada de
 * App\Support\Suprimentos\ConciliacaoAlocacao/ConciliacaoTakeOff/
 * ConciliacaoRecebimento (Ciclo 19): nada aqui é persistido — toda
 * quantidade formal/destinada/pendente é recalculada a cada leitura, em
 * lote (GROUP BY), nunca 1 query por linha em loop.
 *
 * "Quantidade formal" de um par Pacote+Material (Seção 5 da
 * investigação) = SUM de AlocacaoRequisicaoPacote.quantidade_alocada
 * cujo requisicaoItem.itemTakeOff.material_id bate com o Material —
 * nunca uma quantidade nova e independente da cadeia RPItem->Alocacao->
 * Pacote já existente desde o Ciclo 19.
 */
class ConciliacaoDestinacao
{
    /**
     * Soma formal (AlocacaoRequisicaoPacote) de UM par Pacote+Material —
     * usado pelos guards de escrita (Action/Observer), sempre chamado
     * DEPOIS de travar o recurso relevante (nunca a fonte de lock em si).
     */
    public static function quantidadeFormal(string $itemSuprimentoId, string $materialId): float
    {
        return (float) AlocacaoRequisicaoPacote::query()
            ->where('item_suprimento_id', $itemSuprimentoId)
            ->whereHas('requisicaoItem.itemTakeOff', fn ($q) => $q->where('material_id', $materialId))
            ->sum('quantidade_alocada');
    }

    /**
     * Soma já destinada (DestinacaoPlanejadaMaterial) de um par
     * Pacote+Material, excluindo opcionalmente uma linha (edição).
     */
    public static function quantidadeDestinada(string $itemSuprimentoId, string $materialId, ?string $excluirDestinacaoId = null): float
    {
        $query = DestinacaoPlanejadaMaterial::query()
            ->where('item_suprimento_id', $itemSuprimentoId)
            ->where('material_id', $materialId);

        if ($excluirDestinacaoId) {
            $query->where('id', '!=', $excluirDestinacaoId);
        }

        return (float) $query->sum('quantidade_planejada');
    }

    public static function saldoADestinar(string $itemSuprimentoId, string $materialId, ?string $excluirDestinacaoId = null): float
    {
        return round(
            self::quantidadeFormal($itemSuprimentoId, $materialId)
                - self::quantidadeDestinada($itemSuprimentoId, $materialId, $excluirDestinacaoId),
            3
        );
    }

    /**
     * Visão agregada por Pacote+Material, pra UI/listagem — recebe os
     * pares já resolvidos pelo chamador (nunca descobre pares sozinha),
     * mas resolve formal/destinado em lote (1 query cada, nunca N).
     *
     * @param  Collection<int, array{item_suprimento_id: string, material_id: string}>  $pares
     * @return Collection<int, array{item_suprimento_id: string, material_id: string, formal: float, destinado: float, saldo_a_destinar: float}>
     */
    public static function porPares(Collection $pares): Collection
    {
        if ($pares->isEmpty()) {
            return collect();
        }

        $pacoteIds = $pares->pluck('item_suprimento_id')->unique()->values()->all();
        $materialIds = $pares->pluck('material_id')->unique()->values()->all();

        $formalPorPar = AlocacaoRequisicaoPacote::query()
            ->whereIn('item_suprimento_id', $pacoteIds)
            ->with('requisicaoItem.itemTakeOff:id,material_id')
            ->get()
            ->filter(fn (AlocacaoRequisicaoPacote $a) => in_array($a->requisicaoItem?->itemTakeOff?->material_id, $materialIds, true))
            ->groupBy(fn (AlocacaoRequisicaoPacote $a) => $a->item_suprimento_id . '|' . $a->requisicaoItem->itemTakeOff->material_id)
            ->map(fn ($grupo) => (float) $grupo->sum('quantidade_alocada'));

        $destinadoPorPar = DestinacaoPlanejadaMaterial::query()
            ->whereIn('item_suprimento_id', $pacoteIds)
            ->whereIn('material_id', $materialIds)
            ->selectRaw('item_suprimento_id, material_id, SUM(quantidade_planejada) as total')
            ->groupBy('item_suprimento_id', 'material_id')
            ->get()
            ->keyBy(fn ($row) => $row->item_suprimento_id . '|' . $row->material_id)
            ->map(fn ($row) => (float) $row->total);

        return $pares->map(function (array $par) use ($formalPorPar, $destinadoPorPar) {
            $chave = $par['item_suprimento_id'] . '|' . $par['material_id'];
            $formal = $formalPorPar[$chave] ?? 0.0;
            $destinado = $destinadoPorPar[$chave] ?? 0.0;

            return $par + [
                'formal' => $formal,
                'destinado' => $destinado,
                'saldo_a_destinar' => round($formal - $destinado, 3),
            ];
        })->values();
    }
}
