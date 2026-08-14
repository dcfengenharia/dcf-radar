<?php

namespace App\Support\HealthCheck\Score;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;

/**
 * Um item do Mapa de Ações — 1 finding penalizador (severidade->peso() < 0)
 * já com o impacto em pontos de Score calculado. `recomendacao` é sempre
 * copiada verbatim de HealthCheckFinding::$recomendacao — nunca um texto
 * genérico novo inventado aqui.
 *
 * `findingIndex` (Fase 4.2, aditivo): posição original deste finding dentro
 * de HealthCheckResultado::$findings (mesma ordem persistida em
 * cronograma_importacao_health_checks.findings) — permite ao Plano de Ação
 * apontar de volta pro finding exato que originou este item do Mapa de
 * Ações, sem ambiguidade quando a mesma regra_id produz múltiplas
 * ocorrências (ex.: 2+ ciclos STRUCT-005, que sem isso ficam indistinguíveis
 * entre si aqui, já que quantidadeAtividades/impacto são idênticos pra
 * ambos). `null` em registros persistidos ANTES desta fase (nunca
 * inventado retroativamente).
 */
readonly class AcaoRecomendada
{
    public function __construct(
        public string $regraId,
        public HealthCheckCategoria $categoria,
        public HealthCheckSeveridade $severidade,
        public string $titulo,
        public int $quantidadeAtividades,
        /** Pontos perdidos por este finding (<= 0), não arredondado — arredondar só na exibição. */
        public float $impacto,
        public string $recomendacao,
        public ?int $findingIndex = null,
    ) {}

    public function toArray(): array
    {
        return [
            'regra_id' => $this->regraId,
            'categoria' => $this->categoria->value,
            'severidade' => $this->severidade->value,
            'titulo' => $this->titulo,
            'quantidade_atividades' => $this->quantidadeAtividades,
            'impacto' => $this->impacto,
            'recomendacao' => $this->recomendacao,
            'finding_index' => $this->findingIndex,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            regraId: $data['regra_id'],
            categoria: HealthCheckCategoria::from($data['categoria']),
            severidade: HealthCheckSeveridade::from($data['severidade']),
            titulo: $data['titulo'],
            quantidadeAtividades: $data['quantidade_atividades'],
            impacto: $data['impacto'],
            recomendacao: $data['recomendacao'],
            findingIndex: $data['finding_index'] ?? null,
        );
    }
}
