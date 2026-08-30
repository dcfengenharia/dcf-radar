<?php

namespace App\Support\Estoque;

use App\Models\AplicacaoMaterialEstoque;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.4 — desvio DIRETAMENTE RASTREÁVEL (Seções 21/22/23
 * do pedido): só existe quando uma Saída consome uma Reserva ligada a
 * uma Destinação Planejada (Frente conhecida) — nesse caso, comparar a
 * Frente das Aplicações reais dessa MESMA Saída contra a Frente
 * planejada da Reserva é um fato auditável, não uma heurística.
 *
 * **Nunca inventa causalidade** (Seção 21) — uma Aplicação em excesso
 * numa Frente B qualquer (sem vínculo de Reserva) NUNCA é atribuída
 * como "vinda de" uma Frente A específica; isso só é calculável quando
 * a PRÓPRIA Saída está vinculada à Reserva de A (rastreabilidade
 * direta). Reserva sem Frente planejada (`destinacao_planejada_material_id`
 * null, Seção T) não tem "planejado" pra comparar — retorna sem
 * aderente/desviado, nunca um valor inventado.
 */
class DesviosAplicacao
{
    /**
     * @return array{
     *     frente_planejada_id: ?string,
     *     tem_frente_planejada: bool,
     *     aderente: float,
     *     desviado_por_frente: Collection<string, float>,
     *     total_desviado: float,
     * }|null null quando a Saída não está vinculada a nenhuma Reserva.
     */
    public static function porSaida(MovimentacaoEstoque $saida): ?array
    {
        if (! $saida->reserva_estoque_id) {
            return null;
        }

        $reserva = ReservaEstoque::with('destinacaoPlanejada')->find($saida->reserva_estoque_id);
        if (! $reserva) {
            return null;
        }

        $frentePlanejadaId = $reserva->destinacaoPlanejada?->frente_trabalho_id;

        $aplicacoes = AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $saida->id)->get();

        if (! $frentePlanejadaId) {
            return [
                'frente_planejada_id' => null,
                'tem_frente_planejada' => false,
                'aderente' => 0.0,
                'desviado_por_frente' => collect(),
                'total_desviado' => 0.0,
            ];
        }

        $aderente = round((float) $aplicacoes->where('frente_trabalho_id', $frentePlanejadaId)->sum('quantidade'), 3);

        $desviadoPorFrente = $aplicacoes
            ->where('frente_trabalho_id', '!=', $frentePlanejadaId)
            ->groupBy('frente_trabalho_id')
            ->map(fn (Collection $grupo) => round((float) $grupo->sum('quantidade'), 3));

        return [
            'frente_planejada_id' => $frentePlanejadaId,
            'tem_frente_planejada' => true,
            'aderente' => $aderente,
            'desviado_por_frente' => $desviadoPorFrente,
            'total_desviado' => round((float) $desviadoPorFrente->sum(), 3),
        ];
    }

    /**
     * Versão em lote pra listagem — recebe as Saídas já carregadas
     * (com `reservaEstoque.destinacaoPlanejada` eager-loaded pelo
     * chamador) e as Aplicações já carregadas em lote, nunca 1 query
     * por Saída.
     *
     * @param  Collection<int, MovimentacaoEstoque>  $saidas
     * @return Collection<string, array> chave = movimentacao_estoque_id
     */
    public static function porSaidasEmLote(Collection $saidas): Collection
    {
        $saidasComReserva = $saidas->filter(fn (MovimentacaoEstoque $s) => (bool) $s->reserva_estoque_id);

        if ($saidasComReserva->isEmpty()) {
            return collect();
        }

        $reservaIds = $saidasComReserva->pluck('reserva_estoque_id')->unique()->values()->all();
        $reservasPorId = ReservaEstoque::with('destinacaoPlanejada')->whereIn('id', $reservaIds)->get()->keyBy('id');

        $saidaIds = $saidasComReserva->pluck('id')->values()->all();
        $aplicacoesPorSaida = AplicacaoMaterialEstoque::whereIn('movimentacao_estoque_id', $saidaIds)
            ->get()
            ->groupBy('movimentacao_estoque_id');

        return $saidasComReserva->mapWithKeys(function (MovimentacaoEstoque $saida) use ($reservasPorId, $aplicacoesPorSaida) {
            $reserva = $reservasPorId->get($saida->reserva_estoque_id);
            $frentePlanejadaId = $reserva?->destinacaoPlanejada?->frente_trabalho_id;
            $aplicacoes = $aplicacoesPorSaida->get($saida->id, collect());

            if (! $frentePlanejadaId) {
                return [$saida->id => [
                    'frente_planejada_id' => null,
                    'tem_frente_planejada' => false,
                    'aderente' => 0.0,
                    'desviado_por_frente' => collect(),
                    'total_desviado' => 0.0,
                ]];
            }

            $aderente = round((float) $aplicacoes->where('frente_trabalho_id', $frentePlanejadaId)->sum('quantidade'), 3);
            $desviadoPorFrente = $aplicacoes
                ->where('frente_trabalho_id', '!=', $frentePlanejadaId)
                ->groupBy('frente_trabalho_id')
                ->map(fn (Collection $grupo) => round((float) $grupo->sum('quantidade'), 3));

            return [$saida->id => [
                'frente_planejada_id' => $frentePlanejadaId,
                'tem_frente_planejada' => true,
                'aderente' => $aderente,
                'desviado_por_frente' => $desviadoPorFrente,
                'total_desviado' => round((float) $desviadoPorFrente->sum(), 3),
            ]];
        });
    }
}
