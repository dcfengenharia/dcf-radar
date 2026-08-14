<?php

namespace App\Support\HealthCheck\Rules\Hh;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class HhRealizadoSemInicioRealRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'WORK-004';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Execucao;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Hh;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Medio;
    }

    public function titulo(): string
    {
        return 'HH realizado sem data de início real';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) com HH realizado maior que zero, mas sem data de início real registrada.";
    }

    public function impacto(): string
    {
        return 'Horas apontadas sem uma data de início real dificultam rastrear quando o trabalho de fato começou.';
    }

    public function recomendacao(): string
    {
        return 'Preencher a data de início real (Actual Start) para essas atividades no cronograma de origem.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->realHoras > 0 && $tarefa->realInicio === null;
    }
}
