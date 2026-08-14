<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;

/**
 * Base para regras que avaliam cada TarefaImportada (folha) individualmente
 * dentro de $plano->criar + $plano->atualizar e agregam as que combinarem
 * num único HealthCheckFinding. Cobre a maioria das regras de Datas/Avanço/
 * HH/Duração/Marcos — as poucas regras "globais" (ex.: nenhuma atividade em
 * caminho crítico) ou que não operam sobre TarefaImportada (ex.: atividades
 * arquivadas, que só existem como nome/id do banco) implementam
 * HealthCheckRuleInterface diretamente, sem estender esta classe.
 */
abstract class RegraHealthCheckBase implements HealthCheckRuleInterface
{
    abstract public function id(): string;

    abstract public function categoria(): HealthCheckCategoria;

    abstract public function severidade(): HealthCheckSeveridade;

    abstract public function titulo(): string;

    abstract public function descricao(int $quantidade): string;

    abstract public function impacto(): string;

    abstract public function recomendacao(): string;

    /** Predicado — true quando esta tarefa é uma ocorrência da regra. */
    abstract protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool;

    public function bloqueante(): bool
    {
        return false;
    }

    public function avaliar(PlanoImportacao $plano): ?HealthCheckFinding
    {
        $afetadas = [];

        foreach ([...$plano->criar, ...$plano->atualizar] as $tarefa) {
            if ($this->combina($tarefa, $plano)) {
                $afetadas[] = $this->serializarTarefa($tarefa);
            }
        }

        if (empty($afetadas)) {
            return null;
        }

        return new HealthCheckFinding(
            regraId: $this->id(),
            categoria: $this->categoria(),
            severidade: $this->severidade(),
            titulo: $this->titulo(),
            descricao: $this->descricao(count($afetadas)),
            impacto: $this->impacto(),
            recomendacao: $this->recomendacao(),
            atividades: $afetadas,
        );
    }

    protected function serializarTarefa(TarefaImportada $tarefa): array
    {
        return [
            'uid' => $tarefa->uid,
            'codigo' => $tarefa->codigo,
            'nome' => $tarefa->nome,
            'data_inicio' => $tarefa->dataInicio?->toDateString(),
            'data_termino' => $tarefa->dataTermino?->toDateString(),
            'baseline_inicio' => $tarefa->baselineInicio?->toDateString(),
            'baseline_termino' => $tarefa->baselineTermino?->toDateString(),
            'real_inicio' => $tarefa->realInicio?->toDateString(),
            'real_termino' => $tarefa->realTermino?->toDateString(),
            'percentual_concluido' => $tarefa->percentualConcluido,
            'baseline_horas' => $tarefa->baselineHoras,
            'work_horas' => $tarefa->workHoras,
            'real_horas' => $tarefa->realHoras,
        ];
    }
}
