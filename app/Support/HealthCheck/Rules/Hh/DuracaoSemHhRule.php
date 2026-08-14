<?php

namespace App\Support\HealthCheck\Rules\Hh;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class DuracaoSemHhRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'WORK-006';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Hh;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Baixo;
    }

    public function titulo(): string
    {
        return 'Atividade com duração e sem nenhum HH atribuído';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) com datas de início e término definidas, mas sem nenhum HH (baseline ou tendência) atribuído.";
    }

    public function impacto(): string
    {
        return 'Sem HH atribuído, essa atividade não aparece nas curvas S — pode ser proposital (atividade sem recurso de trabalho) ou uma atribuição esquecida.';
    }

    public function recomendacao(): string
    {
        return 'Confirmar se a atividade realmente não precisa de recurso de trabalho atribuído; se precisar, revisar as Assignments no MS Project.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return !$tarefa->isMarco
            && $tarefa->dataInicio !== null
            && $tarefa->dataTermino !== null
            && !$tarefa->dataInicio->equalTo($tarefa->dataTermino)
            && $tarefa->workHoras <= 0
            && $tarefa->baselineHoras <= 0;
    }
}
