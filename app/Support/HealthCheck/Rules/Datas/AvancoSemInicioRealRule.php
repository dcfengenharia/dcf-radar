<?php

namespace App\Support\HealthCheck\Rules\Datas;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class AvancoSemInicioRealRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'DATE-004';
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
        return 'Avanço registrado sem data de início real';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) com percentual de avanço maior que zero, mas sem data de início real registrada.";
    }

    public function impacto(): string
    {
        return 'Uma atividade não pode ter avançado sem ter começado — sinal de inconsistência entre o percentual e as datas reais lançadas.';
    }

    public function recomendacao(): string
    {
        return 'Preencher a data de início real (Actual Start) no MS Project para as atividades já iniciadas.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->percentualConcluido !== null
            && $tarefa->percentualConcluido > 0
            && $tarefa->realInicio === null;
    }
}
