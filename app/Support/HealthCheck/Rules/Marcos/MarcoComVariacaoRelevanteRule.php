<?php

namespace App\Support\HealthCheck\Rules\Marcos;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class MarcoComVariacaoRelevanteRule extends RegraHealthCheckBase
{
    /** Limiar em dias corridos entre a data da linha de base e a data atual do marco. */
    private const LIMITE_DIAS = 15;

    public function id(): string
    {
        return 'MILE-002';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Marcos;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Medio;
    }

    public function titulo(): string
    {
        return 'Marco com variação relevante em relação à linha de base';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontrados {$quantidade} marco(s) cuja data atual diverge da linha de base em mais de " . self::LIMITE_DIAS . ' dias.';
    }

    public function impacto(): string
    {
        return 'Uma variação grande num marco indica replanejamento significativo — vale confirmar se essa mudança já foi comunicada e está refletida na linha de base vigente.';
    }

    public function recomendacao(): string
    {
        return 'Confirmar se a variação é esperada; se for uma mudança de escopo relevante, considerar salvar uma nova Linha de Base.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        if (!$tarefa->isMarco || $tarefa->baselineTermino === null || $tarefa->dataTermino === null) {
            return false;
        }

        return abs($tarefa->baselineTermino->diffInDays($tarefa->dataTermino, false)) > self::LIMITE_DIAS;
    }
}
