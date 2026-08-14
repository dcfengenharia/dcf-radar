<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckNaturezaRegra;

/**
 * Contrato pras regras estruturais (Fase 2B), que consomem o
 * HealthCheckGrafoCronograma pré-calculado — separado de
 * HealthCheckRuleInterface porque uma única regra estrutural pode gerar
 * MAIS DE UM finding na mesma avaliação (ex.: STRUCT-004 gera 1 finding
 * por componente desconectado; STRUCT-005 gera 1 finding por ciclo), então
 * o retorno é sempre um array (vazio quando a regra não encontrou nada).
 *
 * HealthCheckEngine::avaliar() constrói o grafo UMA ÚNICA VEZ por
 * avaliação e passa a mesma instância pra todas as regras estruturais —
 * nenhuma delas deve reconstruir o grafo.
 */
interface HealthCheckRegraEstruturalInterface
{
    public function id(): string;

    /**
     * Planejamento ou Execucao — ver App\Enums\HealthCheckNaturezaRegra.
     * Usado por HealthCheckEngine::avaliar() pra filtrar as regras ANTES de
     * avaliá-las quando o tipo de importação é Baseline.
     */
    public function natureza(): HealthCheckNaturezaRegra;

    /** @return HealthCheckFinding[] */
    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array;
}
