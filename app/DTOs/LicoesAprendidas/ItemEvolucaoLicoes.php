<?php

namespace App\DTOs\LicoesAprendidas;

/**
 * Ciclo 23, Etapa 23.5.A — 1 período (mês, `YYYY-MM`) da evolução
 * temporal de PUBLICAÇÕES — nunca de ocorrência/criação. Representa
 * "quando a memória corporativa cresceu", não "quando o fato aconteceu".
 */
final readonly class ItemEvolucaoLicoes
{
    public function __construct(
        public string $periodo,
        public int $quantidade,
    ) {
    }
}
