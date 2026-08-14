<?php

namespace App\Support\HealthCheck\Rules\Avanco;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class DeveriaTerIniciadoRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'PROG-002';
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
        return 'Deveria ter iniciado e está em 0%';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) cujo início planejado já passou da data de status, mas seguem em 0% de avanço.";
    }

    public function impacto(): string
    {
        return 'Atividades que ainda não começaram apesar do início planejado já ter passado indicam risco de atraso na frente e em possíveis sucessoras.';
    }

    public function recomendacao(): string
    {
        return 'Verificar se há impedimento para o início dessas atividades e registrar uma restrição, se for o caso.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return !$tarefa->isMarco
            && $tarefa->dataInicio !== null
            && $plano->dataStatus !== null
            && $tarefa->dataInicio->lte($plano->dataStatus)
            && $tarefa->percentualConcluido === 0.0;
    }
}
