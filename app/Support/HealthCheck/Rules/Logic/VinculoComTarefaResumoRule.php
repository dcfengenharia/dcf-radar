<?php

namespace App\Support\HealthCheck\Rules\Logic;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\HealthCheckRegraEstruturalInterface;
use App\Support\HealthCheck\TarefasPorUid;

/**
 * LOGIC-009 — vínculo de predecessora/sucessora envolvendo uma
 * tarefa-resumo, de qualquer lado da relação. Trabalha sobre os vínculos
 * BRUTOS (`TarefaImportada::$predecessoras`), resolvendo UIDs via
 * `TarefasPorUid` — que, ao contrário de `HealthCheckGrafoCronograma`,
 * INCLUI tarefas-resumo no índice. É exatamente esse vínculo que o grafo
 * estrutural (Fase 2B.1) ignora silenciosamente (relação envolvendo
 * resumo nunca vira aresta) — esta regra existe pra trazer isso à tona
 * como diagnóstico complementar, sem alterar em nada o comportamento do
 * grafo ou das regras STRUCT-*.
 *
 * Ambos os lados são tecnicamente possíveis no MSPDI: uma tarefa-resumo
 * pode aparecer como predecessora de uma atividade comum (ela mesma tem
 * `PredecessorLink` apontando pra ela) e também pode aparecer como
 * sucessora (ela mesma tem um `PredecessorLink` na sua própria lista,
 * apontando pra outra atividade) — o MS Project não bloqueia nenhum dos
 * dois casos na estrutura do arquivo. Por isso a severidade é Informativo
 * e o texto nunca afirma que é erro.
 */
final class VinculoComTarefaResumoRule implements HealthCheckRegraEstruturalInterface
{
    public function id(): string
    {
        return 'LOGIC-009';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        $tarefas = TarefasPorUid::indexar($plano);
        $afetados = [];

        foreach ($tarefas as $sucessora) {
            foreach ($sucessora->predecessoras as $link) {
                $predecessora = $tarefas[$link->predecessoraUid] ?? null;

                if ($predecessora === null) {
                    continue;
                }

                $predecessoraEhResumo = $predecessora->isSummary;
                $sucessoraEhResumo = $sucessora->isSummary;

                if (!$predecessoraEhResumo && !$sucessoraEhResumo) {
                    continue;
                }

                $direcao = match (true) {
                    $predecessoraEhResumo && $sucessoraEhResumo => 'ambas_resumo',
                    $predecessoraEhResumo => 'predecessora_e_resumo',
                    default => 'sucessora_e_resumo',
                };

                $afetados[] = [
                    'predecessora' => TarefasPorUid::resumo($predecessora),
                    'sucessora' => TarefasPorUid::resumo($sucessora),
                    'tipo' => $link->tipo?->label(),
                    'direcao' => $direcao,
                ];
            }
        }

        if (empty($afetados)) {
            return [];
        }

        return [new HealthCheckFinding(
            regraId: $this->id(),
            categoria: HealthCheckCategoria::Logica,
            severidade: HealthCheckSeveridade::Informativo,
            titulo: 'Vínculo lógico envolvendo tarefa-resumo',
            descricao: sprintf(
                'Foram identificados %d vínculo(s) de predecessora/sucessora envolvendo uma tarefa-resumo. Isso pode ser uma prática legítima em alguns cronogramas (o MS Project permite), mas normalmente é recomendável vincular apenas atividades executáveis.',
                count($afetados)
            ),
            impacto: 'Vínculos com tarefas-resumo não são utilizados pela análise estrutural do Health Check (Fase 2B.1) e podem não refletir a intenção real de sequenciamento entre as atividades executáveis dentro do pacote.',
            recomendacao: 'Avalie se o vínculo deveria apontar para uma atividade executável específica dentro do pacote, em vez da tarefa-resumo como um todo.',
            atividades: $afetados,
        )];
    }
}
