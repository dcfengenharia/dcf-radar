<?php

namespace App\Support\HealthCheck\Score;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckResultado;

/**
 * Score de Saúde do Cronograma — consome o HealthCheckResultado já
 * produzido pelo HealthCheckEngine (nunca reimplementa nenhuma regra).
 * 4 camadas explícitas, nenhuma fórmula obscura:
 *
 *   1. Severidade  -> peso bruto        (HealthCheckSeveridade::peso(), já existente)
 *   2. Peso bruto  -> peso máximo       (peso bruto x FATOR_ESCALA)
 *   3. Peso máximo -> impacto do finding (peso máximo x proporção de atividades afetadas)
 *   4. Impacto total -> Score            (clamp(100 + soma dos impactos, 0, 100))
 *
 * Ver CLAUDE.md, seção "Health Check — Fase 3 (Score de Saúde)".
 */
final class ScoreCalculator
{
    /**
     * Camada 2 — quantos pontos de Score 1 unidade de peso bruto representa
     * quando um finding afeta 100% das atividades elegíveis. Constante
     * nomeada e centralizada de propósito (calibração inicial, ajustável
     * sem reescrever a lógica): Crítico=-100, Alto=-50, Médio=-20, Baixo=-10,
     * Informativo=0 pontos de Score no cenário "afeta tudo".
     */
    public const FATOR_ESCALA = 10;

    /**
     * Identifica qual algoritmo produziu um Score já persistido — um Score
     * gravado nunca é recalculado com uma versão de fórmula diferente
     * (mesmo princípio de HealthCheckCategoria::VERSAO_REGRAS_ATUAL).
     */
    public const VERSAO_FORMULA = '1.0';

    public function calcular(HealthCheckResultado $resultado, PlanoImportacao $plano): ScoreResultado
    {
        $totalElegiveis = $this->totalAtividadesElegiveis($plano);
        $cobertura = $this->calcularCobertura($plano, $totalElegiveis);

        if ($totalElegiveis === 0) {
            return new ScoreResultado(
                score: 100,
                faixa: FaixaScore::paraScore(100),
                cobertura: $cobertura,
                porDimensao: $this->dimensoesEm100(),
                mapaAcoes: [],
                potencialRecuperavel: 0,
                versaoFormula: self::VERSAO_FORMULA,
            );
        }

        // Camada 3 calculada uma única vez por finding e reaproveitada pelo
        // Score geral, pelo Score por dimensão e pelo Mapa de Ações — nunca
        // recalculada 3 vezes.
        $impactos = array_map(
            fn (HealthCheckFinding $f) => [$f, $this->impactoFinding($f, $totalElegiveis)],
            $resultado->findings
        );

        $somaImpactos = array_sum(array_column($impactos, 1));
        $score = $this->clamparScore(100 + $somaImpactos);

        return new ScoreResultado(
            score: $score,
            faixa: FaixaScore::paraScore($score),
            cobertura: $cobertura,
            porDimensao: $this->calcularPorDimensao($impactos),
            mapaAcoes: $this->montarMapaAcoes($impactos),
            potencialRecuperavel: 100 - $score,
            versaoFormula: self::VERSAO_FORMULA,
        );
    }

    /** Camadas 2+3 — impacto (pontos de Score, <= 0) de um único finding. */
    private function impactoFinding(HealthCheckFinding $finding, int $totalElegiveis): float
    {
        $pesoMaximo = $finding->severidade->peso() * self::FATOR_ESCALA;

        return $pesoMaximo * ($finding->quantidade() / $totalElegiveis);
    }

    /** Camada 4 — arredonda só o resultado final, nunca cada finding individualmente antes da soma. */
    private function clamparScore(float $scoreBruto): int
    {
        return (int) round(max(0.0, min(100.0, $scoreBruto)));
    }

    /**
     * Mesmo universo de atividades já usado por STRUCT
     * (HealthCheckGrafoCronograma) e SLACK (RegraHealthCheckSlackPorAtividadeBase):
     * executável (não-resumo) e ativa. Tarefas-resumo nunca aparecem em
     * criar/atualizar (só em ->pacotes) — o filtro !isSummary aqui é
     * defensivo, mesmo padrão de clareza já usado nessas duas classes.
     */
    private function totalAtividadesElegiveis(PlanoImportacao $plano): int
    {
        return count(array_filter(
            [...$plano->criar, ...$plano->atualizar],
            fn (TarefaImportada $t) => !$t->isSummary && $t->ativa
        ));
    }

    private function totalAtividadesExecutaveis(PlanoImportacao $plano): int
    {
        return count(array_filter(
            [...$plano->criar, ...$plano->atualizar],
            fn (TarefaImportada $t) => !$t->isSummary
        ));
    }

    /**
     * Cobertura = atividades executáveis E ativas ÷ atividades executáveis
     * (ativas ou não) × 100.
     *
     * `null` só no caso de divisão por zero de verdade (nenhuma atividade
     * executável no plano — nada pra medir). Quando existem atividades
     * executáveis mas TODAS estão inativas, cobertura é `0` (não `null`) —
     * decisão deliberada: `0%` é o sinal mais forte de baixa confiança na
     * análise que existe, e é exatamente isso que deve ser sinalizado ao
     * usuário, nunca silenciado como "sem dado".
     */
    private function calcularCobertura(PlanoImportacao $plano, int $totalElegiveis): ?int
    {
        $totalExecutaveis = $this->totalAtividadesExecutaveis($plano);

        return $totalExecutaveis > 0 ? (int) round($totalElegiveis / $totalExecutaveis * 100) : null;
    }

    /**
     * @param array<int, array{0: HealthCheckFinding, 1: float}> $impactos
     * @return array<string, ScoreDimensao> chave = HealthCheckCategoria->value
     */
    private function calcularPorDimensao(array $impactos): array
    {
        $porDimensao = [];

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $doCategoria = array_values(array_filter(
                $impactos,
                fn (array $par) => $par[0]->categoria === $categoria
            ));

            if (empty($doCategoria)) {
                $porDimensao[$categoria->value] = new ScoreDimensao(
                    categoria: $categoria,
                    score: 100,
                    quantidadeOcorrencias: 0,
                    severidadeMaxima: null,
                    impactoTotal: 0.0,
                );

                continue;
            }

            $impactoTotal = array_sum(array_column($doCategoria, 1));

            $porDimensao[$categoria->value] = new ScoreDimensao(
                categoria: $categoria,
                score: $this->clamparScore(100 + $impactoTotal),
                quantidadeOcorrencias: array_sum(array_map(fn (array $par) => $par[0]->quantidade(), $doCategoria)),
                severidadeMaxima: $this->severidadeMaisGrave(array_map(fn (array $par) => $par[0]->severidade, $doCategoria)),
                impactoTotal: $impactoTotal,
            );
        }

        return $porDimensao;
    }

    /** @return array<string, ScoreDimensao> atalho do caso "sem atividades elegíveis" — todas as dimensões em 100, sem findings. */
    private function dimensoesEm100(): array
    {
        $porDimensao = [];

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $porDimensao[$categoria->value] = new ScoreDimensao(
                categoria: $categoria,
                score: 100,
                quantidadeOcorrencias: 0,
                severidadeMaxima: null,
                impactoTotal: 0.0,
            );
        }

        return $porDimensao;
    }

    /** @param HealthCheckSeveridade[] $severidades */
    private function severidadeMaisGrave(array $severidades): HealthCheckSeveridade
    {
        usort($severidades, fn (HealthCheckSeveridade $a, HealthCheckSeveridade $b) => $a->peso() <=> $b->peso());

        return $severidades[0];
    }

    /**
     * @param array<int, array{0: HealthCheckFinding, 1: float}> $impactos chave = índice original em HealthCheckResultado::$findings
     * @return AcaoRecomendada[] ordenado por severidade > impacto absoluto > quantidade > regra_id (desempate estável)
     */
    private function montarMapaAcoes(array $impactos): array
    {
        // array_filter() preserva as chaves de $impactos (== índice original
        // em $resultado->findings) — usort() reindexaria e perderia essa
        // informação, então a chave é capturada num 3º elemento ANTES do
        // usort (Fase 4.2: findingIndex, aditivo — nenhum finding é
        // filtrado/ordenado diferente do que já era, só passa a carregar
        // sua própria posição original adiante).
        $comIndiceOriginal = [];
        foreach (array_filter($impactos, fn (array $par) => $par[0]->severidade->peso() < 0) as $indiceOriginal => $par) {
            $comIndiceOriginal[] = [$par[0], $par[1], $indiceOriginal];
        }

        usort($comIndiceOriginal, function (array $a, array $b) {
            [$findingA, $impactoA] = $a;
            [$findingB, $impactoB] = $b;

            return ($findingA->severidade->peso() <=> $findingB->severidade->peso())
                ?: (abs($impactoB) <=> abs($impactoA))
                ?: ($findingB->quantidade() <=> $findingA->quantidade())
                ?: ($findingA->regraId <=> $findingB->regraId);
        });

        return array_map(
            fn (array $par) => new AcaoRecomendada(
                regraId: $par[0]->regraId,
                categoria: $par[0]->categoria,
                severidade: $par[0]->severidade,
                titulo: $par[0]->titulo,
                quantidadeAtividades: $par[0]->quantidade(),
                impacto: $par[1],
                recomendacao: $par[0]->recomendacao,
                findingIndex: $par[2],
            ),
            $comIndiceOriginal
        );
    }
}
