<?php

namespace App\Support\Suprimentos;

use App\Models\PedidoCompraItemParcela;
use App\Models\RecebimentoPedido;
use App\Models\RecebimentoPedidoParcela;
use Illuminate\Support\Collection;

/**
 * Fechamento Adversarial Etapa 3 (Seções 13/14/22) — ÚNICA fonte de
 * verdade de "quanto de um RecebimentoPedido já foi distribuído por
 * parcela/necessidade", "este recebimento já está 100% distribuído" e
 * "quanto de uma PedidoCompraItemParcela já recebeu via distribuições
 * explícitas". Nunca persistido em nenhuma coluna — mesma filosofia
 * 100% derivada de `App\Support\Estoque\PoliticaConciliacaoAplicacao`
 * (Ciclo 20.4), reaproveitada aqui pra um problema estruturalmente
 * idêntico (conciliação quantitativa posterior contra um teto fixo) —
 * reaproveitada por `DistribuirRecebimentoPedidoPorParcela` E por
 * `App\Observers\RecebimentoPedidoParcelaObserver` (a MESMA regra,
 * nunca duplicada) E por `EstadoAtendimentoNecessidadeMaterialQuery`.
 *
 * "Fechado" (mesma decisão de `AplicacaoMaterialEstoque`, Seção 12 do
 * pedido original de 20.4, reaproveitada aqui): `SUM(distribuições) >=
 * quantidade_recebida` do evento, com a mesma tolerância de ponto
 * flutuante (0.0005) já usada em todo o domínio de Suprimentos/Estoque.
 * Depois de fechado, nenhuma distribuição daquele recebimento pode ser
 * criada/editada(quantidade)/excluída — nem mesmo pra "corrigir" um
 * erro (reabrir uma conciliação fechada não é permitido).
 */
class PoliticaDistribuicaoRecebimento
{
    private const TOLERANCIA = 0.0005;

    public static function totalDistribuido(RecebimentoPedido $recebimento, ?string $excluirDistribuicaoId = null): float
    {
        $query = RecebimentoPedidoParcela::where('recebimento_pedido_id', $recebimento->id);

        if ($excluirDistribuicaoId) {
            $query->where('id', '!=', $excluirDistribuicaoId);
        }

        return round((float) $query->sum('quantidade'), 3);
    }

    public static function pendente(RecebimentoPedido $recebimento, ?string $excluirDistribuicaoId = null): float
    {
        return round((float) $recebimento->quantidade_recebida - self::totalDistribuido($recebimento, $excluirDistribuicaoId), 3);
    }

    public static function recebimentoEstaFechado(RecebimentoPedido $recebimento, ?string $excluirDistribuicaoId = null): bool
    {
        return self::totalDistribuido($recebimento, $excluirDistribuicaoId) >= (float) $recebimento->quantidade_recebida - self::TOLERANCIA;
    }

    /** Quanto de UMA parcela já foi atribuído via distribuições explícitas (TETO B). */
    public static function totalAtribuidoAParcela(PedidoCompraItemParcela $parcela, ?string $excluirDistribuicaoId = null): float
    {
        $query = RecebimentoPedidoParcela::where('pedido_compra_item_parcela_id', $parcela->id);

        if ($excluirDistribuicaoId) {
            $query->where('id', '!=', $excluirDistribuicaoId);
        }

        return round((float) $query->sum('quantidade'), 3);
    }

    /**
     * Versão em LOTE — pra listagens/read-model, nunca 1 SUM por linha.
     *
     * @param  array<int, string>  $parcelaIds
     * @return Collection<string, float> chave = pedido_compra_item_parcela_id
     */
    public static function totalAtribuidoPorParcelaEmLote(array $parcelaIds): Collection
    {
        if (empty($parcelaIds)) {
            return collect();
        }

        return RecebimentoPedidoParcela::query()
            ->whereIn('pedido_compra_item_parcela_id', $parcelaIds)
            ->groupBy('pedido_compra_item_parcela_id')
            ->selectRaw('pedido_compra_item_parcela_id, SUM(quantidade) as total')
            ->pluck('total', 'pedido_compra_item_parcela_id')
            ->map(fn ($v) => round((float) $v, 3));
    }
}
