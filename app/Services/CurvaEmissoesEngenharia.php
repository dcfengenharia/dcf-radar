<?php

namespace App\Services;

use App\Models\DocumentoEngenharia;
use App\Models\Work;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Curva S de emissões da LD (Previsto x Realizado) — conta documentos por
 * período (mês/semana), não HH: Previsto = data_planejada do documento,
 * Realizado = data_emissao da 1ª emissão (imutável, ver
 * DocumentoEngenharia::dataEmissaoReal()). Não reaproveita App\Services\
 * CurvaAvanco (que é HH/cronograma-based, não se aplica aqui).
 */
class CurvaEmissoesEngenharia
{
    public function calcular(Work $obra): array
    {
        // Atenção: relations "ofMany" (primeiraRevisao) não podem ter a
        // eager-load restrita a poucas colunas — o self-join interno que o
        // ofMany monta gera "documento_engenharia_id" ambíguo entre a
        // tabela e a subquery de agregação quando se tenta um select
        // parcial. Sempre carregar sem restringir colunas aqui.
        $documentos = DocumentoEngenharia::where('obra_id', $obra->id)
            ->with('primeiraRevisao')
            ->get(['id', 'data_planejada']);

        $total = $documentos->count();

        if ($total === 0) {
            $vazio = ['labels' => [], 'qtdPrevisto' => [], 'qtdRealizado' => [], 'pctPrevistoAcumulado' => [], 'pctRealizadoAcumulado' => []];

            return ['total' => 0, 'mensal' => $vazio, 'semanalCompleta' => $vazio, 'mesesDisponiveis' => []];
        }

        $previstos = $documentos->pluck('data_planejada')->filter();
        $realizados = $documentos->map(fn(DocumentoEngenharia $d) => $d->primeiraRevisao?->data_emissao)->filter();

        $mensal = $this->montarSerie($previstos, $realizados, $total, 'month');
        $semanalCompleta = $this->montarSerie($previstos, $realizados, $total, 'week');

        $mesesDisponiveis = collect($semanalCompleta['labels'])
            ->map(fn($label) => CarbonImmutable::parse($label)->format('Y-m'))
            ->unique()
            ->values()
            ->all();

        return [
            'total' => $total,
            'mensal' => $mensal,
            'semanalCompleta' => $semanalCompleta,
            'mesesDisponiveis' => $mesesDisponiveis,
        ];
    }

    /**
     * @param Collection<int, \Carbon\Carbon> $previstos
     * @param Collection<int, \Carbon\Carbon> $realizados
     */
    private function montarSerie(Collection $previstos, Collection $realizados, int $total, string $unidade): array
    {
        $chave = fn($data) => $unidade === 'month'
            ? CarbonImmutable::parse($data)->startOfMonth()->format('Y-m-d')
            : CarbonImmutable::parse($data)->startOfWeek()->format('Y-m-d');

        $previstoPorPeriodo = $previstos->groupBy($chave)->map->count();
        $realizadoPorPeriodo = $realizados->groupBy($chave)->map->count();

        $todosPeriodos = $previstoPorPeriodo->keys()
            ->merge($realizadoPorPeriodo->keys())
            ->unique()
            ->sort()
            ->values();

        $acumuladoPrevisto = 0;
        $acumuladoRealizado = 0;
        $labels = [];
        $qtdPrevisto = [];
        $qtdRealizado = [];
        $pctPrevistoAcumulado = [];
        $pctRealizadoAcumulado = [];

        foreach ($todosPeriodos as $periodo) {
            $qp = $previstoPorPeriodo->get($periodo, 0);
            $qr = $realizadoPorPeriodo->get($periodo, 0);
            $acumuladoPrevisto += $qp;
            $acumuladoRealizado += $qr;

            $labels[] = $periodo;
            $qtdPrevisto[] = $qp;
            $qtdRealizado[] = $qr;
            $pctPrevistoAcumulado[] = round(($acumuladoPrevisto / $total) * 100, 1);
            $pctRealizadoAcumulado[] = round(($acumuladoRealizado / $total) * 100, 1);
        }

        return compact('labels', 'qtdPrevisto', 'qtdRealizado', 'pctPrevistoAcumulado', 'pctRealizadoAcumulado');
    }
}
