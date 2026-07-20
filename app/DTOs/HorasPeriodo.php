<?php

namespace App\DTOs;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;

readonly class HorasPeriodo
{
    public function __construct(
        public string $atividadeUid,
        public SerieAvanco $serie,
        public GranularidadePeriodo $granularidade,
        public string $periodoInicio,
        public float $horas,
    ) {}
}
