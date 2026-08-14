<?php

namespace App\Support\HealthCheck\Rules\Datas;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class TerminoRealAntesDoInicioRealRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'DATE-006';
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
        return HealthCheckSeveridade::Critico;
    }

    public function titulo(): string
    {
        return 'Término real anterior ao início real';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) cuja data de término real é anterior à data de início real.";
    }

    public function impacto(): string
    {
        return 'Datas reais invertidas indicam erro de apontamento — a atividade não pode ter terminado antes de começar.';
    }

    public function recomendacao(): string
    {
        return 'Revisar os apontamentos de Actual Start/Actual Finish dessas atividades no cronograma de origem.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->realInicio !== null
            && $tarefa->realTermino !== null
            && $tarefa->realTermino->lt($tarefa->realInicio);
    }
}
