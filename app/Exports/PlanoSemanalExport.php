<?php

namespace App\Exports;

use App\Models\Atividade;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PlanoSemanalExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $atividades)
    {
    }

    public function collection(): Collection
    {
        return $this->atividades;
    }

    public function headings(): array
    {
        return [
            'Atividade', 'Frente de Trabalho', 'Disciplina', 'Responsável',
            'Início Planejado', 'Término Planejado', 'Pronta', 'Status',
        ];
    }

    /**
     * @param  Atividade  $atividade
     */
    public function map($atividade): array
    {
        return [
            $atividade->nome,
            $atividade->pacoteTrabalho?->nome ?? '—',
            $atividade->disciplina?->nome ?? '—',
            $atividade->responsavel ? "{$atividade->responsavel->first_name} {$atividade->responsavel->last_name}" : '—',
            $atividade->inicio_planejado?->format('d/m/Y') ?? '—',
            $atividade->data_termino?->format('d/m/Y') ?? '—',
            $atividade->estaPronta() ? 'Sim' : 'Não',
            $atividade->status->value,
        ];
    }
}
