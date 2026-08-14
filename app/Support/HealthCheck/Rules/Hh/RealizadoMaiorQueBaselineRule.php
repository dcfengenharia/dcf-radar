<?php

namespace App\Support\HealthCheck\Rules\Hh;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class RealizadoMaiorQueBaselineRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'WORK-002';
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
        return 'HH realizado maior que a linha de base';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) em que o HH realizado já ultrapassa o HH previsto na linha de base.";
    }

    public function impacto(): string
    {
        return 'Indica estouro de HH em relação ao planejado originalmente — pode ser produtividade abaixo do previsto ou escopo maior que o orçado.';
    }

    public function recomendacao(): string
    {
        return 'Avaliar se o estouro de HH é pontual ou uma tendência que deve ser refletida em futuras estimativas.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->baselineHoras > 0
            && $tarefa->realHoras > $tarefa->baselineHoras + 0.01;
    }
}
