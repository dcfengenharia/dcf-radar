<?php

namespace App\Support\HealthCheck\Rules\Estrutura;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\HealthCheckRegraEstruturalInterface;

/**
 * STRUCT-004 — componentes conectados (conectividade NÃO direcionada, ver
 * HealthCheckGrafoCronograma::componentes()) que formam redes lógicas
 * independentes entre si.
 *
 * Só considera componentes com 2+ atividades ("redes" de verdade, com pelo
 * menos uma relação interna) — componentes de 1 atividade isolada já são
 * cobertos por STRUCT-003 e ficam de fora daqui, pra não duplicar o mesmo
 * problema como "componente desconectado de tamanho 1" (decisão tomada
 * na Fase 2B.1, documentada em CLAUDE.md — evita a explosão de ruído que
 * o próprio pedido original pede pra evitar: "não gerar finding pra cada
 * atividade").
 *
 * Se houver 0 ou 1 componente qualificado (rede única, ou nenhuma rede
 * com relação interna), não há nada pra comparar — nenhum finding. A
 * partir de 2 componentes qualificados, gera 1 finding POR componente
 * (nunca 1 finding agregando todos).
 */
final class RedeDesconectadaRule implements HealthCheckRegraEstruturalInterface
{
    private const TAMANHO_MINIMO_COMPONENTE = 2;

    public function id(): string
    {
        return 'STRUCT-004';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        $componentes = array_values(array_filter(
            $grafo->componentes(),
            fn (array $uids) => count($uids) >= self::TAMANHO_MINIMO_COMPONENTE
        ));

        if (count($componentes) < 2) {
            return [];
        }

        $findings = [];

        foreach ($componentes as $indice => $uids) {
            $tarefas = array_values(array_filter(array_map(
                fn (string $uid) => $grafo->tarefa($uid),
                $uids
            )));

            $findings[] = new HealthCheckFinding(
                regraId: $this->id(),
                categoria: HealthCheckCategoria::Estrutura,
                severidade: HealthCheckSeveridade::Alto,
                titulo: 'Rede lógica desconectada identificada',
                descricao: sprintf(
                    'Este grupo de %d atividade(s) forma uma rede lógica independente, sem nenhuma conexão de predecessora/sucessora com as demais redes identificadas no cronograma (%d redes desconectadas entre si no total).',
                    count($uids),
                    count($componentes)
                ),
                impacto: 'Componentes desconectados podem indicar frentes de trabalho que deveriam estar logicamente conectadas ao restante do cronograma — ou apenas frentes legitimamente independentes, o que também é normal em obras com múltiplas frentes/pacotes.',
                recomendacao: 'Avalie se este grupo de atividades deveria estar conectado às demais redes do cronograma por meio de uma relação de predecessora/sucessora.',
                atividades: [[
                    'componente_id' => $indice + 1,
                    'quantidade_atividades' => count($uids),
                    'quantidade_relacoes' => $this->contarRelacoesInternas($grafo, $uids),
                    'atividades' => array_map(fn ($t) => [
                        'uid' => $t->uid,
                        'codigo' => $t->codigo,
                        'nome' => $t->nome,
                    ], $tarefas),
                ]],
            );
        }

        return $findings;
    }

    /** @param string[] $uids */
    private function contarRelacoesInternas(HealthCheckGrafoCronograma $grafo, array $uids): int
    {
        $doComponente = array_flip($uids);
        $total = 0;

        foreach ($uids as $uid) {
            foreach ($grafo->sucessorasDe($uid) as $sucessoraUid) {
                if (isset($doComponente[$sucessoraUid])) {
                    $total++;
                }
            }
        }

        return $total;
    }
}
