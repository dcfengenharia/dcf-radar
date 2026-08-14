<?php

namespace App\Support\HealthCheck\Rules\Baseline;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class BaselineIncompletaRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'BASE-003';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Baseline;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Medio;
    }

    public function titulo(): string
    {
        return 'Baseline incompleta';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) sem data de início ou término de linha de base preenchida.";
    }

    public function impacto(): string
    {
        return 'Sem a linha de base, essa atividade fica de fora das curvas S de Previsto e de qualquer comparação planejado x realizado.';
    }

    public function recomendacao(): string
    {
        return 'Confirmar se a linha de base (Baseline) foi salva no MS Project antes de exportar o arquivo.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->baselineInicio === null || $tarefa->baselineTermino === null;
    }
}
