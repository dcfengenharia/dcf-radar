<?php

namespace App\Support\HealthCheck\Rules\Logic;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\HealthCheckRegraEstruturalInterface;
use App\Support\HealthCheck\TarefasPorUid;

/**
 * LOGIC-010 — atividade ativa e executável cuja(s) predecessora(s)
 * capturada(s) estão inativas. Trabalha sobre os vínculos BRUTOS
 * (`TarefaImportada::$predecessoras`) e sobre `TarefasPorUid` (que, ao
 * contrário de `HealthCheckGrafoCronograma`, NÃO exclui atividades
 * inativas) — é exatamente essa exclusão do grafo estrutural que torna a
 * atividade "sem predecessora" pra fins de STRUCT-001; aqui queremos
 * investigar POR QUE, sem alterar em nada o comportamento do grafo.
 *
 * 2 findings possíveis, com severidades diferentes — decisão tomada
 * nesta implementação (não estava fixada no pedido, apresentada aqui em
 * vez de decidida silenciosamente):
 * - Todas as predecessoras capturadas estão inativas: severidade Baixo —
 *   sinal mais forte, a atividade não tem mais NENHUMA base lógica ativa
 *   remanescente no cronograma.
 * - Mistura de predecessoras ativas e inativas: severidade Informativo —
 *   sinal mais fraco, a lógica de sequenciamento provavelmente ainda é
 *   sustentada pela(s) predecessora(s) ativa(s) remanescente(s).
 * Em ambos os casos, o texto deixa claro que a desativação pode ter sido
 * uma decisão deliberada do planejador — nunca acusa como erro.
 */
final class SucessoraAtivaComPredecessoraInativaRule implements HealthCheckRegraEstruturalInterface
{
    public function id(): string
    {
        return 'LOGIC-010';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        $tarefas = TarefasPorUid::indexar($plano);

        $todasInativas = [];
        $misturaAtivaInativa = [];

        foreach ($tarefas as $sucessora) {
            if ($sucessora->isSummary || !$sucessora->ativa || empty($sucessora->predecessoras)) {
                continue;
            }

            $ativas = [];
            $inativas = [];

            foreach ($sucessora->predecessoras as $link) {
                $predecessora = $tarefas[$link->predecessoraUid] ?? null;

                if ($predecessora === null) {
                    continue;
                }

                if ($predecessora->ativa) {
                    $ativas[] = $predecessora;
                } else {
                    $inativas[] = $predecessora;
                }
            }

            if (empty($inativas)) {
                continue;
            }

            $registro = [
                'sucessora' => TarefasPorUid::resumo($sucessora),
                'predecessoras_inativas' => array_map(
                    fn (TarefaImportada $t) => TarefasPorUid::resumo($t),
                    $inativas
                ),
                'predecessoras_ativas' => array_map(
                    fn (TarefaImportada $t) => TarefasPorUid::resumo($t),
                    $ativas
                ),
            ];

            if (empty($ativas)) {
                $todasInativas[] = $registro;
            } else {
                $misturaAtivaInativa[] = $registro;
            }
        }

        $findings = [];

        if (!empty($todasInativas)) {
            $findings[] = new HealthCheckFinding(
                regraId: $this->id(),
                categoria: HealthCheckCategoria::Logica,
                severidade: HealthCheckSeveridade::Baixo,
                titulo: 'Sucessora ativa com todas as predecessoras inativas',
                descricao: sprintf(
                    'Foram identificadas %d atividade(s) ativa(s) cujas predecessoras capturadas estão TODAS inativas/arquivadas. Isso pode ser uma decisão deliberada de desativação, mas vale confirmar se a atividade ainda deveria depender dessa lógica.',
                    count($todasInativas)
                ),
                impacto: 'Se a desativação das predecessoras não foi intencional, a atividade pode estar sem nenhuma base lógica de sequenciamento válida no cronograma atual.',
                recomendacao: 'Confirme se as predecessoras desativadas deveriam ser substituídas por uma atividade ativa equivalente.',
                atividades: $todasInativas,
            );
        }

        if (!empty($misturaAtivaInativa)) {
            $findings[] = new HealthCheckFinding(
                regraId: $this->id(),
                categoria: HealthCheckCategoria::Logica,
                severidade: HealthCheckSeveridade::Informativo,
                titulo: 'Sucessora ativa com mistura de predecessoras ativas e inativas',
                descricao: sprintf(
                    'Foram identificadas %d atividade(s) ativa(s) com pelo menos uma predecessora inativa, mas que também possuem outra(s) predecessora(s) ainda ativa(s).',
                    count($misturaAtivaInativa)
                ),
                impacto: 'A lógica de sequenciamento provavelmente ainda é válida através da(s) predecessora(s) ativa(s), mas vale revisar se a predecessora inativa deveria ter sido removida do vínculo.',
                recomendacao: 'Confirme se o vínculo com a predecessora inativa ainda faz sentido ou se deveria ser removido do cronograma de origem.',
                atividades: $misturaAtivaInativa,
            );
        }

        return $findings;
    }
}
