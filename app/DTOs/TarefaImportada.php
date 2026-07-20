<?php

namespace App\DTOs;

use Carbon\Carbon;

readonly class TarefaImportada
{
    public function __construct(
        public string $uid,
        public string $nome,
        public bool $isSummary,
        public bool $isMarco,
        public bool $caminhoCritico,
        public ?string $parentUid,
        public ?string $codigo,
        public ?Carbon $dataInicio,
        public ?Carbon $dataTermino,
        public ?Carbon $baselineInicio,
        public ?Carbon $baselineTermino,
        public ?Carbon $realInicio,
        public ?Carbon $realTermino,
        public float $baselineHoras,
        public float $workHoras,
        public float $realHoras,
        public ?float $percentualConcluido,
        public array $textos,
    ) {}
}
