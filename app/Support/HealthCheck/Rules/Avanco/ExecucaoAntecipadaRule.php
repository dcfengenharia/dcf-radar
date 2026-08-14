<?php

namespace App\Support\HealthCheck\Rules\Avanco;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class ExecucaoAntecipadaRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'PROG-004';
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
        return HealthCheckSeveridade::Informativo;
    }

    public function titulo(): string
    {
        return 'Execução antecipada em relação à linha de base';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) cujo início real ocorreu antes do início planejado na linha de base.";
    }

    public function impacto(): string
    {
        return 'Não é necessariamente um problema — pode indicar ganho de prazo real. Vale confirmar se a linha de base ainda reflete o planejamento vigente.';
    }

    public function recomendacao(): string
    {
        return 'Nenhuma ação obrigatória — apenas informativo, útil para identificar frentes adiantadas.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->realInicio !== null
            && $tarefa->baselineInicio !== null
            && $tarefa->realInicio->lt($tarefa->baselineInicio);
    }
}
