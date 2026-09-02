<?php

namespace App\Support\Industrializacao;

use App\Enums\DirecaoRemessaIndustrializacao;
use App\Models\OrdemIndustrializacao;
use App\Models\ProdutoIndustrializadoConsumo;
use App\Models\RemessaIndustrializacao;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.2 — visão gerencial da Industrialização (Seção 4 do
 * pedido), 100% derivada e obra-escopada, batch. Nunca chama "atrasada"
 * uma Ordem/Produto — `App\Models\OrdemIndustrializacao`/
 * `ProdutoIndustrializado` não têm prazo formal de produção (gap já
 * confirmado desde a auditoria 20.9, reconfirmado aqui por fresh-read:
 * nenhuma coluna `data_prevista_producao`/`prazo` existe em nenhuma das
 * 2 tabelas) — `prazo_industrializacao` é sempre a string literal
 * `'desconhecido'`, nunca uma data inventada nem um cálculo camuflado.
 */
class ResumoIndustrializacaoQuery
{
    /**
     * @return Collection<string, array> chave = ordem_industrializacao_id
     */
    public static function porObra(string $obraId): Collection
    {
        $ordens = OrdemIndustrializacao::query()
            ->where('obra_id', $obraId)
            ->where('status', '!=', \App\Enums\StatusOrdemIndustrializacao::Rascunho->value)
            ->with('produtos:id,ordem_industrializacao_id,quantidade_prevista')
            ->get();

        if ($ordens->isEmpty()) {
            return collect();
        }

        $ordemIds = $ordens->pluck('id')->all();

        $enviadoPorOrdem = RemessaIndustrializacao::query()
            ->whereIn('ordem_industrializacao_id', $ordemIds)
            ->where('direcao', DirecaoRemessaIndustrializacao::Envio->value)
            ->groupBy('ordem_industrializacao_id')
            ->selectRaw('ordem_industrializacao_id, SUM(quantidade) as total')
            ->pluck('total', 'ordem_industrializacao_id')
            ->map(fn ($v) => round((float) $v, 3));

        $retornadoPorOrdem = RemessaIndustrializacao::query()
            ->whereIn('ordem_industrializacao_id', $ordemIds)
            ->where('direcao', DirecaoRemessaIndustrializacao::RetornoSobra->value)
            ->groupBy('ordem_industrializacao_id')
            ->selectRaw('ordem_industrializacao_id, SUM(quantidade) as total')
            ->pluck('total', 'ordem_industrializacao_id')
            ->map(fn ($v) => round((float) $v, 3));

        $consumidoPorOrdem = ProdutoIndustrializadoConsumo::query()
            ->join('remessas_industrializacao', 'remessas_industrializacao.id', '=', 'produto_industrializado_consumos.remessa_industrializacao_id')
            ->whereIn('remessas_industrializacao.ordem_industrializacao_id', $ordemIds)
            ->groupBy('remessas_industrializacao.ordem_industrializacao_id')
            ->selectRaw('remessas_industrializacao.ordem_industrializacao_id as ordem_id, SUM(produto_industrializado_consumos.quantidade_consumida) as total')
            ->pluck('total', 'ordem_id')
            ->map(fn ($v) => round((float) $v, 3));

        $produtoIds = $ordens->flatMap(fn (OrdemIndustrializacao $o) => $o->produtos->pluck('id'))->all();
        $saldoProdutos = \App\Support\Industrializacao\SaldoProdutoIndustrializado::porProdutosEmLote($produtoIds);

        return $ordens->mapWithKeys(function (OrdemIndustrializacao $ordem) use (
            $enviadoPorOrdem, $retornadoPorOrdem, $consumidoPorOrdem, $saldoProdutos
        ) {
            $enviado = (float) ($enviadoPorOrdem[$ordem->id] ?? 0.0);
            $retornado = (float) ($retornadoPorOrdem[$ordem->id] ?? 0.0);
            $consumido = (float) ($consumidoPorOrdem[$ordem->id] ?? 0.0);
            $emPoderTerceiro = round(max(0, $enviado - $retornado - $consumido), 3);

            $previsto = round((float) $ordem->produtos->sum('quantidade_prevista'), 3);
            $produzido = round((float) $ordem->produtos->sum(fn ($p) => $saldoProdutos[$p->id]['produzido'] ?? 0.0), 3);
            $entregue = round((float) $ordem->produtos->sum(fn ($p) => $saldoProdutos[$p->id]['entregue'] ?? 0.0), 3);
            $pendente = round(max(0, $previsto - $entregue), 3);

            return [$ordem->id => [
                'ordem_industrializacao_id' => $ordem->id,
                'previsto' => $previsto,
                'enviado' => $enviado,
                'em_poder_terceiro' => $emPoderTerceiro,
                'consumido' => $consumido,
                'produzido' => $produzido,
                'entregue' => $entregue,
                'pendente' => $pendente,
                'prazo_industrializacao' => 'desconhecido',
            ]];
        });
    }
}
