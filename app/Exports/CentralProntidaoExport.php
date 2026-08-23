<?php

namespace App\Exports;

use App\Support\CentralProntidao\AtividadeProntidaoView;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Central de Prontidão (Ciclo 15, Etapa B.4) — uma aba por seção, mesmo
 * padrão de RelatorioRestricoesExport (dados já agregados/consolidados
 * pela CentralProntidaoQuery/componente, nenhuma query própria aqui).
 *
 * Recebe o MESMO array montado por `⚡central-prontidao.blade.php::dadosExport()`
 * — reaproveitado tal e qual pelo PDF (`exports.central-prontidao-pdf`),
 * nenhuma lógica de agregação duplicada entre os dois formatos.
 *
 * `$dados['views']` é a Collection<AtividadeProntidaoView> já retornada por
 * `CentralProntidaoQuery::paraObra()` — este export só itera e achata os
 * campos já prontos de cada DTO (Restrição/Checklist/PlanoAcao/Suprimento/
 * Engenharia), nunca reimplementa a classificação de prontidão nem acessa
 * relações Eloquent (todos os campos lidos aqui são scalar/array/enum já
 * materializados no DTO — mesma garantia de ausência de N+1 já comprovada
 * pelo partial de detalhe da B.3).
 */
class CentralProntidaoExport implements WithMultipleSheets
{
    public function __construct(private readonly array $dados)
    {
    }

    public function sheets(): array
    {
        return [
            $this->folhaResumo(),
            $this->folhaAtividades(),
            $this->folhaRestricoesBloqueantes(),
            $this->folhaRestricoesNaoBloqueantes(),
            $this->folhaChecklistPendente(),
            $this->folhaPlanoAcao(),
            $this->folhaSuprimentos(),
            $this->folhaEngenharia(),
            $this->folhaDocumentosEngenhariaBloqueantes(),
        ];
    }

    private function folhaResumo()
    {
        $filtros = $this->dados['filtros'];
        $resumo = $this->dados['resumo'];

        $linhas = [
            ['Obra', $this->dados['obra']->name],
            ['Gerado em', $this->dados['geradoEm']->format('d/m/Y H:i')],
            ['Horizonte', $this->dados['horizonteLabel']],
            ['Pacote/EAP', $filtros['pacote'] ?? 'Todos'],
            ['Disciplina', $filtros['disciplina'] ?? 'Todas'],
            ['Frente', $filtros['frente'] ?? 'Todas'],
            ['Responsável', $filtros['responsavel'] ?? 'Todos'],
            ['Busca', $filtros['busca'] ?? '—'],
            ['Total de Atividades', $resumo['total']],
            ['Prontas', $resumo['pronta']],
            ['Atenção', $resumo['atencao']],
            ['Não Prontas', $resumo['nao_pronta']],
            ['Concluídas', $resumo['concluida']],
        ];

        return $this->folha('Resumo', ['Campo', 'Valor'], $linhas);
    }

    private function folhaAtividades()
    {
        $linhas = $this->views()
            ->map(fn (AtividadeProntidaoView $v) => [
                $v->codigoCronograma ?? '—',
                $v->nome,
                $v->pacoteNome ?? '—',
                $v->disciplinaNome ?? '—',
                $v->frenteNome ?? '—',
                $v->responsavelNome ?? '—',
                $v->inicioPlanejado?->format('d/m/Y') ?? '—',
                $v->statusOperacional->label(),
                empty($v->resumoMotivos) ? '—' : implode('; ', $v->resumoMotivos),
            ])
            ->all();

        return $this->folha(
            'Atividades',
            ['Código', 'Atividade', 'Pacote/EAP', 'Disciplina', 'Frente', 'Responsável', 'Início Planejado', 'Status', 'Motivos'],
            $linhas
        );
    }

    private function folhaRestricoesBloqueantes()
    {
        $linhas = [];
        foreach ($this->views() as $v) {
            foreach ($v->restricoesBloqueantes as $r) {
                $vencida = $r->prazoLimite?->isPast() ?? false;
                $linhas[] = [
                    $v->nome,
                    $r->descricao,
                    $r->responsavel ?? '—',
                    $r->prazoLimite?->format('d/m/Y') ?? '—',
                    $vencida ? 'Sim' : 'Não',
                    $r->origem->label(),
                ];
            }
        }

        return $this->folha(
            'Restrições Bloqueantes',
            ['Atividade', 'Descrição', 'Responsável', 'Prazo', 'Vencida', 'Origem'],
            $linhas
        );
    }

    private function folhaRestricoesNaoBloqueantes()
    {
        $linhas = [];
        foreach ($this->views() as $v) {
            foreach ($v->restricoesNaoBloqueantes as $r) {
                $linhas[] = [
                    $v->nome,
                    $r->descricao,
                    $r->responsavel ?? '—',
                    $r->prazoLimite?->format('d/m/Y') ?? '—',
                    $r->origem->label(),
                ];
            }
        }

        return $this->folha(
            'Restrições Não-Bloqueantes',
            ['Atividade', 'Descrição', 'Responsável', 'Prazo', 'Origem'],
            $linhas
        );
    }

    private function folhaChecklistPendente()
    {
        $linhas = [];
        foreach ($this->views() as $v) {
            foreach ($v->checklistPendentes as $item) {
                $linhas[] = [$v->nome, $item];
            }
        }

        return $this->folha('Checklist Pendente', ['Atividade', 'Item Pendente'], $linhas);
    }

    private function folhaPlanoAcao()
    {
        $linhas = [];
        foreach ($this->views() as $v) {
            foreach ($v->planoAcoesAbertas as $pa) {
                $linhas[] = [
                    $v->nome,
                    $pa->titulo,
                    $pa->regraId,
                    $pa->severidade?->label() ?? '—',
                    $pa->resultadoUltimaReconciliacao?->label() ?? 'Nunca reconciliada',
                ];
            }
        }

        return $this->folha(
            'Plano de Ação',
            ['Atividade', 'Título', 'Regra', 'Severidade', 'Última Reconciliação'],
            $linhas
        );
    }

    private function folhaSuprimentos()
    {
        $linhas = [];
        foreach ($this->views() as $v) {
            foreach ($v->suprimentos as $s) {
                $linhas[] = [
                    $v->nome,
                    $s->nome,
                    $s->status->label(),
                    $s->necessidade?->format('d/m/Y') ?? '—',
                ];
            }
        }

        return $this->folha('Suprimentos', ['Atividade', 'Item', 'Status', 'Necessidade'], $linhas);
    }

    private function folhaEngenharia()
    {
        $linhas = [];
        foreach ($this->views() as $v) {
            foreach ($v->engenharia as $doc) {
                $linhas[] = [
                    $v->nome,
                    $doc->codigo ?? '—',
                    $doc->emitido ? 'Sim' : 'Não',
                    $doc->atrasado ? 'Sim' : 'Não',
                ];
            }
        }

        return $this->folha('Engenharia', ['Atividade', 'Código', 'Emitido', 'Atrasado'], $linhas);
    }

    /**
     * Ciclo 18, Etapa 18.4 — vínculo DIRETO (Ciclo 18.1) não liberado para
     * construção, distinto da folha "Engenharia" acima (via Suprimento).
     */
    private function folhaDocumentosEngenhariaBloqueantes()
    {
        $linhas = [];
        foreach ($this->views() as $v) {
            foreach ($v->documentosBloqueantes as $d) {
                $linhas[] = [
                    $v->nome,
                    $d->codigo ?? '—',
                    $d->descricao ?? '—',
                    $d->revisaoVigente ?? '—',
                    $d->statusDocumental ?? '—',
                    $d->motivo === 'sem_revisao' ? 'Ainda não emitido' : 'Revisão vigente não liberada para construção',
                ];
            }
        }

        return $this->folha(
            'Documentos GED Bloqueantes',
            ['Atividade', 'Código', 'Descrição', 'Revisão Vigente', 'Situação', 'Motivo'],
            $linhas
        );
    }

    /**
     * @return Collection<int, AtividadeProntidaoView>
     */
    private function views(): Collection
    {
        return $this->dados['views'];
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
