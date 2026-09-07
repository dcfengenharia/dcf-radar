<?php

namespace App\DTOs\LicoesAprendidas;

/**
 * Ciclo 23, Etapa 23.5.A (Decisão 7) — 1 Material com presença cross-obra
 * comprovada (`COUNT(DISTINCT obra_origem_id) >= 2`, já filtrado pelo
 * read-model — nunca calculado de novo aqui). Nunca infere causalidade —
 * este DTO só carrega contagens objetivas, o texto de apresentação
 * ("Material X está associado a lições publicadas provenientes de M
 * obras") é responsabilidade exclusiva da camada de exibição (Decisão 3).
 */
final readonly class ItemMaterialCrossObra
{
    public function __construct(
        public string $materialId,
        public string $titulo,
        public int $quantidadeLicoes,
        public int $quantidadeObras,
        public int $quantidadeBoasPraticas,
    ) {
    }
}
