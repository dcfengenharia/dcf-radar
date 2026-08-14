<?php

namespace App\Support\HealthCheck\Rules\CaminhoCritico;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckRuleInterface;

/**
 * Regra global (não avalia atividade por atividade) — implementa a
 * interface diretamente em vez de estender RegraHealthCheckBase, já que o
 * "afetado" aqui é o cronograma inteiro, não uma lista de atividades.
 */
class NenhumaAtividadeCriticaRule implements HealthCheckRuleInterface
{
    public function id(): string
    {
        return 'CRIT-001';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::CaminhoCritico;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Informativo;
    }

    public function bloqueante(): bool
    {
        return false;
    }

    public function avaliar(PlanoImportacao $plano): ?HealthCheckFinding
    {
        $folhas = [...$plano->criar, ...$plano->atualizar];

        if (empty($folhas)) {
            return null;
        }

        foreach ($folhas as $tarefa) {
            if ($tarefa->caminhoCritico) {
                return null;
            }
        }

        return new HealthCheckFinding(
            regraId: $this->id(),
            categoria: $this->categoria(),
            severidade: $this->severidade(),
            titulo: 'Nenhuma atividade identificada como caminho crítico',
            descricao: 'Não foram identificadas atividades marcadas como críticas pelo MS Project neste cronograma (0 de ' . count($folhas) . ' atividade(s)). Isso pode indicar que o caminho crítico não está sendo utilizado ou calculado como referência neste planejamento. Verifique se essa informação é esperada para este cronograma.',
            impacto: 'Quando o caminho crítico é utilizado como referência de planejamento, a ausência de atividades críticas pode limitar a análise de riscos e impactos no prazo.',
            recomendacao: 'Verifique se o cálculo do caminho crítico está habilitado e se as configurações de cálculo do cronograma estão adequadas.',
            atividades: [],
        );
    }
}
