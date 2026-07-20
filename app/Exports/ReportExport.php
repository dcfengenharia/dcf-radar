<?php

namespace App\Exports;

use App\Models\Report;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Exportação Excel do Report — uma aba com o quadro de desvios de todas
 * as curvas, outra com os pontos da curva S. Não inclui fotos/gráfico
 * (Chart.js não roda no Excel); a versão completa com imagens é o PDF
 * (ver App\Http\... exportarPdf() em ⚡relatorio-detalhe.blade.php).
 */
class ReportExport implements WithMultipleSheets
{
    public function __construct(private readonly Report $report)
    {
    }

    public function sheets(): array
    {
        return [
            new ReportDesviosExport($this->report),
            new ReportCurvaExport($this->report),
        ];
    }
}
