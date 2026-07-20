<?php

namespace App\Exports;

use App\Models\Report;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Um período por linha da curva S de cada curva do report — os mesmos
 * pontos já congelados em ReportCurvaDatapoint na geração (ver
 * App\Services\ReportGerador), nunca recalculados aqui.
 */
class ReportCurvaExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private readonly Report $report)
    {
    }

    public function collection(): Collection
    {
        return $this->report->curvas->flatMap(
            fn ($curva) => $curva->datapoints->map(fn ($ponto) => ['curva' => $curva, 'ponto' => $ponto])
        )->sortBy(fn ($linha) => $linha['ponto']->periodo_inicio)->values();
    }

    public function headings(): array
    {
        return ['Curva', 'Granularidade', 'Série', 'Período', 'HH', '% Acumulado'];
    }

    public function map($linha): array
    {
        return [
            $linha['curva']->titulo_exibicao,
            $linha['ponto']->granularidade->value,
            $linha['ponto']->serie->value,
            $linha['ponto']->periodo_inicio->format('d/m/Y'),
            round((float) $linha['ponto']->horas, 2),
            round((float) $linha['ponto']->percentual_acumulado, 2) . '%',
        ];
    }

    public function title(): string
    {
        return 'Curva S';
    }
}
