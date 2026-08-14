<?php

namespace App\Support\HealthCheck\Rules\Datas;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class ConcluidaSemTerminoRealRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'DATE-003';
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
        return '100% concluída sem data de término real';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) marcadas como 100% concluídas sem uma data de término real registrada.";
    }

    public function impacto(): string
    {
        return 'Sem o término real, não é possível confirmar quando a atividade foi de fato concluída — afeta o histórico de PPC e qualquer análise de prazo real x planejado.';
    }

    public function recomendacao(): string
    {
        return 'Preencher a data de término real (Actual Finish) no MS Project para as atividades já concluídas.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->percentualConcluido !== null
            && $tarefa->percentualConcluido >= 100
            && $tarefa->realTermino === null;
    }
}
