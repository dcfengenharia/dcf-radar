<?php

namespace App\Support\HealthCheck\Score;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;

/**
 * Score (0-100) de uma única categoria de Health Check — derivado
 * dinamicamente de HealthCheckCategoria::cases(), nunca de uma lista fixa.
 */
readonly class ScoreDimensao
{
    public function __construct(
        public HealthCheckCategoria $categoria,
        public int $score,
        public int $quantidadeOcorrencias,
        public ?HealthCheckSeveridade $severidadeMaxima,
        /** Pontos perdidos nesta dimensão (<= 0) — soma dos impactos dos findings desta categoria, antes do clamp. */
        public float $impactoTotal,
    ) {}

    public function toArray(): array
    {
        return [
            'categoria' => $this->categoria->value,
            'score' => $this->score,
            'quantidade_ocorrencias' => $this->quantidadeOcorrencias,
            'severidade_maxima' => $this->severidadeMaxima?->value,
            'impacto_total' => $this->impactoTotal,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            categoria: HealthCheckCategoria::from($data['categoria']),
            score: $data['score'],
            quantidadeOcorrencias: $data['quantidade_ocorrencias'],
            severidadeMaxima: isset($data['severidade_maxima']) ? HealthCheckSeveridade::from($data['severidade_maxima']) : null,
            impactoTotal: $data['impacto_total'],
        );
    }
}
