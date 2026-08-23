<?php

namespace App\Support\Grd;

use Carbon\Carbon;

/**
 * Ciclo 18, Etapa 18.5.7 — payload leve e imutável do Digest Semanal de
 * Pendências GED, produzido por `App\Services\DigestPendenciasGed::
 * consolidar()`. Espelha o papel de `App\Support\CentralProntidao\
 * ResumoDigestProntidao` (Ciclo 16, A.2), mas — diferente daquele, que
 * carrega a árvore completa de `AtividadeProntidaoView` e por isso ganhou
 * um segundo DTO reduzido (`SnapshotDigestProntidao`, Etapa A.3) — este
 * DTO já nasce só com contagens escalares (nunca a lista de distribuições/
 * candidatos individuais), então não precisa de nenhuma etapa de redução
 * antes de viajar dentro de uma Notification queued.
 *
 * Todos os números vêm DIRETO de `DetectorCopiasObsoletasGrd`/
 * `CandidatosNovaEntregaGrd` (18.5.1, nunca reimplementados) — este DTO só
 * agrega o que esses 2 serviços já derivam.
 */
final readonly class ResumoDigestPendenciasGed
{
    public function __construct(
        public string $obraId,
        public string $obraNome,
        public Carbon $geradoEm,
        public int $obsoletasDocumentos,
        public int $obsoletasDestinatarios,
        public int $obsoletasQuantidadeFisica,
        public int $candidatosDocumentos,
        public int $candidatosDestinatarios,
    ) {
    }

    public function temPendencias(): bool
    {
        return $this->obsoletasDocumentos > 0 || $this->candidatosDocumentos > 0;
    }
}
