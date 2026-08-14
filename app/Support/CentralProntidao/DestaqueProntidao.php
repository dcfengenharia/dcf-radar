<?php

namespace App\Support\CentralProntidao;

use Carbon\Carbon;

/**
 * Recorte MÍNIMO de UMA atividade problemática, pra uso exclusivo dentro
 * de `SnapshotDigestProntidao` (Ciclo 16, Etapa A.3) — nunca os DTOs de
 * contexto completos da Central (`RestricaoResumo`/`PlanoAcaoResumo`/
 * `SuprimentoAlerta`/`EngenhariaAlerta`), que o digest não transporta.
 * Puramente estrutura de dados, sem nenhuma lógica — a seleção/ordenação
 * de quais atividades viram destaque vive em
 * `SnapshotDigestProntidao::deResumo()`.
 */
final readonly class DestaqueProntidao
{
    public function __construct(
        public ?string $codigo,
        public string $nome,
        public StatusOperacionalProntidao $statusOperacional,
        public ?Carbon $inicioPlanejado,
        public array $resumoMotivos,
    ) {
    }
}
