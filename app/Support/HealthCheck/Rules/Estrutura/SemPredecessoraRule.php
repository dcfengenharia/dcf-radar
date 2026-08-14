<?php

namespace App\Support\HealthCheck\Rules\Estrutura;

use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\RegraHealthCheckEstruturalPorAtividadeBase;

/**
 * STRUCT-001 — atividade executável/ativa sem NENHUMA predecessora.
 *
 * Não tenta adivinhar qual é "a primeira atividade lógica" — uma obra
 * pode ter várias frentes/pacotes/disciplinas iniciando em paralelo,
 * então toda atividade sem predecessora é reportada (decisão do usuário).
 *
 * Dedup com STRUCT-003: uma atividade totalmente isolada (sem
 * predecessora E sem sucessora) NÃO dispara esta regra — vira só
 * STRUCT-003, pra não duplicar o alerta (`grauSaida() > 0` abaixo exclui
 * o caso isolado).
 */
final class SemPredecessoraRule extends RegraHealthCheckEstruturalPorAtividadeBase
{
    public function id(): string
    {
        return 'STRUCT-001';
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
        return 'Atividades sem predecessora definida';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram identificadas {$quantidade} atividade(s) sem predecessora definida. Verifique se o início dessas atividades é intencionalmente independente ou se existe uma relação lógica que deveria ser representada no cronograma.";
    }

    public function impacto(): string
    {
        return 'Atividades sem predecessoras podem reduzir a capacidade de o cronograma representar corretamente a sequência lógica e os impactos de atrasos.';
    }

    public function recomendacao(): string
    {
        return 'Verifique se existe uma atividade, marco ou evento que determine logicamente o início desta atividade.';
    }

    protected function combina(TarefaImportada $tarefa, HealthCheckGrafoCronograma $grafo): bool
    {
        return $grafo->grauEntrada($tarefa->uid) === 0 && $grafo->grauSaida($tarefa->uid) > 0;
    }
}
