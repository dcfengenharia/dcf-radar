<?php

namespace App\Support\HealthCheck\Rules\Hh;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class ConcluidaComHhZeradoRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'WORK-003';
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
        return '100% concluída com HH realizado zero';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) marcadas como 100% concluídas sem nenhum HH realizado registrado.";
    }

    public function impacto(): string
    {
        return 'Uma atividade concluída sem HH lançado indica falha no apontamento de horas — afeta diretamente as curvas S de realizado.';
    }

    public function recomendacao(): string
    {
        return 'Conferir o apontamento de Actual Work dos recursos alocados nessa atividade.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return !$tarefa->isMarco
            && $tarefa->percentualConcluido !== null
            && $tarefa->percentualConcluido >= 100
            && $tarefa->realHoras <= 0;
    }
}
