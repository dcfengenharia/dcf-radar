<?php

namespace App\Support\Engenharia;

use App\DTOs\Engenharia\InteligenciaEngenharia;
use App\Models\Work;
use Carbon\CarbonInterface;

/**
 * Ciclo 22, Etapa 22.1 — fachada única da camada gerencial de
 * Engenharia (Seção 23). Compõe as 4 queries especializadas
 * (`ProntidaoDocumentalAtividadeQuery`/`GrdGerencialQuery`/
 * `IndustrializacaoDocumentalQuery`/`SuprimentoDocumentalQuery`), cada
 * uma reaproveitando 100% as regras autoritativas do Ciclo 18 (GED) —
 * nunca reimplementadas aqui. Sem UI, sem Cockpit, sem alteração em
 * `SituacoesGerenciaisQuery` (Ciclo 21) — decisão explícita desta etapa
 * (Seção 20, Opção C): só read-model por enquanto.
 */
class InteligenciaEngenhariaQuery
{
    public static function porObra(Work $obra, int $horizonteDias, ?CarbonInterface $referencia = null): InteligenciaEngenharia
    {
        return new InteligenciaEngenharia(
            obraId: $obra->id,
            horizonteDias: $horizonteDias,
            prontidaoDocumental: ProntidaoDocumentalAtividadeQuery::porObra($obra, $horizonteDias, $referencia),
            grdAguardandoAceite: GrdGerencialQuery::aguardandoAceite($obra),
            copiasObsoletasPendentes: GrdGerencialQuery::copiasObsoletasPendentes($obra),
            industrializacaoComMudancaRevisao: IndustrializacaoDocumentalQuery::comMudancaDeRevisao($obra),
            suprimentoBloqueadoPorDocumento: SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento($obra),
        );
    }
}
