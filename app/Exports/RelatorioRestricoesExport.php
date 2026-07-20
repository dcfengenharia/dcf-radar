<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Uma aba por indicador do Relatório de Restrições (⚡relatorios-restricoes.blade.php).
 * Diferente de RestricoesExport (linha-a-linha), aqui os dados já chegam
 * agregados — cada aba é só um array de linhas já calculado pelo componente.
 */
class RelatorioRestricoesExport implements WithMultipleSheets
{
    public function __construct(private readonly array $dados)
    {
    }

    public function sheets(): array
    {
        return [
            $this->folha(
                'Por Responsável',
                ['Responsável', 'Total', 'Abertas', 'Bloqueantes'],
                collect($this->dados['porResponsavel'])->map(fn ($r) => [$r['nome'], $r['total'], $r['abertas'], $r['bloqueantes']])->all()
            ),
            $this->folha(
                'Por Período',
                ['Período', 'Total', 'Resolvidas'],
                collect($this->dados['porPeriodo'])->map(fn ($r) => [$r['label'], $r['total'], $r['resolvidas']])->all()
            ),
            $this->folha(
                'Atrasadas',
                ['Atividade', 'Descrição', 'Responsável', 'Prazo Limite', 'Dias em Atraso'],
                $this->dados['atrasadas']
            ),
            $this->folha(
                'Tempo de Resolução',
                ['Categoria', 'Média de Dias', 'Restrições Resolvidas'],
                collect($this->dados['tempoMedioResolucao']['porCategoria'])->map(fn ($r) => [$r['categoria'], $r['mediaDias'], $r['total']])->all()
            ),
            $this->folha(
                'Prontidão por Disciplina',
                ['Disciplina', 'Total Itens', 'Concluídos', '% Concluído'],
                collect($this->dados['prontidaoPorDisciplina'])->map(fn ($r) => [$r['disciplina'], $r['total'], $r['concluidos'], $r['percentual'] . '%'])->all()
            ),
            $this->folha(
                'Por Pilar Lean',
                ['Pilar', 'Total', 'Abertas'],
                collect($this->dados['porPilar'])->map(fn ($r) => [$r['label'], $r['total'], $r['abertas']])->all()
            ),
            $this->folha(
                'Por Categoria',
                ['Categoria', 'Total', 'Abertas'],
                collect($this->dados['porCategoria'])->map(fn ($r) => [$r['categoria'], $r['total'], $r['abertas']])->all()
            ),
            $this->folha(
                'Status Geral',
                ['Status', 'Total'],
                collect($this->dados['statusGeral'])->map(fn ($r) => [$r['status'], $r['total']])->all()
            ),
            $this->folha(
                'Distribuição de Risco',
                ['Risco', 'Total'],
                collect($this->dados['riscoDistribuicao'])->map(fn ($r) => [$r['label'], $r['total']])->all()
            ),
            $this->folha(
                'PPC Aderência Semanal',
                ['Semana', 'Comprometidas', 'Concluídas no Prazo', 'PPC %'],
                collect($this->dados['ppcPorSemana'])->map(fn ($r) => [$r['semana_label'], $r['comprometidas'], $r['concluidas_no_prazo'], $r['ppc_percentual'] . '%'])->all()
            ),
        ];
    }

    private function folha(string $titulo, array $cabecalho, array $linhas)
    {
        return new class ($titulo, $cabecalho, $linhas) implements FromArray, WithHeadings, WithTitle {
            public function __construct(
                private readonly string $titulo,
                private readonly array $cabecalho,
                private readonly array $linhas,
            ) {
            }

            public function array(): array
            {
                return $this->linhas;
            }

            public function headings(): array
            {
                return $this->cabecalho;
            }

            public function title(): string
            {
                return $this->titulo;
            }
        };
    }
}
