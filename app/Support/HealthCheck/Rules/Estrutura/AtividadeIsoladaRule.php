<?php

namespace App\Support\HealthCheck\Rules\Estrutura;

use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\RegraHealthCheckEstruturalPorAtividadeBase;

/**
 * STRUCT-003 — atividade executável/ativa completamente desconectada da
 * rede lógica: sem predecessora E sem sucessora ao mesmo tempo. Mais grave
 * que STRUCT-001/002 isoladamente (severidade Alto).
 *
 * Quando esta regra identifica uma atividade, STRUCT-001 e STRUCT-002 NÃO
 * geram finding pra ela (dedup — ver a exclusão em suas próprias
 * combina()), evitando 3 alertas redundantes pra um mesmo problema.
 */
final class AtividadeIsoladaRule extends RegraHealthCheckEstruturalPorAtividadeBase
{
    public function id(): string
    {
        return 'STRUCT-003';
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
        return 'Atividades isoladas da rede lógica';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram identificadas {$quantidade} atividade(s) completamente desconectadas da rede lógica do cronograma, sem predecessoras e sem sucessoras.";
    }

    public function impacto(): string
    {
        return 'A atividade não possui conexões lógicas com outras atividades e pode não participar adequadamente da análise de impactos e propagação de atrasos.';
    }

    public function recomendacao(): string
    {
        return 'Verifique se a atividade deveria estar conectada à rede lógica do cronograma.';
    }

    protected function combina(TarefaImportada $tarefa, HealthCheckGrafoCronograma $grafo): bool
    {
        return $grafo->grauEntrada($tarefa->uid) === 0 && $grafo->grauSaida($tarefa->uid) === 0;
    }
}
