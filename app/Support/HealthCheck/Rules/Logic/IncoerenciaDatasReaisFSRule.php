<?php

namespace App\Support\HealthCheck\Rules\Logic;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Enums\TipoRelacionamentoPredecessora;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\HealthCheckRegraEstruturalInterface;
use App\Support\HealthCheck\TarefasPorUid;

/**
 * LOGIC-008 — inconsistência entre datas REAIS (ActualStart/ActualFinish,
 * já capturadas como `realInicio`/`realTermino` em `TarefaImportada` desde
 * a Fase 1) numa relação Término-Início (FS): a sucessora começou de fato
 * antes do término real da predecessora.
 *
 * Trabalha EXCLUSIVAMENTE com `realInicio`/`realTermino` — nunca
 * `dataInicio`/`dataTermino` (previstos), baseline, percentual ou
 * tendência. As futuras LOGIC-001/002/003 (fora de escopo desta fase) vão
 * avaliar datas PREVISTAS e por isso precisam do modo de agendamento
 * (Fase 2B.2A) pra reduzir falso positivo — datas REAIS já aconteceram,
 * não são recalculadas pelo MS Project em nenhum modo de agendamento,
 * então esta regra não depende desse dado.
 *
 * Só avalia quando AMBAS as datas reais necessárias existem — ausência de
 * qualquer uma delas faz o vínculo ser simplesmente ignorado (nunca
 * tratado como "sem problema" nem como "problema"). Ignora vínculos em
 * que a predecessora OU a sucessora é tarefa-resumo ou está inativa —
 * nenhuma das duas tem uma "data real" no sentido de execução de trabalho
 * comparável.
 */
final class IncoerenciaDatasReaisFSRule implements HealthCheckRegraEstruturalInterface
{
    public function id(): string
    {
        return 'LOGIC-008';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Execucao;
    }

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        $tarefas = TarefasPorUid::indexar($plano);
        $afetados = [];

        foreach ($tarefas as $sucessora) {
            if ($sucessora->isSummary || !$sucessora->ativa || $sucessora->realInicio === null) {
                continue;
            }

            foreach ($sucessora->predecessoras as $link) {
                if ($link->tipo !== TipoRelacionamentoPredecessora::FinishToStart) {
                    continue;
                }

                $predecessora = $tarefas[$link->predecessoraUid] ?? null;

                if ($predecessora === null || $predecessora->isSummary || !$predecessora->ativa) {
                    continue;
                }

                if ($predecessora->realTermino === null) {
                    continue;
                }

                if ($sucessora->realInicio->lt($predecessora->realTermino)) {
                    $afetados[] = [
                        'predecessora' => array_merge(TarefasPorUid::resumo($predecessora), [
                            'real_termino' => $predecessora->realTermino->toDateString(),
                        ]),
                        'sucessora' => array_merge(TarefasPorUid::resumo($sucessora), [
                            'real_inicio' => $sucessora->realInicio->toDateString(),
                        ]),
                    ];
                }
            }
        }

        if (empty($afetados)) {
            return [];
        }

        return [new HealthCheckFinding(
            regraId: $this->id(),
            categoria: HealthCheckCategoria::Logica,
            severidade: HealthCheckSeveridade::Alto,
            titulo: 'Inconsistência entre datas reais em relação Término-Início (FS)',
            descricao: sprintf(
                'Foram identificadas %d relação(ões) Término-Início (FS) em que a sucessora iniciou (data real) antes do término real da predecessora. Verifique a coerência da relação lógica e dos registros de avanço.',
                count($afetados)
            ),
            impacto: 'Uma sucessora que começou de fato antes do término real da predecessora pode indicar avanço registrado no período errado ou uma relação lógica que não reflete mais a execução real.',
            recomendacao: 'Revise os registros de avanço (Início/Término Real) das duas atividades e confirme se a relação Término-Início entre elas ainda é válida.',
            atividades: $afetados,
        )];
    }
}
