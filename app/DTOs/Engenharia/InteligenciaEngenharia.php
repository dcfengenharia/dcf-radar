<?php

namespace App\DTOs\Engenharia;

use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.1 — read model consolidado, retornado por
 * `InteligenciaEngenhariaQuery::porObra()`. Puro DTO de leitura — sem
 * UI, sem Cockpit, sem Notification (Seção 23/29).
 */
final readonly class InteligenciaEngenharia
{
    /**
     * @param  Collection<int, AtividadeProntidaoDocumental>  $prontidaoDocumental
     * @param  Collection<int, FatoEngenharia>  $grdAguardandoAceite
     * @param  Collection<int, FatoEngenharia>  $copiasObsoletasPendentes
     * @param  Collection<int, FatoEngenharia>  $industrializacaoComMudancaRevisao
     * @param  Collection<int, FatoEngenharia>  $suprimentoBloqueadoPorDocumento
     */
    public function __construct(
        public string $obraId,
        public int $horizonteDias,
        public Collection $prontidaoDocumental,
        public Collection $grdAguardandoAceite,
        public Collection $copiasObsoletasPendentes,
        public Collection $industrializacaoComMudancaRevisao,
        public Collection $suprimentoBloqueadoPorDocumento,
    ) {
    }

    public function totalFatosAcionaveis(): int
    {
        return $this->grdAguardandoAceite->count()
            + $this->copiasObsoletasPendentes->count()
            + $this->industrializacaoComMudancaRevisao->count()
            + $this->suprimentoBloqueadoPorDocumento->count();
    }
}
