<?php

namespace App\Support\Grd;

use App\Enums\ResultadoRecolhimento;
use App\Enums\StatusGrd;
use App\Models\DocumentoEngenharia;
use App\Models\GrdDistribuicao;
use App\Models\GrdRecolhimento;
use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 18, Etapa 18.5.1 — query somente-leitura, em lote, de "quem ainda
 * segura uma cópia física obsoleta de um Documento nesta obra". NUNCA
 * persiste nada — alerta é sempre derivado em tempo de leitura (decisão
 * central de 18.5.0/18.5.1, nunca uma tabela de "pendências").
 */
class DetectorCopiasObsoletasGrd
{
    /**
     * Uma distribuição é uma cópia obsoleta quando:
     * - a GRD que a distribuiu está Emitida;
     * - a revisão distribuída NÃO é a revisão vigente do Documento AGORA
     *   (comparação de tupla idêntica a
     *   DocumentoEngenhariaRevisao::scopeVigentes() — QUALQUER mudança
     *   naquele critério precisa ser espelhada aqui);
     * - a quantidade pendente (entregue − recolhida via eventos
     *   Recolhido; NaoLocalizado nunca reduz) é > 0.
     *
     * Toda a filtragem roda em SQL, em lote, por obra — nunca por
     * distribuição individual. Retorna objetos simples (stdClass) com o
     * contexto já resolvido (Documento, revisão vigente, revisão
     * entregue, destinatário, GRD, quantidades).
     *
     * **18.5.1.HARDENING — semântica explícita de Destinatario soft-deletado**:
     * uma cópia obsoleta CONTINUA aparecendo mesmo que o Destinatario que a
     * recebeu esteja soft-deletado — é evidência de uma cópia física
     * histórica que pode continuar em campo, cadastro inativo não apaga
     * esse fato. A filtragem (WHERE/EXISTS acima) nunca toca a tabela
     * `destinatarios`; o registro depende só de `GrdDestinatario` (o
     * snapshot congelado na emissão), nunca da existência ativa do
     * `Destinatario`. A relação `grdDestinatario.destinatario` eager-
     * carregada abaixo é só um extra de conveniência (pode vir `null` se
     * soft-deletado — respeita o default scope de SoftDeletes) e nunca é
     * usada para decidir se o registro aparece.
     *
     * @return Collection<int, object>
     */
    public function porObra(Work $obra): Collection
    {
        $recolhidoPorDistribuicao = DB::table('grd_recolhimentos')
            ->select('grd_distribuicao_id', DB::raw('SUM(quantidade) as total'))
            ->where('resultado', ResultadoRecolhimento::Recolhido->value)
            ->groupBy('grd_distribuicao_id');

        $distribuicoes = GrdDistribuicao::query()
            ->select(
                'grd_distribuicoes.*',
                DB::raw('COALESCE(recolhido_agg.total, 0) as total_recolhido_batch')
            )
            ->join('grd_itens', 'grd_itens.id', '=', 'grd_distribuicoes.grd_item_id')
            ->join('grds', 'grds.id', '=', 'grd_itens.grd_id')
            ->join('documento_engenharia_revisoes as revisao_entregue', 'revisao_entregue.id', '=', 'grd_itens.documento_engenharia_revisao_id')
            ->join('documentos_engenharia as documento', 'documento.id', '=', 'revisao_entregue.documento_engenharia_id')
            ->leftJoinSub($recolhidoPorDistribuicao, 'recolhido_agg', 'recolhido_agg.grd_distribuicao_id', '=', 'grd_distribuicoes.id')
            ->where('grds.obra_id', $obra->id)
            ->where('grds.status', StatusGrd::Emitida->value)
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('documento_engenharia_revisoes as mais_nova')
                    ->whereColumn('mais_nova.documento_engenharia_id', 'documento.id')
                    ->whereRaw(
                        '(COALESCE(mais_nova.data_emissao, "1000-01-01"), mais_nova.created_at, mais_nova.id) > '
                        . '(COALESCE(revisao_entregue.data_emissao, "1000-01-01"), revisao_entregue.created_at, revisao_entregue.id)'
                    );
            })
            ->whereRaw('(grd_distribuicoes.quantidade - COALESCE(recolhido_agg.total, 0)) > 0')
            ->with(['item.revisao.documento', 'grdDestinatario.destinatario', 'item.grd'])
            ->get();

        if ($distribuicoes->isEmpty()) {
            return collect();
        }

        $documentoIds = $distribuicoes
            ->map(fn (GrdDistribuicao $d) => $d->item->revisao->documento_engenharia_id)
            ->unique()
            ->values();

        $documentosComVigente = DocumentoEngenharia::query()
            ->whereIn('id', $documentoIds)
            ->with('latestRevisao')
            ->get()
            ->keyBy('id');

        $distribuicaoIds = $distribuicoes->pluck('id');

        $ultimosEventos = GrdRecolhimento::query()
            ->whereIn('grd_distribuicao_id', $distribuicaoIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('grd_distribuicao_id')
            ->map(fn ($eventos) => $eventos->first());

        return $distribuicoes->map(function (GrdDistribuicao $distribuicao) use ($documentosComVigente, $ultimosEventos) {
            $documento = $distribuicao->item->revisao->documento;
            $revisaoVigente = $documentosComVigente->get($documento->id)?->latestRevisao;
            $ultimoEvento = $ultimosEventos->get($distribuicao->id);
            $quantidadeEntregue = (int) $distribuicao->quantidade;
            $quantidadeRecolhida = (int) $distribuicao->getAttribute('total_recolhido_batch');

            return (object) [
                'distribuicao' => $distribuicao,
                'documento' => $documento,
                'revisao_entregue' => $distribuicao->item->revisao,
                'revisao_vigente' => $revisaoVigente,
                'grd_destinatario' => $distribuicao->grdDestinatario,
                'grd' => $distribuicao->item->grd,
                'quantidade_entregue' => $quantidadeEntregue,
                'quantidade_recolhida' => $quantidadeRecolhida,
                'quantidade_pendente' => max($quantidadeEntregue - $quantidadeRecolhida, 0),
                'ultimo_resultado_recolhimento' => $ultimoEvento?->resultado,
            ];
        })->values();
    }
}
