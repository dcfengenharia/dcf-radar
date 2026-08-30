<?php

namespace App\Support\Industrializacao;

use App\Models\EntregaProdutoIndustrializado;
use App\Models\ProducaoIndustrializada;
use App\Models\ProdutoIndustrializado;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.5 — "produzido"/"entregue"/"saldo pronto no
 * terceiro" (Seção 17/47) são sempre DERIVADOS de
 * `producoes_industrializadas`/`entregas_produto_industrializado`,
 * nunca colunas em `produtos_industrializados`.
 */
class SaldoProdutoIndustrializado
{
    public static function produzido(ProdutoIndustrializado $produto): float
    {
        return round((float) ProducaoIndustrializada::where('produto_industrializado_id', $produto->id)->sum('quantidade'), 3);
    }

    public static function entregue(ProdutoIndustrializado $produto): float
    {
        return round((float) EntregaProdutoIndustrializado::where('produto_industrializado_id', $produto->id)->sum('quantidade'), 3);
    }

    public static function saldoPronto(ProdutoIndustrializado $produto): float
    {
        return round(self::produzido($produto) - self::entregue($produto), 3);
    }

    /**
     * Versão em lote pra listagem — nunca 1 SUM por linha.
     *
     * @param  array<int, string>  $produtoIds
     * @return Collection<string, array{produzido: float, entregue: float, saldo_pronto: float}>
     */
    public static function porProdutosEmLote(array $produtoIds): Collection
    {
        if (empty($produtoIds)) {
            return collect();
        }

        $produzidoPorProduto = ProducaoIndustrializada::query()
            ->whereIn('produto_industrializado_id', $produtoIds)
            ->groupBy('produto_industrializado_id')
            ->selectRaw('produto_industrializado_id, SUM(quantidade) as total')
            ->pluck('total', 'produto_industrializado_id')
            ->map(fn ($v) => round((float) $v, 3));

        $entreguePorProduto = EntregaProdutoIndustrializado::query()
            ->whereIn('produto_industrializado_id', $produtoIds)
            ->groupBy('produto_industrializado_id')
            ->selectRaw('produto_industrializado_id, SUM(quantidade) as total')
            ->pluck('total', 'produto_industrializado_id')
            ->map(fn ($v) => round((float) $v, 3));

        return collect($produtoIds)->mapWithKeys(function (string $produtoId) use ($produzidoPorProduto, $entreguePorProduto) {
            $produzido = (float) ($produzidoPorProduto[$produtoId] ?? 0.0);
            $entregue = (float) ($entreguePorProduto[$produtoId] ?? 0.0);

            return [$produtoId => [
                'produzido' => $produzido,
                'entregue' => $entregue,
                'saldo_pronto' => round($produzido - $entregue, 3),
            ]];
        });
    }
}
