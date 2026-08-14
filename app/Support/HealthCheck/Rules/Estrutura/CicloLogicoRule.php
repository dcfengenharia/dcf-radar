<?php

namespace App\Support\HealthCheck\Rules\Estrutura;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\HealthCheckRegraEstruturalInterface;

/**
 * STRUCT-005 — ciclos no grafo direcionado (predecessora → sucessora),
 * detectados via HealthCheckGrafoCronograma::ciclos() (Tarjan iterativo,
 * SCCs não triviais). Gera 1 finding POR ciclo identificado — nunca 1
 * finding agregando todos, mesmo padrão de STRUCT-004.
 *
 * Severidade Crítico: um ciclo lógico impede o cálculo de datas/caminho
 * crítico/folgas de qualquer ferramenta de CPM (incluindo o próprio MS
 * Project) — não é opinião, é uma inconsistência matematicamente
 * impossível de resolver sem quebrar o ciclo.
 */
final class CicloLogicoRule implements HealthCheckRegraEstruturalInterface
{
    public function id(): string
    {
        return 'STRUCT-005';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        $ciclos = $grafo->ciclos();

        if (empty($ciclos)) {
            return [];
        }

        $findings = [];

        foreach ($ciclos as $indice => $uids) {
            $tarefas = array_values(array_filter(array_map(
                fn (string $uid) => $grafo->tarefa($uid),
                $uids
            )));

            $findings[] = new HealthCheckFinding(
                regraId: $this->id(),
                categoria: HealthCheckCategoria::Estrutura,
                severidade: HealthCheckSeveridade::Critico,
                titulo: 'Ciclo lógico identificado no cronograma',
                descricao: sprintf(
                    'Foi identificado um ciclo de dependências envolvendo %d atividade(s) — uma cadeia de predecessoras/sucessoras que retorna a si mesma (%d ciclo(s) identificado(s) no total).',
                    count($uids),
                    count($ciclos)
                ),
                impacto: 'Um ciclo lógico impede o cálculo correto de datas, caminho crítico e folgas — ferramentas de CPM (incluindo o MS Project) não conseguem processar corretamente dependências circulares.',
                recomendacao: 'Revise as relações de predecessora/sucessora das atividades listadas e remova ou corrija o vínculo que fecha o ciclo.',
                atividades: [[
                    'ciclo_id' => $indice + 1,
                    'atividades' => array_map(fn ($t) => [
                        'uid' => $t->uid,
                        'codigo' => $t->codigo,
                        'nome' => $t->nome,
                    ], $tarefas),
                    'relacoes' => $this->relacoesDoCiclo($grafo, $uids),
                ]],
            );
        }

        return $findings;
    }

    /**
     * Relações internas ao ciclo — quando a SCC tem mais de um ciclo
     * elementar entrelaçado, esta lista traz TODAS as arestas internas
     * (não só as de um único caminho fechado), o que é a representação
     * "quando possível" pedida sem precisar enumerar cada ciclo elementar
     * individualmente (custo potencialmente exponencial — ver
     * HealthCheckGrafoCronograma::ciclos()).
     *
     * @param string[] $uids
     * @return array<int, array{de: string, para: string}>
     */
    private function relacoesDoCiclo(HealthCheckGrafoCronograma $grafo, array $uids): array
    {
        $doCiclo = array_flip($uids);
        $relacoes = [];

        foreach ($uids as $uid) {
            foreach ($grafo->sucessorasDe($uid) as $sucessoraUid) {
                if (isset($doCiclo[$sucessoraUid])) {
                    $relacoes[] = ['de' => $uid, 'para' => $sucessoraUid];
                }
            }
        }

        return $relacoes;
    }
}
