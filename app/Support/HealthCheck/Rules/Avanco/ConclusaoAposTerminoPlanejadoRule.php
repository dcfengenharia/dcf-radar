<?php

namespace App\Support\HealthCheck\Rules\Avanco;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class ConclusaoAposTerminoPlanejadoRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'PROG-003';
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
        return HealthCheckSeveridade::Medio;
    }

    public function titulo(): string
    {
        return 'Conclusão real posterior ao término planejado';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) concluídas depois da data planejada de término (atraso na conclusão).";
    }

    public function impacto(): string
    {
        return 'Confirma um atraso já concretizado — útil para medir aderência real do planejamento, mesmo sem afetar o cronograma futuro.';
    }

    public function recomendacao(): string
    {
        return 'Registrar a causa do atraso, se ainda não estiver documentada, para análise histórica de aderência.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->realTermino !== null
            && $tarefa->dataTermino !== null
            && $tarefa->realTermino->gt($tarefa->dataTermino);
    }
}
