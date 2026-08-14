<?php

namespace App\Support\HealthCheck\Rules\Datas;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class ZeroPorCentoComTerminoRealRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'DATE-007';
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
        return 'Tarefa com 0% de conclusão e término real informado';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram identificadas {$quantidade} atividade(s) com 0% de conclusão e data de término real informada.";
    }

    public function impacto(): string
    {
        return 'Essa combinação de informações é inconsistente e pode distorcer a análise de avanço, produtividade e status da atividade.';
    }

    public function recomendacao(): string
    {
        return 'Verifique o percentual de conclusão e a data de término real informados no cronograma de origem.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->percentualConcluido === 0.0 && $tarefa->realTermino !== null;
    }
}
