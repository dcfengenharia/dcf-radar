<?php

namespace App\Support\HealthCheck\Rules\Slack;

use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckSlackPorAtividadeBase;

/**
 * SLACK-005 — folga livre (FreeSlack) maior que a folga total (TotalSlack).
 *
 * Viola uma relação matemática garantida por definição de CPM (FreeSlack
 * <= TotalSlack sempre, independente de qualquer configuração do MS
 * Project — Folga Livre usa só a data mais cedo do sucessor imediato,
 * Folga Total usa a data mais tarde permitida por toda a rede/restrição,
 * logo Folga Livre nunca deveria exceder Folga Total). Uma violação aqui
 * tipicamente indica que o cronograma não foi totalmente recalculado
 * antes da exportação — NÃO afirma erro de planejamento.
 *
 * Comparação puramente matemática (`freeSlack > totalSlack`), nunca
 * baseada no sinal dos valores — funciona igual para valores positivos,
 * negativos ou mistos.
 */
final class FreeSlackMaiorTotalSlackRule extends RegraHealthCheckSlackPorAtividadeBase
{
    public function id(): string
    {
        return 'SLACK-005';
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
        return 'Folga livre maior que a folga total';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram identificadas {$quantidade} atividade(s) em que a folga livre (FreeSlack) é maior que a folga total (TotalSlack) — essa relação é matematicamente inesperada, já que a folga livre nunca deveria exceder a folga total.";
    }

    public function impacto(): string
    {
        return 'A relação entre folga livre e folga total é inconsistente. Isso normalmente indica que o cronograma não foi totalmente recalculado antes da exportação, ou uma inconsistência nos dados exportados.';
    }

    public function recomendacao(): string
    {
        return 'Verifique o recálculo do cronograma e os dados exportados. Não representa necessariamente um erro de planejamento.';
    }

    protected function combina(TarefaImportada $tarefa): bool
    {
        return $tarefa->totalSlack !== null
            && $tarefa->freeSlack !== null
            && $tarefa->freeSlack > $tarefa->totalSlack;
    }
}
