<?php

namespace App\Support;

use App\Enums\GranularidadePeriodo;
use App\Models\ReportCurva;
use Illuminate\Support\Carbon;

/**
 * Transforma os datapoints já gravados de um ReportCurva (fotografia,
 * nunca recalculado ao vivo — ver App\Services\ReportGerador) em
 * estrutura pronta pra tabela/gráfico. Extraído de
 * ⚡relatorio-detalhe.blade.php (única fonte original) pra ser
 * reaproveitado também pelo link público do cliente
 * (App\Http\Controllers\ClienteRelatorioPublicoController) — mesma
 * lógica, sem duplicar/arriscar divergência entre as duas telas.
 *
 * Todos os valores aqui são PERCENTUAIS — nunca HH. "barras" = % que o
 * HH daquele período representa do total de linha de base da curva
 * (period_horas ÷ total_hh_previsto); "linhas" = % acumulado (já
 * corrigido em ReportGerador::gerarCurva() via CurvaAvanco::
 * rebasearPercentual(), mesmo denominador pras 3 séries).
 */
class ReportCurvaSerializer
{
    private const MESES_PT = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

    public static function serieParaGrafico(ReportCurva $curva, GranularidadePeriodo $gran): array
    {
        $doPeriodo = $curva->datapoints->where('granularidade', $gran);

        $periodos = $doPeriodo->pluck('periodo_inicio')
            ->unique(fn ($d) => $d->toDateString())
            ->sort()
            ->values();

        $porSerie = $doPeriodo->groupBy(fn ($d) => $d->serie->value);

        $labels = $periodos->map(fn ($p) => self::formatarPeriodoPt($p, $gran))->all();

        $porData = [];
        foreach (['previsto' => 'Previsto', 'tendencia' => 'Tendência', 'realizado' => 'Realizado'] as $serieValue => $label) {
            $porData[$serieValue] = ($porSerie->get($serieValue) ?? collect())->keyBy(fn ($d) => $d->periodo_inicio->toDateString());
        }

        $totalBase = (float) $curva->total_hh_previsto;

        $barras = [];
        $linhas = [];
        foreach (['previsto' => 'Previsto', 'tendencia' => 'Tendência', 'realizado' => 'Realizado'] as $serieValue => $label) {
            $barras[$serieValue] = [
                'label' => $label,
                'data' => $periodos->map(function ($p) use ($porData, $serieValue, $totalBase) {
                    if (! $porData[$serieValue]->has($p->toDateString())) {
                        return null;
                    }

                    $horas = (float) $porData[$serieValue]->get($p->toDateString())->horas;

                    return $totalBase > 0 ? round($horas / $totalBase * 100, 2) : 0.0;
                })->all(),
            ];
            $linhas[$serieValue] = [
                'label' => $label,
                'data' => $periodos->map(fn ($p) => $porData[$serieValue]->has($p->toDateString())
                    ? (float) $porData[$serieValue]->get($p->toDateString())->percentual_acumulado
                    : null)->all(),
            ];
        }

        // Aderência = %real acumulado ÷ %previsto acumulado, período a
        // período — 100% = exatamente em dia com o planejado (usada na
        // tabela mensal).
        $tabela = [];
        foreach ($periodos as $i => $p) {
            $chave = $p->toDateString();
            $previstoPct = $porData['previsto']->has($chave) ? (float) $porData['previsto']->get($chave)->percentual_acumulado : null;
            $tendenciaPct = $porData['tendencia']->has($chave) ? (float) $porData['tendencia']->get($chave)->percentual_acumulado : null;
            $realPct = $porData['realizado']->has($chave) ? (float) $porData['realizado']->get($chave)->percentual_acumulado : null;

            $aderencia = ($previstoPct !== null && $previstoPct > 0 && $realPct !== null)
                ? round($realPct / $previstoPct * 100, 2)
                : null;

            // Aderência DA SEMANA (usada na tabela semanal e no
            // velocímetro): %realizado DO PERÍODO ÷ %previsto DO PERÍODO —
            // diferente da acumulada acima, mede o quanto foi executado
            // NAQUELA semana especificamente em relação ao planejado pra
            // ela, não o acumulado desde o início do projeto.
            $previstoPctPeriodo = $barras['previsto']['data'][$i];
            $realizadoPctPeriodo = $barras['realizado']['data'][$i];
            $aderenciaPeriodo = ($previstoPctPeriodo !== null && $previstoPctPeriodo > 0 && $realizadoPctPeriodo !== null)
                ? round($realizadoPctPeriodo / $previstoPctPeriodo * 100, 2)
                : null;

            $tabela[] = [
                'label' => $labels[$i],
                'previsto_pct_periodo' => $barras['previsto']['data'][$i],
                'tendencia_pct_periodo' => $barras['tendencia']['data'][$i],
                'realizado_pct_periodo' => $barras['realizado']['data'][$i],
                'previsto_pct' => $previstoPct,
                'tendencia_pct' => $tendenciaPct,
                'realizado_pct' => $realPct,
                'aderencia' => $aderencia,
                'aderencia_periodo' => $aderenciaPeriodo,
            ];
        }

        return [
            'labels' => $labels,
            'barras' => $barras,
            'linhas' => $linhas,
            'tabela' => $tabela,
        ];
    }

    public static function formatarPeriodoPt(Carbon $data, GranularidadePeriodo $gran): string
    {
        if ($gran === GranularidadePeriodo::Mensal) {
            return self::MESES_PT[$data->month - 1].'/'.$data->format('y');
        }

        // Número da semana (ISO-8601) + ano cheio — evita a confusão de
        // mostrar dois números de 2 dígitos parecidos (ex.: "26/2026").
        return sprintf('SEM %02d/%d', $data->weekOfYear, $data->year);
    }
}
