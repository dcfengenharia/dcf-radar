<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SuprimentosExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $linhas)
    {
    }

    public function collection(): Collection
    {
        return $this->linhas;
    }

    public function headings(): array
    {
        return [
            'Item', 'Código', 'Fluxo', 'Fornecedor', 'Status',
            'Necessidade', 'Previsto', 'Tendência', 'Desvio (dias)',
        ];
    }

    /**
     * @param  array{item: \App\Models\ItemSuprimento, necessidade: ?\Carbon\Carbon, previstoFinal: ?\Carbon\Carbon, tendenciaFinal: ?\Carbon\Carbon, desvioDias: ?int}  $linha
     */
    public function map($linha): array
    {
        $item = $linha['item'];

        return [
            $item->nome,
            $item->codigo ?? '—',
            $item->fluxo?->nome ?? '—',
            $item->fornecedor?->nome ?? '—',
            $item->status->label(),
            $linha['necessidade']?->format('d/m/Y') ?? '—',
            $linha['previstoFinal']?->format('d/m/Y') ?? '—',
            $linha['tendenciaFinal']?->format('d/m/Y') ?? '—',
            $linha['desvioDias'] ?? '—',
        ];
    }
}
