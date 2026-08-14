<?php

namespace App\Support\HealthCheck\Rules\Avanco;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class AtividadeAtrasadaRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'PROG-001';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Execucao;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Avanco;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Alto;
    }

    public function titulo(): string
    {
        return 'Atividade atrasada';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) cujo término planejado já passou da data de status sem estarem 100% concluídas.";
    }

    public function impacto(): string
    {
        return 'Atividades além do prazo planejado, sem conclusão registrada, tendem a empurrar prazos de atividades sucessoras e do projeto como um todo.';
    }

    public function recomendacao(): string
    {
        return 'Revisar o avanço real dessas atividades e replanejar caso o atraso seja confirmado.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return !$tarefa->isMarco
            && $tarefa->dataTermino !== null
            && $plano->dataStatus !== null
            && $tarefa->dataTermino->lt($plano->dataStatus)
            && ($tarefa->percentualConcluido === null || $tarefa->percentualConcluido < 100);
    }
}
