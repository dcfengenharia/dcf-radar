<?php

namespace App\Exports;

use App\Models\Restricao;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RestricoesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $restricoes)
    {
    }

    public function collection(): Collection
    {
        return $this->restricoes;
    }

    public function headings(): array
    {
        return [
            'Atividade', 'Descrição', 'Tipo', 'Bloqueante', 'Responsável',
            'Disciplina', 'Frente de Trabalho', 'Prazo', 'P×I', 'Status',
            'Aberta em', 'Resolvida em',
        ];
    }

    public function map($r): array
    {
        /** @var Restricao $r */
        return [
            $r->atividade?->nome ?? '—',
            $r->descricao,
            $r->categoria?->nome ?? '—',
            $r->bloqueante ? 'Sim' : 'Não',
            $r->responsavel
                ? "{$r->responsavel->first_name} {$r->responsavel->last_name}"
                : ($r->responsavel_externo ?: '—'),
            $r->atividade?->disciplina?->nome ?? '—',
            $r->atividade?->frenteTrabalho?->nome ?? '—',
            $r->prazo_limite?->format('d/m/Y') ?? '—',
            $r->probabilidade !== null && $r->impacto !== null ? "{$r->probabilidade}×{$r->impacto}" : '—',
            match ($r->status->value ?? $r->status) {
                'aberta' => 'Aberta',
                'em_tratamento' => 'Em Tratamento',
                'aguardando_terceiros' => 'Ag. Terceiros',
                'resolvida' => 'Resolvida',
                default => $r->status->value ?? $r->status,
            },
            $r->aberta_em?->format('d/m/Y H:i') ?? '—',
            $r->resolvida_em?->format('d/m/Y H:i') ?? '—',
        ];
    }
}
