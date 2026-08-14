<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;

/**
 * Base para as regras SLACK (Fase 2B.3) que avaliam cada atividade
 * individualmente com base nos valores brutos de `totalSlack`/`freeSlack`
 * (Fase 2A) e agregam as que combinarem num único HealthCheckFinding —
 * mesmo espírito de `RegraHealthCheckEstruturalPorAtividadeBase`
 * (STRUCT-001/002/003), mas sem depender de `HealthCheckGrafoCronograma`:
 * folga é um valor já calculado pelo próprio MS Project por atividade,
 * não uma propriedade da rede que reconstruímos via PredecessorLink — por
 * isso não faz sentido reaproveitar o grafo nem sua guarda de "sem
 * nenhuma relação capturada" (ver CLAUDE.md, Fase 2B.3).
 *
 * Filtros aplicados a TODAS as regras SLACK (decisão do usuário):
 * - Tarefas-resumo (`isSummary`) são SEMPRE ignoradas — os valores de
 *   folga de uma tarefa-resumo são calculados a partir das datas
 *   adiantada/tardia herdadas de até 4 subtarefas possivelmente
 *   desconectadas entre si, sem significado lógico (achado documentado
 *   no diagnóstico da Fase 2B.3).
 * - Tarefas inativas (`!ativa`) são SEMPRE ignoradas — valores congelados
 *   de antes da desativação, mesmo tratamento já usado em STRUCT-* e LOGIC-*.
 * - Marcos NÃO são ignorados — participam do cálculo de CPM normalmente.
 * - Tarefas sem predecessora/sucessora capturada NÃO são excluídas por
 *   esse motivo — SLACK analisa o resultado que o MS Project já calculou
 *   internamente, independente de termos reconstruído a rede via
 *   PredecessorLink.
 *
 * Ausência (`null`) de `totalSlack`/`freeSlack` NUNCA é tratada como zero
 * — cada regra concreta decide em `combina()` quando o dado necessário
 * está ausente (retornando `false`, nunca inferindo um valor).
 */
abstract class RegraHealthCheckSlackPorAtividadeBase implements HealthCheckRegraEstruturalInterface
{
    abstract public function id(): string;

    abstract public function severidade(): HealthCheckSeveridade;

    abstract public function titulo(): string;

    abstract public function descricao(int $quantidade): string;

    abstract public function impacto(): string;

    abstract public function recomendacao(): string;

    /** Predicado — true quando esta tarefa é uma ocorrência da regra. Deve retornar false quando o(s) valor(es) de folga necessário(s) estiver(em) ausente(s) (null). */
    abstract protected function combina(TarefaImportada $tarefa): bool;

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        $afetadas = [];

        foreach ([...$plano->criar, ...$plano->atualizar] as $tarefa) {
            if ($tarefa->isSummary || !$tarefa->ativa) {
                continue;
            }

            if ($this->combina($tarefa)) {
                $afetadas[] = [
                    'uid' => $tarefa->uid,
                    'codigo' => $tarefa->codigo,
                    'nome' => $tarefa->nome,
                    'total_slack' => $tarefa->totalSlack,
                    'free_slack' => $tarefa->freeSlack,
                ];
            }
        }

        if (empty($afetadas)) {
            return [];
        }

        return [new HealthCheckFinding(
            regraId: $this->id(),
            categoria: HealthCheckCategoria::Slack,
            severidade: $this->severidade(),
            titulo: $this->titulo(),
            descricao: $this->descricao(count($afetadas)),
            impacto: $this->impacto(),
            recomendacao: $this->recomendacao(),
            atividades: $afetadas,
        )];
    }
}
