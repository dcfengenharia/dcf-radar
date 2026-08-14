<?php

namespace App\Support\HealthCheck\Rules\Datas;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\RegraHealthCheckBase;

class TerminoPlanejadoAntesDoInicioRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'DATE-005';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Datas;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Critico;
    }

    public function titulo(): string
    {
        return 'Término planejado anterior ao início planejado';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) cuja data de término planejado é anterior à data de início planejado.";
    }

    public function impacto(): string
    {
        return 'Datas planejadas invertidas tornam a duração da atividade negativa — quebra qualquer cálculo de prazo, Lookahead ou curva S dessa atividade.';
    }

    public function recomendacao(): string
    {
        return 'Corrigir as datas de início e término no cronograma de origem antes de importar novamente.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->dataInicio !== null
            && $tarefa->dataTermino !== null
            && $tarefa->dataTermino->lt($tarefa->dataInicio);
    }
}
