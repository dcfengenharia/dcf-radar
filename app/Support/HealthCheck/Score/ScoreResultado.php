<?php

namespace App\Support\HealthCheck\Score;

/**
 * Resultado completo do ScoreCalculator para uma importação — contém tudo
 * que uma futura UI precisa para explicar "por que este Score" sem
 * recalcular nada (ver CLAUDE.md, Fase 3 — Score de Saúde).
 */
readonly class ScoreResultado
{
    /**
     * @param array<string, ScoreDimensao> $porDimensao chave = HealthCheckCategoria->value
     * @param AcaoRecomendada[] $mapaAcoes já ordenado por prioridade (severidade > impacto > quantidade > regra_id)
     */
    public function __construct(
        public int $score,
        public FaixaScore $faixa,
        /** Null quando não há atividades executáveis no plano (nada para medir). */
        public ?int $cobertura,
        public array $porDimensao,
        public array $mapaAcoes,
        /** 100 - score — quantos pontos, no máximo, seriam recuperados corrigindo tudo. */
        public int $potencialRecuperavel,
        public string $versaoFormula,
    ) {}
}
