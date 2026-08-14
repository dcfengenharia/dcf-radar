<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;

/**
 * Grafo lógico do cronograma (predecessora → sucessora), construído UMA
 * ÚNICA VEZ por avaliação de Health Check (App\Support\HealthCheck\
 * HealthCheckEngine::avaliar()) e reaproveitado por todas as regras
 * estruturais da Fase 2B — nunca reconstruído regra a regra. Complexidade
 * de construção e das consultas agregadas (componentes/ciclos): O(V + E).
 *
 * Nós — só entram no grafo atividades EXECUTÁVEIS e ATIVAS
 * (`!isSummary && ativa`):
 * - Tarefas-resumo/projeto NUNCA são nó — e relações que apontam pra elas
 *   são ignoradas (não contam como predecessora/sucessora de ninguém).
 * - Atividades inativas nunca são nó — uma atividade ativa cuja única
 *   predecessora está inativa aparece com grau de entrada 0 (mesmo
 *   tratamento de "predecessora ausente"), sem alerta especial nesta fase.
 * - Marcos SÃO nós normais — decisão do usuário (Fase 2B.1): nenhuma
 *   heurística especial pra marco ("não quero heurísticas complexas
 *   nesta etapa"), tratado exatamente como qualquer atividade executável
 *   pelas regras STRUCT-001/002/003.
 *
 * Arestas — direcionadas (predecessora → sucessora), construídas a partir
 * de `TarefaImportada::$predecessoras` (Fase 2A) — só quando AMBAS as
 * pontas já estão no conjunto de nós acima.
 */
final class HealthCheckGrafoCronograma
{
    /** @var array<string, TarefaImportada> uid => tarefa (só nós do grafo) */
    private array $tarefas = [];

    /** @var array<string, array<string, true>> uid => set de uids predecessoras diretas */
    private array $predecessorasDe = [];

    /** @var array<string, array<string, true>> uid => set de uids sucessoras diretas */
    private array $sucessorasDe = [];

    private int $totalArestas = 0;

    /** @var array<int, string[]>|null cache de componentes conectados (conectividade NÃO direcionada — ver nota em componentes()) */
    private ?array $componentesCache = null;

    /** @var array<int, string[]>|null cache de ciclos (SCCs não triviais do grafo direcionado) */
    private ?array $ciclosCache = null;

    private function __construct() {}

    public static function construir(PlanoImportacao $plano): self
    {
        $grafo = new self();

        foreach ([...$plano->criar, ...$plano->atualizar] as $tarefa) {
            if ($tarefa->isSummary || !$tarefa->ativa) {
                continue;
            }
            $grafo->tarefas[$tarefa->uid] = $tarefa;
            $grafo->predecessorasDe[$tarefa->uid] ??= [];
            $grafo->sucessorasDe[$tarefa->uid] ??= [];
        }

        foreach ($grafo->tarefas as $uid => $tarefa) {
            foreach ($tarefa->predecessoras as $link) {
                $predUid = $link->predecessoraUid;

                // Predecessora fora do grafo (tarefa-resumo, inativa, ou UID que
                // não existe nesta importação) — relação ignorada silenciosamente,
                // conforme decisão documentada em CLAUDE.md.
                if (!isset($grafo->tarefas[$predUid])) {
                    continue;
                }

                if (isset($grafo->predecessorasDe[$uid][$predUid])) {
                    continue; // múltiplos links pra mesma predecessora contam como 1 aresta
                }

                $grafo->predecessorasDe[$uid][$predUid] = true;
                $grafo->sucessorasDe[$predUid][$uid] = true;
                $grafo->totalArestas++;
            }
        }

        return $grafo;
    }

    /** @return TarefaImportada[] uid => tarefa, só nós do grafo */
    public function tarefas(): array
    {
        return $this->tarefas;
    }

    public function tarefa(string $uid): ?TarefaImportada
    {
        return $this->tarefas[$uid] ?? null;
    }

    /** @return string[] uids das predecessoras diretas dentro do grafo */
    public function predecessorasDe(string $uid): array
    {
        return array_keys($this->predecessorasDe[$uid] ?? []);
    }

    /** @return string[] uids das sucessoras diretas dentro do grafo */
    public function sucessorasDe(string $uid): array
    {
        return array_keys($this->sucessorasDe[$uid] ?? []);
    }

    public function grauEntrada(string $uid): int
    {
        return count($this->predecessorasDe[$uid] ?? []);
    }

    public function grauSaida(string $uid): int
    {
        return count($this->sucessorasDe[$uid] ?? []);
    }

    public function totalArestas(): int
    {
        return $this->totalArestas;
    }

    /**
     * Verdadeiro quando NENHUMA atividade do plano tem qualquer relação de
     * precedência capturada — sinal de que o arquivo de origem
     * provavelmente não popula esse campo do MSPDI (comum em exportações
     * de outras ferramentas convertidas pra MSPDI), não que cada atividade
     * individualmente esteja isolada. Usado pra suprimir ruído de
     * STRUCT-001/002/003 nesse cenário degenerado — ver
     * RegraHealthCheckEstruturalPorAtividadeBase e a nota em CLAUDE.md.
     */
    public function semNenhumaRelacaoCapturada(): bool
    {
        return $this->totalArestas === 0;
    }

    /**
     * Componentes conectados usando conectividade NÃO DIRECIONADA — decisão
     * documentada: A→B→C é 1 componente só, mesmo a direção sendo relevante
     * pra predecessora/sucessora/ciclos. União por Union-Find (path
     * compression), O(V + E), sem recursão.
     *
     * @return array<int, string[]> lista de componentes, cada um uma lista de uids
     */
    public function componentes(): array
    {
        return $this->componentesCache ??= $this->calcularComponentes();
    }

    /**
     * Ciclos do grafo DIRECIONADO — cada "ciclo" é uma componente fortemente
     * conexa (SCC) não trivial: tamanho ≥ 2, ou um único nó com auto-laço
     * (a tarefa lista a si mesma como predecessora — caso degenerado, mas
     * detectado corretamente). Calculado via Tarjan iterativo (sem
     * recursão — seguro pra cronogramas de milhares de atividades), O(V + E).
     *
     * Uma SCC pode agregar mais de um ciclo elementar quando há múltiplos
     * caminhos fechados compartilhando nós — enumerar cada ciclo elementar
     * separadamente teria custo potencialmente exponencial; agrupar por SCC
     * é a abordagem seguro e eficiente escolhida nesta fase (ver CLAUDE.md).
     *
     * @return array<int, string[]> lista de ciclos, cada um uma lista de uids envolvidos
     */
    public function ciclos(): array
    {
        return $this->ciclosCache ??= $this->calcularCiclos();
    }

    /** @return array<int, string[]> */
    private function calcularComponentes(): array
    {
        $pai = [];
        foreach (array_keys($this->tarefas) as $uid) {
            $pai[$uid] = $uid;
        }

        if (empty($pai)) {
            return [];
        }

        $find = function (string $x) use (&$pai, &$find) {
            while ($pai[$x] !== $x) {
                $pai[$x] = $pai[$pai[$x]];
                $x = $pai[$x];
            }
            return $x;
        };

        foreach ($this->predecessorasDe as $uid => $preds) {
            foreach (array_keys($preds) as $predUid) {
                $ra = $find($uid);
                $rb = $find($predUid);
                if ($ra !== $rb) {
                    $pai[$ra] = $rb;
                }
            }
        }

        $grupos = [];
        foreach (array_keys($this->tarefas) as $uid) {
            $raiz = $find($uid);
            $grupos[$raiz][] = $uid;
        }

        return array_values($grupos);
    }

    /** @return array<int, string[]> */
    private function calcularCiclos(): array
    {
        if ($this->totalArestas === 0) {
            return [];
        }

        $index = 0;
        $indices = [];
        $lowlink = [];
        $onStack = [];
        $tarjanStack = [];
        $sccs = [];

        foreach (array_keys($this->tarefas) as $raiz) {
            if (isset($indices[$raiz])) {
                continue;
            }

            $indices[$raiz] = $index;
            $lowlink[$raiz] = $index;
            $index++;
            $tarjanStack[] = $raiz;
            $onStack[$raiz] = true;

            // Pilha de execução iterativa (evita recursão): cada frame é
            // [uid, lista de sucessoras, próximo índice a visitar].
            $frames = [[$raiz, $this->sucessorasDe($raiz), 0]];

            while (!empty($frames)) {
                $topo = count($frames) - 1;
                [$v, $sucessores, $i] = $frames[$topo];

                $desceu = false;
                while ($i < count($sucessores)) {
                    $w = $sucessores[$i];
                    $i++;

                    if (!isset($indices[$w])) {
                        $frames[$topo][2] = $i;
                        $indices[$w] = $index;
                        $lowlink[$w] = $index;
                        $index++;
                        $tarjanStack[] = $w;
                        $onStack[$w] = true;
                        $frames[] = [$w, $this->sucessorasDe($w), 0];
                        $desceu = true;
                        break;
                    }

                    if (!empty($onStack[$w])) {
                        $lowlink[$v] = min($lowlink[$v], $indices[$w]);
                    }
                }

                if ($desceu) {
                    continue;
                }

                $frames[$topo][2] = $i;
                array_pop($frames);

                if (!empty($frames)) {
                    $paiUid = $frames[count($frames) - 1][0];
                    $lowlink[$paiUid] = min($lowlink[$paiUid], $lowlink[$v]);
                }

                if ($lowlink[$v] === $indices[$v]) {
                    $scc = [];
                    do {
                        $w = array_pop($tarjanStack);
                        $onStack[$w] = false;
                        $scc[] = $w;
                    } while ($w !== $v);

                    if (count($scc) > 1 || isset($this->sucessorasDe[$scc[0]][$scc[0]])) {
                        $sccs[] = $scc;
                    }
                }
            }
        }

        return $sccs;
    }
}
