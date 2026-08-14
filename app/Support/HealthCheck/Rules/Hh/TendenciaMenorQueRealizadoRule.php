<?php

namespace App\Support\HealthCheck\Rules\Hh;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class TendenciaMenorQueRealizadoRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'WORK-001';
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
        return HealthCheckSeveridade::Alto;
    }

    public function titulo(): string
    {
        return 'HH de tendência menor que o HH realizado';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) em que o HH de tendência (Work) é menor que o HH já realizado (Actual Work).";
    }

    public function impacto(): string
    {
        return 'Matematicamente, o HH restante ficaria negativo — sinal de que a tendência não foi atualizada após o apontamento do realizado.';
    }

    public function recomendacao(): string
    {
        return 'Revisar e atualizar o campo Work (tendência) no MS Project para refletir pelo menos o HH já realizado.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->workHoras < $tarefa->realHoras - 0.01;
    }
}
