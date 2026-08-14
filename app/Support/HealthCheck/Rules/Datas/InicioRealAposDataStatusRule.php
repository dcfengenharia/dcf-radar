<?php

namespace App\Support\HealthCheck\Rules\Datas;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class InicioRealAposDataStatusRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'DATE-001';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Execucao;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Datas;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Alto;
    }

    public function titulo(): string
    {
        return 'Início real registrado após a data de status';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) com início real posterior à data de status do cronograma.";
    }

    public function impacto(): string
    {
        return 'Um início real no futuro (em relação à data de status) distorce a medição do período e os indicadores de desempenho — normalmente indica apontamento no período errado.';
    }

    public function recomendacao(): string
    {
        return 'Verificar se os apontamentos pertencem ao período correto e revisar a data de competência dos dados.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->realInicio !== null
            && $plano->dataStatus !== null
            && $tarefa->realInicio->gt($plano->dataStatus);
    }
}
