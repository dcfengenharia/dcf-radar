<?php

namespace App\Support\HealthCheck\Rules\Estrutura;

use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\RegraHealthCheckEstruturalPorAtividadeBase;

/**
 * STRUCT-002 — atividade executável/ativa sem NENHUMA sucessora.
 *
 * Não tenta adivinhar qual é "a última atividade lógica" (mesmo princípio
 * de STRUCT-001, espelhado).
 *
 * Dedup com STRUCT-003: uma atividade totalmente isolada NÃO dispara esta
 * regra — vira só STRUCT-003 (`grauEntrada() > 0` abaixo exclui o caso
 * isolado).
 */
final class SemSucessoraRule extends RegraHealthCheckEstruturalPorAtividadeBase
{
    public function id(): string
    {
        return 'STRUCT-002';
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
        return 'Atividades sem sucessora definida';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram identificadas {$quantidade} atividade(s) sem sucessora definida. Verifique se o término dessas atividades representa intencionalmente o encerramento de uma frente ou se existe uma relação lógica posterior que deveria ser representada.";
    }

    public function impacto(): string
    {
        return 'Atividades sem sucessoras podem impedir que impactos de atraso sejam propagados corretamente para atividades posteriores.';
    }

    public function recomendacao(): string
    {
        return 'Verifique se existe uma atividade ou marco posterior que dependa logicamente do término desta atividade.';
    }

    protected function combina(TarefaImportada $tarefa, HealthCheckGrafoCronograma $grafo): bool
    {
        return $grafo->grauSaida($tarefa->uid) === 0 && $grafo->grauEntrada($tarefa->uid) > 0;
    }
}
