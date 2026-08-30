<?php

namespace App\Support\Estoque;

use App\Models\ContagemInventario;
use App\Models\InventarioAjuste;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.7 — divergência/situação de InventarioItem sempre
 * DERIVADA (Seção 5 do pedido: "preferência: derivada"), nunca uma
 * coluna persistida. Mesma filosofia de App\Support\Suprimentos\
 * ConciliacaoTakeOff/ConciliacaoAlocacao/ConciliacaoRecebimento (Ciclo
 * 19) — toda entrada aceita uma Collection já carregada pelo chamador e
 * resolve em LOTE (nunca 1 query por item numa listagem — Seção 25 do
 * pedido, "não aceitar N+1").
 */
class ConciliacaoInventario
{
    /**
     * @param  Collection<int, \App\Models\InventarioItem>  $itens
     * @return Collection<string, array{ultima_contagem: ?ContagemInventario, diferenca: ?float, tem_ajuste: bool}> chave = inventario_item_id
     */
    public static function porItens(Collection $itens): Collection
    {
        $itemIds = $itens->pluck('id')->all();
        if (empty($itemIds)) {
            return collect();
        }

        // 1 query total: todas as contagens dos itens pedidos, em ordem
        // de registro (created_at, id) — a ÚLTIMA de cada grupo é a
        // "contagem adotada", mesmo critério canônico de
        // InventarioItem::ultimaContagem().
        $ultimaContagemPorItem = ContagemInventario::query()
            ->whereIn('inventario_item_id', $itemIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('inventario_item_id')
            ->map(fn (Collection $grupo) => $grupo->last());

        // 1 query total: quais itens já têm Ajuste.
        $itensComAjuste = InventarioAjuste::query()
            ->whereIn('inventario_item_id', $itemIds)
            ->pluck('inventario_item_id')
            ->flip();

        return $itens->mapWithKeys(function ($item) use ($ultimaContagemPorItem, $itensComAjuste) {
            /** @var ?ContagemInventario $ultima */
            $ultima = $ultimaContagemPorItem->get($item->id);
            $diferenca = $ultima
                ? round((float) $ultima->quantidade_contada - (float) $item->quantidade_sistema_snapshot, 3)
                : null;

            return [$item->id => [
                'ultima_contagem' => $ultima,
                'diferenca' => $diferenca,
                'tem_ajuste' => $itensComAjuste->has($item->id),
            ]];
        });
    }
}
