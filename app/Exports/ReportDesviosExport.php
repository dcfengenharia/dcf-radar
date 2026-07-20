<?php

namespace App\Exports;

use App\Models\Report;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Uma linha por linha do quadro de análise de desvios de cada curva do
 * report — os mesmos números já congelados em ReportDesvio na geração
 * (ver App\Services\ReportGerador), nunca recalculados aqui.
 */
class ReportDesviosExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private readonly Report $report)
    {
    }

    public function collection(): Collection
    {
        return $this->report->curvas->flatMap(
            fn ($curva) => $curva->desvios->map(fn ($desvio) => ['curva' => $curva, 'desvio' => $desvio])
        );
    }

    public function headings(): array
    {
        return ['Curva', 'Pacote', 'Nível', 'Peso', '% Previsto', '% Real', '% Desvio', '% Impacto'];
    }

    public function map($linha): array
    {
        return [
            $linha['curva']->titulo_exibicao,
            $linha['desvio']->titulo_exibicao,
            $linha['desvio']->eh_nivel_pai ? 'Pai' : 'Filho',
            round((float) $linha['desvio']->peso * 100, 2) . '%',
            round((float) $linha['desvio']->percentual_previsto, 2) . '%',
            round((float) $linha['desvio']->percentual_real, 2) . '%',
            round((float) $linha['desvio']->percentual_desvio, 2) . '%',
            round((float) $linha['desvio']->percentual_impacto, 2) . '%',
        ];
    }

    public function title(): string
    {
        return 'Desvios';
    }
}
