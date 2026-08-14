<?php

namespace App\Support\HealthCheck\Rules\Slack;

use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckSlackPorAtividadeBase;

/**
 * SLACK-001 — folga total (TotalSlack) negativa.
 *
 * Folga negativa é um sinal de saúde de cronograma legítimo e bem
 * estabelecido na indústria (um dos pontos do "DCMA 14-Point Assessment")
 * — normalmente decorre de uma restrição de prazo forte (Must Finish On,
 * Finish No Later Than, Deadline) que força um término mais cedo do que a
 * lógica pura da rede permitiria. NÃO é necessariamente erro de
 * planejamento — o texto deste finding nunca afirma isso.
 */
final class TotalSlackNegativoRule extends RegraHealthCheckSlackPorAtividadeBase
{
    public function id(): string
    {
        return 'SLACK-001';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Alto;
    }

    public function titulo(): string
    {
        return 'Atividades com folga total negativa';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram identificadas {$quantidade} atividade(s) com folga total (TotalSlack) negativa. Isso pode indicar pressão de prazo real (ex.: restrição de data, atraso acumulado) ou uma incompatibilidade entre a lógica do cronograma e as datas planejadas — verifique a causa antes de tratar como erro.";
    }

    public function impacto(): string
    {
        return 'Folga negativa indica que, mantidas as condições atuais, a atividade (ou uma atividade posterior a ela na rede lógica) não cumprirá o prazo ou a restrição vigente.';
    }

    public function recomendacao(): string
    {
        return 'Verifique se a condição decorre de restrição de prazo, atraso já ocorrido ou incompatibilidade entre a lógica e as datas planejadas. Folga total negativa não é necessariamente um erro de cronograma.';
    }

    protected function combina(TarefaImportada $tarefa): bool
    {
        return $tarefa->totalSlack !== null && $tarefa->totalSlack < 0;
    }
}
