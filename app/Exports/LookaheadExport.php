<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LookaheadExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Collection $linhas,
        private readonly bool $temImportacaoAvanco = true,
    ) {
    }

    public function collection(): Collection
    {
        return $this->linhas;
    }

    public function headings(): array
    {
        return [
            'Tarefa', 'Disciplina', 'Frente de Trabalho',
            'Início LB', 'Término LB', 'Início', 'Término', 'Avanço',
            'Restrições Bloqueantes', 'Restrições Não Bloqueantes',
            'Prontidão', 'Status',
        ];
    }

    /**
     * @param  array{atividade: \App\Models\Atividade, inicioTendencia: ?\Carbon\Carbon, terminoTendencia: ?\Carbon\Carbon, inicioBaseline: ?\Carbon\Carbon, terminoBaseline: ?\Carbon\Carbon, restricoesBloq: int, restricoesNaoBloq: int, itensOk: int, totalItens: int, pronta: bool, peso: ?float, percentualRealizado: ?float}  $linha
     */
    public function map($linha): array
    {
        $at = $linha['atividade'];

        return [
            $at->nome,
            $at->disciplina?->nome ?? '—',
            $at->frenteTrabalho?->nome ?? '—',
            $linha['inicioBaseline']?->format('d/m/Y') ?? '—',
            $linha['terminoBaseline']?->format('d/m/Y') ?? '—',
            $this->temImportacaoAvanco ? ($linha['inicioTendencia']?->format('d/m/Y') ?? '—') : 'N/A',
            $this->temImportacaoAvanco ? ($linha['terminoTendencia']?->format('d/m/Y') ?? '—') : 'N/A',
            // Correção pós-QA (Ciclo 17) — NUNCA $at->percentual_concluido (ver
            // mesma correção em ⚡lookahead.blade.php); fonte única é o valor já
            // resolvido pela fotografia de avanço efetiva em atividades().
            $linha['percentualRealizado'] !== null ? number_format($linha['percentualRealizado'], 0) . '%' : '—',
            $linha['restricoesBloq'],
            $linha['restricoesNaoBloq'],
            $linha['totalItens'] > 0 ? "{$linha['itensOk']}/{$linha['totalItens']}" : '—',
            $linha['pronta'] ? 'Pronta' : 'Não pronta',
        ];
    }
}
