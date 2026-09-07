<?php

namespace App\DTOs\LicoesAprendidas;

use Illuminate\Support\Collection;

/**
 * Ciclo 23, Etapa 23.5.A (Decisão 9) — resultado único e completo de
 * `InteligenciaLicoesQuery::resumo()`. Só dado, `readonly`, nunca
 * persistido — a Blade nunca monta estatística nenhuma sozinha, só lê
 * estes campos já agregados via SQL.
 */
final readonly class ResumoInteligenciaLicoes
{
    /**
     * @param  Collection<int, ItemDistribuicaoLicoes>  $distribuicaoPorArea
     * @param  Collection<int, ItemDistribuicaoLicoes>  $distribuicaoPorDisciplina
     * @param  Collection<int, ItemDistribuicaoLicoes>  $distribuicaoPorTipo
     * @param  Collection<int, ItemDistribuicaoLicoes>  $distribuicaoPorCriticidade
     * @param  Collection<int, ItemEvolucaoLicoes>  $evolucaoTemporal
     * @param  Collection<int, ItemMaterialCrossObra>  $materiaisCrossObra
     * @param  Collection<int, ItemProvenienciaLicoes>  $proveniencia
     */
    public function __construct(
        public int $totalLicoesPublicadas,
        public int $totalObrasComLicaoPublicada,
        public int $totalBoasPraticasPublicadas,
        public Collection $distribuicaoPorArea,
        public Collection $distribuicaoPorDisciplina,
        public Collection $distribuicaoPorTipo,
        public Collection $distribuicaoPorCriticidade,
        public Collection $evolucaoTemporal,
        public Collection $materiaisCrossObra,
        public Collection $proveniencia,
    ) {
    }
}
