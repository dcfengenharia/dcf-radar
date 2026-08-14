<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;

interface HealthCheckRuleInterface
{
    public function id(): string;

    public function categoria(): HealthCheckCategoria;

    public function severidade(): HealthCheckSeveridade;

    /**
     * Planejamento ou Execucao — ver App\Enums\HealthCheckNaturezaRegra.
     * Usado por HealthCheckEngine::avaliar() pra filtrar as regras ANTES de
     * avaliá-las quando o tipo de importação é Baseline.
     */
    public function natureza(): HealthCheckNaturezaRegra;

    /**
     * Sempre false na Fase 1 — nenhuma regra bloqueia a importação (decisão
     * do usuário). O método já existe no contrato pra permitir, numa fase
     * futura, ativar bloqueio numa regra específica sem quebrar a interface
     * nem o HealthCheckEngine.
     */
    public function bloqueante(): bool;

    /**
     * Avalia o PlanoImportacao já produzido por
     * App\Imports\MsProjectImporter::analisar() — nunca lê o arquivo XML de
     * novo, nunca consulta o banco. Retorna null quando a regra não
     * encontrou nenhuma ocorrência.
     */
    public function avaliar(PlanoImportacao $plano): ?HealthCheckFinding;
}
