<?php

namespace App\Support\HealthCheck\Rules\Slack;

use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckSlackPorAtividadeBase;

/**
 * SLACK-002 — folga livre (FreeSlack) negativa.
 *
 * Mesma classe de fenômeno de SLACK-001, mas escopada só ao sucessor
 * imediato — costuma acontecer quando o sucessor tem uma restrição
 * própria (ex.: Must Start On) que força um início mais cedo do que a
 * lógica de predecessora/sucessora natural implicaria. Menos comum que
 * folga total negativa, mas igualmente legítima — NÃO afirma erro.
 */
final class FreeSlackNegativoRule extends RegraHealthCheckSlackPorAtividadeBase
{
    public function id(): string
    {
        return 'SLACK-002';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Medio;
    }

    public function titulo(): string
    {
        return 'Atividades com folga livre negativa';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram identificadas {$quantidade} atividade(s) com folga livre (FreeSlack) negativa.";
    }

    public function impacto(): string
    {
        return 'Folga livre negativa indica pressão na lógica imediata entre esta atividade e sua(s) sucessora(s), podendo estar relacionada a restrições de prazo ou à configuração lógica do cronograma.';
    }

    public function recomendacao(): string
    {
        return 'Verifique as restrições e a lógica entre esta atividade e suas sucessoras. Folga livre negativa não é necessariamente um erro.';
    }

    protected function combina(TarefaImportada $tarefa): bool
    {
        return $tarefa->freeSlack !== null && $tarefa->freeSlack < 0;
    }
}
