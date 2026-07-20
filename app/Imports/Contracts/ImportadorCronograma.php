<?php

namespace App\Imports\Contracts;

use App\DTOs\PlanoImportacao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\Work;

interface ImportadorCronograma
{
    public function analisar(
        string $caminhoArquivo,
        Work $obra,
        TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline
    ): PlanoImportacao;

    public function aplicar(
        PlanoImportacao $plano,
        Work $obra,
        ?string $userId,
        ?string $arquivo,
        TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline
    ): CronogramaImportacao;
}
