<?php

namespace App\DTOs\LicoesAprendidas;

/**
 * Ciclo 23, Etapa 23.5.A (Decisão 15) — 1 categoria de proveniência,
 * sempre uma das 3 já reconstruídas deterministicamente:
 * `candidato_convertido` | `captura_contextual` | `manual`. As 3
 * categorias são mutuamente exclusivas por construção (ver
 * `InteligenciaLicoesQuery::proveniencia()`), nunca uma classificação
 * heurística/probabilística.
 */
final readonly class ItemProvenienciaLicoes
{
    public function __construct(
        public string $chave,
        public string $rotulo,
        public int $quantidade,
    ) {
    }
}
