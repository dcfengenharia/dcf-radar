<?php

namespace App\Support\HealthCheck\Rules\Logic;

use App\DTOs\PlanoImportacao;
use App\DTOs\PredecessoraLink;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\HealthCheckRegraEstruturalInterface;
use App\Support\HealthCheck\TarefasPorUid;

/**
 * LOGIC-005 — múltiplos vínculos (PredecessorLink) entre o MESMO par de
 * atividades (mesma predecessora + mesma sucessora). Trabalha sobre os
 * vínculos BRUTOS de `TarefaImportada::$predecessoras` — nunca sobre
 * `HealthCheckGrafoCronograma`, que colapsa múltiplos vínculos do mesmo
 * par numa única aresta (Fase 2B.1) e por isso não serve pra detectar
 * esta situação.
 *
 * 3 casos, cada um com sua própria severidade e seu próprio finding
 * (mesmo padrão multi-finding já usado em STRUCT-004/005):
 * - Caso A (duplicidade exata): mesmo tipo + mesmo LinkLag + mesmo
 *   LagFormat em 2+ vínculos do mesmo par. Severidade Médio.
 * - Caso B (tipos diferentes): 2+ vínculos do mesmo par com tipos
 *   diferentes (ex.: FS e SS). Severidade Informativo — pode ser
 *   intencional (comum em cronogramas migrados de outras ferramentas,
 *   como Primavera P6, que permite múltiplos tipos de relação entre o
 *   mesmo par nativamente, ao contrário da UI padrão do MS Project).
 * - Caso C (mesmo tipo, lag diferente): 2+ vínculos do mesmo par, mesmo
 *   tipo, mas LinkLag/LagFormat diferentes entre eles. Severidade Médio.
 *
 * Prioridade de classificação quando um grupo tem 3+ vínculos mistos:
 * tipos diferentes sempre vira Caso B, independente dos lags também
 * divergirem — é o achado estruturalmente mais saliente do grupo.
 *
 * LinkLag/LagFormat nunca são convertidos nem interpretados por
 * magnitude aqui — só usados como valores brutos pra comparação de
 * igualdade (mesmo princípio da Fase 2A: "não converter silenciosamente").
 */
final class MultiplosVinculosMesmoParRule implements HealthCheckRegraEstruturalInterface
{
    public function id(): string
    {
        return 'LOGIC-005';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        $tarefas = TarefasPorUid::indexar($plano);

        $duplicidadeExata = [];
        $tiposDiferentes = [];
        $lagDiferente = [];

        foreach ($tarefas as $sucessora) {
            $porPredecessora = [];
            foreach ($sucessora->predecessoras as $link) {
                $porPredecessora[$link->predecessoraUid][] = $link;
            }

            foreach ($porPredecessora as $predecessoraUid => $links) {
                if (count($links) < 2) {
                    continue;
                }

                $predecessora = $tarefas[$predecessoraUid] ?? null;

                $tiposDistintos = array_unique(array_map(
                    fn (PredecessoraLink $l) => $l->tipoCodigoOriginal,
                    $links
                ));
                $lagsDistintos = array_unique(array_map(
                    fn (PredecessoraLink $l) => $l->linkLag . '|' . $l->lagFormat,
                    $links
                ));

                $registro = [
                    'predecessora' => $predecessora
                        ? TarefasPorUid::resumo($predecessora)
                        : ['uid' => $predecessoraUid, 'codigo' => null, 'nome' => null],
                    'sucessora' => TarefasPorUid::resumo($sucessora),
                    'vinculos' => array_map(fn (PredecessoraLink $l) => [
                        'tipo' => $l->tipo?->label(),
                        'tipo_codigo_original' => $l->tipoCodigoOriginal,
                        'link_lag' => $l->linkLag,
                        'lag_format' => $l->lagFormat,
                    ], $links),
                ];

                if (count($tiposDistintos) > 1) {
                    $tiposDiferentes[] = $registro;
                } elseif (count($lagsDistintos) > 1) {
                    $lagDiferente[] = $registro;
                } else {
                    $duplicidadeExata[] = $registro;
                }
            }
        }

        $findings = [];

        if (!empty($duplicidadeExata)) {
            $findings[] = new HealthCheckFinding(
                regraId: $this->id(),
                categoria: HealthCheckCategoria::Logica,
                severidade: HealthCheckSeveridade::Medio,
                titulo: 'Vínculos duplicados entre a mesma predecessora e sucessora',
                descricao: sprintf(
                    'Foram identificados %d par(es) de atividades com vínculos idênticos (mesmo tipo de relacionamento, mesmo LinkLag e mesmo LagFormat) duplicados entre a mesma predecessora e sucessora.',
                    count($duplicidadeExata)
                ),
                impacto: 'Vínculos duplicados não alteram o cálculo de datas, mas costumam indicar erro de digitação ou duplicidade não percebida na origem do cronograma.',
                recomendacao: 'Revise se os vínculos duplicados foram criados intencionalmente ou se um deles pode ser removido.',
                atividades: $duplicidadeExata,
            );
        }

        if (!empty($tiposDiferentes)) {
            $findings[] = new HealthCheckFinding(
                regraId: $this->id(),
                categoria: HealthCheckCategoria::Logica,
                severidade: HealthCheckSeveridade::Informativo,
                titulo: 'Múltiplos tipos de relacionamento entre a mesma predecessora e sucessora',
                descricao: sprintf(
                    'Foram identificados %d par(es) de atividades com mais de um tipo de relacionamento (ex.: Término-Início e Início-Início) entre a mesma predecessora e sucessora.',
                    count($tiposDiferentes)
                ),
                impacto: 'Múltiplos tipos de relacionamento entre o mesmo par podem ser intencionais (comum em cronogramas migrados de outras ferramentas), mas também podem indicar duplicidade não percebida.',
                recomendacao: 'Confirme se os diferentes tipos de relacionamento entre essas atividades são intencionais.',
                atividades: $tiposDiferentes,
            );
        }

        if (!empty($lagDiferente)) {
            $findings[] = new HealthCheckFinding(
                regraId: $this->id(),
                categoria: HealthCheckCategoria::Logica,
                severidade: HealthCheckSeveridade::Medio,
                titulo: 'Mesmo tipo de relacionamento com lag/lead diferente entre a mesma predecessora e sucessora',
                descricao: sprintf(
                    'Foram identificados %d par(es) de atividades com o mesmo tipo de relacionamento, porém com valores de LinkLag/LagFormat diferentes entre os vínculos duplicados.',
                    count($lagDiferente)
                ),
                impacto: 'Vínculos duplicados com lags diferentes podem indicar uma correção não finalizada no cronograma de origem (um vínculo antigo não removido).',
                recomendacao: 'Revise qual dos vínculos reflete o lag/lead correto e remova o vínculo desatualizado.',
                atividades: $lagDiferente,
            );
        }

        return $findings;
    }
}
