<?php

namespace App\Support\HealthCheck\Rules\Duracao;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class DuracaoZeroRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'DUR-001';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Duracao;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Medio;
    }

    public function titulo(): string
    {
        return 'Duração zero (atividade não é marco)';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) com início e término iguais, mas que não estão marcadas como marco.";
    }

    public function impacto(): string
    {
        return 'Uma atividade de duração zero que não é marco costuma ser erro de configuração no cronograma de origem.';
    }

    public function recomendacao(): string
    {
        return 'Confirmar se a atividade deveria ser marcada como marco (Milestone) ou se as datas precisam de ajuste.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return !$tarefa->isMarco
            && $tarefa->dataInicio !== null
            && $tarefa->dataTermino !== null
            && $tarefa->dataInicio->equalTo($tarefa->dataTermino);
    }
}
