<?php

namespace App\Exports;

use App\Models\Work;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ObrasExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $works)
    {
    }

    public function collection(): Collection
    {
        return $this->works;
    }

    public function headings(): array
    {
        return ['Obra', 'Cliente', 'Localização', 'Início', 'Término', 'Avanço (%)', 'Status', 'Membros'];
    }

    public function map($work): array
    {
        /** @var Work $work */
        return [
            $work->name,
            $work->client?->name ?? '—',
            $work->location ?? '—',
            $work->start_date_baseline?->format('d/m/Y') ?? '—',
            $work->end_date_baseline?->format('d/m/Y') ?? '—',
            number_format((float) ($work->avanco_realizado ?? 0), 1, ',', ''),
            match ($work->status) {
                'planejamento' => 'Planejamento',
                'em_andamento' => 'Em Andamento',
                'paralisada' => 'Paralisada',
                'concluida' => 'Concluída',
                default => $work->status,
            },
            $work->users_count ?? $work->users->count(),
        ];
    }
}
