<?php

namespace App\Support\HealthCheck\Rules\Duracao;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class DuracaoExcessivaRule extends RegraHealthCheckBase
{
    /** Limiar em dias corridos — configurável aqui, sem impacto no importador. */
    private const LIMITE_DIAS = 180;

    public function id(): string
    {
        return 'DUR-002';
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
        return HealthCheckSeveridade::Baixo;
    }

    public function titulo(): string
    {
        return 'Duração excessivamente longa';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontradas {$quantidade} atividade(s) com duração planejada acima de " . self::LIMITE_DIAS . ' dias corridos.';
    }

    public function impacto(): string
    {
        return 'Atividades muito longas dificultam o acompanhamento semanal e costumam esconder sub-etapas que valeriam a pena detalhar.';
    }

    public function recomendacao(): string
    {
        return 'Avaliar se a atividade deveria ser quebrada em etapas menores no cronograma de origem.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return !$tarefa->isMarco
            && $tarefa->dataInicio !== null
            && $tarefa->dataTermino !== null
            && $tarefa->dataInicio->diffInDays($tarefa->dataTermino) > self::LIMITE_DIAS;
    }
}
