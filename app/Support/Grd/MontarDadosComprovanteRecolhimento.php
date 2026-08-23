<?php

namespace App\Support\Grd;

use App\Models\GrdRecolhimento;

/**
 * Ciclo 18, Etapa 18.5.8 — monta os dados do COMPROVANTE de UM evento de
 * recolhimento específico (`GrdRecolhimento`, append-only desde a 18.5.1).
 * Puramente de LEITURA — nenhuma regra de domínio nova.
 *
 * **Unidade do comprovante — 1 evento, nunca o "estado atual"**: cada
 * `GrdRecolhimento` é um fato histórico independente e imutável (nunca
 * editado/apagado — `const UPDATED_AT = null`). Se uma distribuição teve 3
 * eventos (Recolhido parcial, NãoLocalizado, Recolhido final), existem 3
 * comprovantes distintos, cada um reproduzindo EXATAMENTE o que aquele
 * evento registrou — nunca o estado derivado (`GrdDistribuicao::estado()`)
 * da distribuição no momento em que o comprovante é gerado. Gerar o
 * comprovante do evento 1 depois que os eventos 2 e 3 já aconteceram
 * continua mostrando "Recolhido parcial, qtd. X" — nunca é reescrito.
 *
 * `distribuicao->quantidade` (a quantidade ORIGINALMENTE entregue, nunca
 * alterada) e os snapshots de `item`/`grdDestinatario` vêm juntos — o
 * comprovante do evento sempre mostra os 3 conceitos separados: quantidade
 * distribuída original, quantidade DESTE evento, e (só como referência,
 * nunca como "resultado deste evento") a pendência atual da distribuição.
 */
class MontarDadosComprovanteRecolhimento
{
    /**
     * @return array{grd: \App\Models\Grd, distribuicao: \App\Models\GrdDistribuicao, evento: GrdRecolhimento, geradoEm: \Illuminate\Support\Carbon}
     */
    public function paraEvento(GrdRecolhimento $evento): array
    {
        $evento->loadMissing([
            'registradoPor',
            'distribuicao.item.grd.obra',
            'distribuicao.item.grd.emitidoPor',
            'distribuicao.grdDestinatario',
        ]);

        $distribuicao = $evento->distribuicao;

        return [
            'grd' => $distribuicao->item->grd,
            'distribuicao' => $distribuicao,
            'evento' => $evento,
            'geradoEm' => now(),
        ];
    }
}
