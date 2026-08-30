<?php

namespace App\Support\TakeOff;

use App\Models\ItemTakeOff;
use Illuminate\Support\Collection;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — Curva ABC quantitativa (D8: sem preço
 * nenhuma fonte real hoje, nunca inventar preco_estimado só para
 * produzir gráfico — ABC econômica fica para quando existir preço de
 * verdade em Take Off/RC/Pedido).
 *
 * **Achado desta correção**: a 19.1 original chamava `calcular()` sobre
 * TODOS os itens consolidados de uma vez, somando quantidade de
 * grandezas incompatíveis (metros + quilos + unidades) como se fossem a
 * mesma coisa — análise matematicamente enganosa (seção 12 do pedido).
 * `calcularAgrupadoPorUnidade()` é agora o ÚNICO ponto de entrada
 * público usado pela UI/consolidado: segmenta por `UnidadeMedida` ANTES
 * de aplicar a classificação ABC, nunca comparando quantidades de
 * unidades diferentes no mesmo ranking. `calcular()` continua existindo
 * (documentado como só válido dentro de um conjunto JÁ homogêneo de
 * unidade) porque é o motor reaproveitado por
 * `calcularAgrupadoPorUnidade()` — nunca deve ser chamado direto sobre
 * uma coleção mista de fora deste arquivo.
 *
 * Classificação clássica por percentual acumulado de QUANTIDADE (não
 * valor): A até 80%, B até 95%, C o restante. Classe de cada item é
 * decidida pelo acumulado ANTES de somar a fatia deste item (não
 * depois) — garante que o maior item sozinho seja sempre classe A,
 * mesmo quando sua própria fatia já ultrapassa 80% do total.
 */
class CurvaAbcTakeOff
{
    private const LIMITE_A = 80.0;
    private const LIMITE_B = 95.0;

    /**
     * Ponto de entrada seguro — agrupa por unidade de medida (código, ou
     * "Sem unidade" quando nula) e calcula a Curva ABC independentemente
     * dentro de cada grupo. Ordenado por quantidade total do grupo
     * (maior grandeza de volume primeiro), não alfabético.
     *
     * @param Collection<int, ItemTakeOff> $itens
     * @return array<int, array{unidade_medida_id: ?string, unidade_label: string, linhas: array}>
     */
    public static function calcularAgrupadoPorUnidade(Collection $itens): array
    {
        $porUnidade = $itens->groupBy(fn (ItemTakeOff $item) => $item->unidade_medida_id ?? '__sem_unidade__');

        $grupos = $porUnidade->map(function (Collection $grupo, string $chave) {
            $unidade = $grupo->first()->unidadeMedida;

            return [
                'unidade_medida_id' => $chave === '__sem_unidade__' ? null : $chave,
                'unidade_label' => $unidade?->codigo ?? 'Sem unidade',
                'total_quantidade' => (float) $grupo->sum(fn (ItemTakeOff $i) => (float) $i->quantidade),
                'linhas' => self::calcular($grupo),
            ];
        })->values();

        return $grupos->sortByDesc('total_quantidade')->values()->all();
    }

    /**
     * Motor de cálculo — só produz resultado correto sobre uma coleção
     * JÁ homogênea de unidade de medida (garantido por
     * `calcularAgrupadoPorUnidade()`). Não valida heterogeneidade
     * internamente (não é o papel deste método) — nunca chamar direto
     * fora deste arquivo sobre uma coleção mista.
     *
     * @param Collection<int, ItemTakeOff> $itens
     * @return array<int, array{item: ItemTakeOff, percentual: float, percentual_acumulado: float, classe: string}>
     */
    public static function calcular(Collection $itens): array
    {
        $total = (float) $itens->sum(fn (ItemTakeOff $item) => (float) $item->quantidade);

        if ($total <= 0.0) {
            return [];
        }

        $ordenados = $itens->sortByDesc(fn (ItemTakeOff $item) => (float) $item->quantidade)->values();

        $acumuladoAntes = 0.0;
        $resultado = [];

        foreach ($ordenados as $item) {
            $percentual = ((float) $item->quantidade / $total) * 100;

            $classe = match (true) {
                $acumuladoAntes < self::LIMITE_A => 'A',
                $acumuladoAntes < self::LIMITE_B => 'B',
                default => 'C',
            };

            $acumuladoAntes += $percentual;

            $resultado[] = [
                'item' => $item,
                'percentual' => round($percentual, 2),
                'percentual_acumulado' => round($acumuladoAntes, 2),
                'classe' => $classe,
            ];
        }

        return $resultado;
    }
}
