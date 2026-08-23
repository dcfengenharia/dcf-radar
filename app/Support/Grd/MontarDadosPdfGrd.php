<?php

namespace App\Support\Grd;

use App\Models\Grd;
use App\Models\GrdDistribuicao;

/**
 * Ciclo 18, Etapa 18.5.3 — monta os dados pra impressão/PDF de uma GRD
 * Emitida. Puramente de LEITURA/apresentação — nenhuma regra de domínio
 * nova, nenhuma escrita. Reconstrução 100% a partir de fatos e snapshots
 * já congelados na emissão (`GrdItem.*_snapshot`, `GrdDestinatario.
 * *_snapshot`, `GrdDistribuicao.quantidade`) — NUNCA consulta
 * `revisaoVigente()`/`Destinatario` ao vivo pra decidir o que exibir.
 *
 * Mesmo espírito de extração já usado no projeto pra reaproveitar
 * apresentação em 2 lugares sem duplicar lógica (ex.: `ReportCurvaSerializer`,
 * extraída do próprio `⚡relatorio-detalhe.blade.php`) — aqui, extraída
 * desde o início como classe própria (não existia versão inline antes)
 * justamente pra ficar diretamente testável sem precisar decodificar
 * bytes de PDF.
 */
class MontarDadosPdfGrd
{
    /**
     * @return array{grd: Grd, distribuicoes: \Illuminate\Support\Collection<int, GrdDistribuicao>, geradoEm: \Illuminate\Support\Carbon}
     */
    public function paraGrd(Grd $grd): array
    {
        $grd->loadMissing(['obra', 'criador', 'emitidoPor', 'itens.revisao', 'destinatarios']);

        $distribuicoes = GrdDistribuicao::whereIn('grd_item_id', $grd->itens->pluck('id'))
            ->with(['item', 'grdDestinatario', 'recolhimentos.registradoPor'])
            ->orderBy('created_at')
            ->get();

        return [
            'grd' => $grd,
            'distribuicoes' => $distribuicoes,
            'geradoEm' => now(),
        ];
    }
}
