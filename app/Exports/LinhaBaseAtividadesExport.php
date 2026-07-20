<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LinhaBaseAtividadesExport implements FromCollection, WithHeadings, WithMapping
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
            'ID', 'Tarefa', 'Disciplina', 'Duração LB', 'Início LB', 'Término LB',
            'Caminho Crítico', 'Restrições',
        ];
    }

    /**
     * @param  array{atividade: \App\Models\Atividade, inicioBaseline: ?\Carbon\Carbon, terminoBaseline: ?\Carbon\Carbon, duracaoBaseline: ?int}  $linha
     */
    public function map($linha): array
    {
        $at = $linha['atividade'];

        return [
            $at->external_uid ?? '—',
            $at->nome,
            $at->disciplina?->nome ?? '—',
            $linha['duracaoBaseline'] ?? '—',
            $linha['inicioBaseline']?->format('d/m/Y') ?? '—',
            $linha['terminoBaseline']?->format('d/m/Y') ?? '—',
            $at->caminho_critico ? 'Sim' : 'Não',
            $at->restricoes_count,
        ];
    }
}
