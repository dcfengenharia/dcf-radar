<?php

namespace App\Support\HealthCheck\PlanoAcao;

/**
 * Único ponto de verdade pra "esses dois conjuntos de external_uid
 * representam o mesmo problema?" (Fase 4 diagnóstico, Etapa 5: sobreposição
 * de uid, sem threshold numérico) — reaproveitado por
 * `PlanoAcaoReconciliador` (reconciliação entre importações) e por
 * `PlanoAcao::criarDeFinding()`/a UI (duplicação controlada e badge "N
 * ações abertas"), pra não duplicar a mesma regra em 3 lugares.
 */
final class SobreposicaoUid
{
    /**
     * @param string[] $uidsA
     * @param string[] $uidsB
     */
    public static function temSobreposicao(array $uidsA, array $uidsB): bool
    {
        return array_intersect($uidsA, $uidsB) !== [];
    }
}
