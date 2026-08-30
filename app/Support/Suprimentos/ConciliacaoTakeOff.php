<?php

namespace App\Support\Suprimentos;

use App\Enums\StatusRequisicaoPlanejamento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\RequisicaoPlanejamentoItem;
use App\Support\TakeOff\TakeOffConsolidado;
use Illuminate\Support\Collection;

/**
 * Ciclo 19, Etapa 19.2 — conciliação quantitativa Take Off × Requisição
 * do Planejamento. Só RPs `Emitida` consomem saldo oficial (rascunho não
 * é demanda formal — seção 11 do pedido); tudo aqui é DERIVADO, nunca
 * persistido (seção 8) — `ItemTakeOff`/`ListaEngenharia` nunca ganham
 * coluna de saldo/quantidade requisitada.
 *
 * Toda entrada aceita uma Collection já carregada pelo chamador — nunca
 * dispara 1 query por item/lista em loop (seção 30): o custo é sempre
 * O(1) consultas (1 SUM agregado em lote), independente de N.
 */
class ConciliacaoTakeOff
{
    public const STATUS_NAO_REQUISITADO = 'nao_requisitado';
    public const STATUS_PARCIAL = 'parcial';
    public const STATUS_COMPLETO = 'completo';

    /**
     * @param  Collection<int, ItemTakeOff>  $itensTakeOff
     * @return Collection<string, array> chave = item_take_off_id
     */
    public static function porItens(Collection $itensTakeOff): Collection
    {
        $ids = $itensTakeOff->pluck('id')->all();

        $requisitado = empty($ids)
            ? collect()
            : RequisicaoPlanejamentoItem::query()
                ->whereIn('item_take_off_id', $ids)
                ->whereHas('requisicao', fn ($q) => $q->where('status', StatusRequisicaoPlanejamento::Emitida->value))
                ->groupBy('item_take_off_id')
                ->selectRaw('item_take_off_id, SUM(quantidade_requisitada) as total')
                ->pluck('total', 'item_take_off_id');

        return $itensTakeOff->map(function (ItemTakeOff $item) use ($requisitado) {
            $prevista = (float) $item->quantidade;
            $requisitada = round((float) ($requisitado[$item->id] ?? 0), 3);
            $saldo = round($prevista - $requisitada, 3);
            $percentual = $prevista > 0 ? round(min(100, ($requisitada / $prevista) * 100), 2) : ($requisitada > 0 ? 100.0 : 0.0);

            return [
                'item_take_off_id' => $item->id,
                'quantidade_prevista' => $prevista,
                'quantidade_requisitada' => $requisitada,
                'saldo' => $saldo,
                'percentual_requisitado' => $percentual,
                'status' => self::statusPara($requisitada, $prevista),
            ];
        })->keyBy('item_take_off_id');
    }

    public static function porItem(ItemTakeOff $item): array
    {
        return self::porItens(collect([$item]))->get($item->id);
    }

    /**
     * @param  Collection<int, ListaEngenharia>  $listas  precisa vir com `itens` eager-loaded pelo chamador
     * @return Collection<string, array> chave = lista_id
     */
    public static function porListas(Collection $listas): Collection
    {
        $todosItens = $listas->flatMap(fn (ListaEngenharia $lista) => $lista->itens);
        $conciliacaoPorItem = self::porItens($todosItens);

        return $listas->map(function (ListaEngenharia $lista) use ($conciliacaoPorItem) {
            $totalItens = $lista->itens->count();
            $completos = $parciais = $naoRequisitados = 0;

            foreach ($lista->itens as $item) {
                $status = $conciliacaoPorItem->get($item->id)['status'] ?? self::STATUS_NAO_REQUISITADO;
                match ($status) {
                    self::STATUS_COMPLETO => $completos++,
                    self::STATUS_PARCIAL => $parciais++,
                    default => $naoRequisitados++,
                };
            }

            return [
                'lista_id' => $lista->id,
                'lista_codigo' => $lista->codigo,
                'lista_tipo' => $lista->tipo?->value,
                'total_itens' => $totalItens,
                'itens_nao_requisitados' => $naoRequisitados,
                'itens_parciais' => $parciais,
                'itens_completos' => $completos,
                // Cobertura por CONTAGEM de itens totalmente conciliados — nunca
                // soma de quantidade entre unidades incompatíveis (kg+m+un).
                'percentual_itens_completos' => $totalItens > 0 ? round(($completos / $totalItens) * 100, 2) : null,
            ];
        })->keyBy('lista_id');
    }

    public static function porLista(ListaEngenharia $lista): array
    {
        $lista->loadMissing('itens');

        return self::porListas(collect([$lista]))->first();
    }

    /**
     * Resumo da obra inteira — 3 queries totais (listas vigentes, itens
     * eager-loaded, SUM agregado de requisitado), nunca por lista.
     */
    public static function porObra(string $obraId): array
    {
        $listas = TakeOffConsolidado::listasVigentes($obraId)->load('itens');
        $porListas = self::porListas($listas);

        return [
            'total_listas' => $listas->count(),
            'total_itens' => (int) $porListas->sum('total_itens'),
            'itens_nao_requisitados' => (int) $porListas->sum('itens_nao_requisitados'),
            'itens_parciais' => (int) $porListas->sum('itens_parciais'),
            'itens_completos' => (int) $porListas->sum('itens_completos'),
            'listas_zero_pct' => $porListas->filter(fn ($l) => $l['percentual_itens_completos'] === 0.0)->count(),
            'listas_cem_pct' => $porListas->filter(fn ($l) => $l['percentual_itens_completos'] === 100.0)->count(),
            'por_lista' => $porListas,
        ];
    }

    private static function statusPara(float $requisitada, float $prevista): string
    {
        if ($requisitada <= 0.0005) {
            return self::STATUS_NAO_REQUISITADO;
        }

        if ($requisitada >= $prevista - 0.0005) {
            return self::STATUS_COMPLETO;
        }

        return self::STATUS_PARCIAL;
    }
}
