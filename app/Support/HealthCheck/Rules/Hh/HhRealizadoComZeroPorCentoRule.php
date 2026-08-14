<?php

namespace App\Support\HealthCheck\Rules\Hh;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class HhRealizadoComZeroPorCentoRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'WORK-005';
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
        return 'HH realizado em atividade com 0% de avanço';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) com HH realizado maior que zero, mas com percentual de avanço em 0%.";
    }

    public function impacto(): string
    {
        return 'HH lançado sem avanço físico correspondente é um sinal clássico de dessincronia entre o apontamento de horas e o % de avanço.';
    }

    public function recomendacao(): string
    {
        return 'Conferir se o percentual de avanço dessa atividade está atualizado no cronograma de origem.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->realHoras > 0 && $tarefa->percentualConcluido === 0.0;
    }
}
