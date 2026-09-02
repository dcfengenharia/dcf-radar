<?php

namespace App\DTOs\Gestao\Cockpit;

use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.2 — read model do Cockpit de Engenharia. Mesma
 * filosofia dos DTOs irmãos (`CockpitObra`/`CockpitSuprimentos`, Ciclo
 * 21.5/21.6): puro dado já resolvido — Blade nunca recalcula nada.
 */
final readonly class CockpitEngenharia
{
    /**
     * @param  array{atividades_bloqueadas:int, atividades_parciais:int, atividades_informacao_insuficiente:int, documentos_bloqueantes:int, grds_aguardando_aceite:int, copias_obsoletas_pendentes:int, industrializacao_com_mudanca:int, suprimento_bloqueado:int}  $resumoExecutivo
     * @param  Collection<int, array<string, mixed>>  $acaoPrioritaria
     * @param  array<int, \App\DTOs\Gestao\Cockpit\CockpitProntidaoEngenhariaHorizonte>  $prontidaoPorHorizonte
     * @param  Collection<int, \App\DTOs\Engenharia\AtividadeProntidaoDocumental>  $matrizAtividades
     * @param  Collection<int, \App\DTOs\Engenharia\FatoEngenharia>  $grdAguardandoAceite
     * @param  Collection<int, \App\DTOs\Engenharia\FatoEngenharia>  $copiasObsoletasPendentes
     * @param  Collection<int, \App\DTOs\Engenharia\FatoEngenharia>  $industrializacaoComMudancaRevisao
     * @param  Collection<int, \App\DTOs\Engenharia\FatoEngenharia>  $suprimentoBloqueadoPorDocumento
     * @param  Collection<int, \App\DTOs\Engenharia\AtividadeProntidaoDocumental>  $informacaoInsuficiente
     */
    public function __construct(
        public string $obraId,
        public int $horizonteDias,
        public array $resumoExecutivo,
        public Collection $acaoPrioritaria,
        public array $prontidaoPorHorizonte,
        public Collection $matrizAtividades,
        public int $matrizTotalAtividades,
        public Collection $grdAguardandoAceite,
        public Collection $copiasObsoletasPendentes,
        public Collection $industrializacaoComMudancaRevisao,
        public Collection $suprimentoBloqueadoPorDocumento,
        public Collection $informacaoInsuficiente,
        public array $gaps = [],
    ) {
    }
}
