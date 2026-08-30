<?php

namespace App\Support\Suprimentos;

use App\Models\AlocacaoRequisicaoPacote;
use App\Models\ItemSuprimento;
use App\Models\RequisicaoPlanejamento;
use App\Models\RequisicaoPlanejamentoItem;
use Illuminate\Support\Collection;

/**
 * Ciclo 19, Etapa 19.3 — conciliação quantitativa RequisicaoPlanejamentoItem
 * × alocação a Pacote de Compra (`ItemSuprimento`). Tudo DERIVADO, nunca
 * persistido — mesma filosofia de `App\Support\Suprimentos\ConciliacaoTakeOff`.
 * Toda entrada aceita dado já carregado pelo chamador — nunca 1 query por
 * item/RP/Pacote em loop.
 */
class ConciliacaoAlocacao
{
    public const STATUS_NAO_ALOCADO = 'nao_alocado';
    public const STATUS_PARCIAL = 'parcial';
    public const STATUS_COMPLETO = 'completo';

    /**
     * @param  Collection<int, RequisicaoPlanejamentoItem>  $rpItens
     * @return Collection<string, array> chave = requisicao_planejamento_item_id
     */
    public static function porRequisicaoItens(Collection $rpItens): Collection
    {
        $ids = $rpItens->pluck('id')->all();

        $alocado = empty($ids)
            ? collect()
            : AlocacaoRequisicaoPacote::query()
                ->whereIn('requisicao_planejamento_item_id', $ids)
                ->groupBy('requisicao_planejamento_item_id')
                ->selectRaw('requisicao_planejamento_item_id, SUM(quantidade_alocada) as total')
                ->pluck('total', 'requisicao_planejamento_item_id');

        return $rpItens->map(function (RequisicaoPlanejamentoItem $item) use ($alocado) {
            $requisitada = (float) $item->quantidade_requisitada;
            $alocada = round((float) ($alocado[$item->id] ?? 0), 3);
            $saldo = round($requisitada - $alocada, 3);
            $percentual = $requisitada > 0 ? round(min(100, ($alocada / $requisitada) * 100), 2) : ($alocada > 0 ? 100.0 : 0.0);

            return [
                'requisicao_planejamento_item_id' => $item->id,
                'quantidade_requisitada' => $requisitada,
                'quantidade_alocada' => $alocada,
                'saldo' => $saldo,
                'percentual_alocado' => $percentual,
                'status' => self::statusPara($alocada, $requisitada),
            ];
        })->keyBy('requisicao_planejamento_item_id');
    }

    public static function porRequisicaoItem(RequisicaoPlanejamentoItem $item): array
    {
        return self::porRequisicaoItens(collect([$item]))->get($item->id);
    }

    /**
     * Cobertura por CONTAGEM de itens totalmente alocados — nunca soma
     * de quantidade entre unidades incompatíveis (mesmo princípio já
     * usado em ConciliacaoTakeOff::porListas()).
     */
    public static function porRequisicao(RequisicaoPlanejamento $rp): array
    {
        $itens = $rp->itens()->get();
        $conciliacao = self::porRequisicaoItens($itens);

        $completos = $parciais = $naoAlocados = 0;
        foreach ($conciliacao as $linha) {
            match ($linha['status']) {
                self::STATUS_COMPLETO => $completos++,
                self::STATUS_PARCIAL => $parciais++,
                default => $naoAlocados++,
            };
        }

        $total = $itens->count();

        return [
            'total_itens' => $total,
            'itens_nao_alocados' => $naoAlocados,
            'itens_parciais' => $parciais,
            'itens_completos' => $completos,
            'percentual_itens_completos' => $total > 0 ? round(($completos / $total) * 100, 2) : null,
        ];
    }

    /**
     * Resumo de um Pacote de Compra: quantas RPs/RPItens o alimentam,
     * quantidade por unidade (nunca somada entre unidades diferentes),
     * listas/documentos de origem, e a necessidade (já corrigida —
     * ignora atividade `fora_do_cronograma`).
     */
    public static function porPacote(ItemSuprimento $pacote): array
    {
        $alocacoes = AlocacaoRequisicaoPacote::query()
            ->where('item_suprimento_id', $pacote->id)
            ->with([
                'requisicaoItem.itemTakeOff.unidadeMedida',
                'requisicaoItem.itemTakeOff.lista.revisao.documento',
            ])
            ->get();

        $porUnidade = [];
        $rpsIds = [];
        $listasIds = [];
        $documentosIds = [];

        foreach ($alocacoes as $alocacao) {
            $rpItem = $alocacao->requisicaoItem;
            if (! $rpItem) {
                continue;
            }

            $rpsIds[$rpItem->requisicao_planejamento_id] = true;

            $itemTakeOff = $rpItem->itemTakeOff;
            $unidadeLabel = $itemTakeOff?->unidadeMedida?->codigo ?? '—';
            $porUnidade[$unidadeLabel] = ($porUnidade[$unidadeLabel] ?? 0) + (float) $alocacao->quantidade_alocada;

            $lista = $itemTakeOff?->lista;
            if ($lista) {
                $listasIds[$lista->id] = true;
                if ($lista->revisao?->documento) {
                    $documentosIds[$lista->revisao->documento->id] = true;
                }
            }
        }

        $pacote->loadMissing('atividades');

        return [
            'total_rps' => count($rpsIds),
            'total_rp_itens' => $alocacoes->count(),
            'quantidade_por_unidade' => $porUnidade,
            'total_listas' => count($listasIds),
            'total_documentos' => count($documentosIds),
            'total_atividades' => $pacote->atividades->count(),
            'necessidade' => $pacote->necessidade(),
        ];
    }

    private static function statusPara(float $alocada, float $requisitada): string
    {
        if ($alocada <= 0.0005) {
            return self::STATUS_NAO_ALOCADO;
        }

        if ($alocada >= $requisitada - 0.0005) {
            return self::STATUS_COMPLETO;
        }

        return self::STATUS_PARCIAL;
    }
}
