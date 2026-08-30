<?php

namespace App\Support\Industrializacao;

use App\Enums\DirecaoRemessaIndustrializacao;
use App\Models\ProdutoIndustrializado;
use App\Models\ProdutoIndustrializadoConsumo;
use App\Models\RemessaIndustrializacao;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.5 — genealogia quantitativa N:N
 * ProdutoIndustrializado ↔ RemessaIndustrializacao (decisão do
 * usuário). Navega os 2 sentidos pedidos (Seção 24): "qual matéria-
 * prima formou este produto" e "quais produtos esta remessa gerou".
 * Nunca inventa distribuição automática — só lê o que já foi
 * explicitamente registrado via `App\Actions\Estoque\
 * RegistrarConsumoIndustrializacao`.
 */
class GenealogiaIndustrializacao
{
    /**
     * @return Collection<int, ProdutoIndustrializadoConsumo> consumos com remessa/material carregados
     */
    public static function materiaPrimaDoProduto(ProdutoIndustrializado $produto): Collection
    {
        return ProdutoIndustrializadoConsumo::where('produto_industrializado_id', $produto->id)
            ->with(['remessa.material'])
            ->orderBy('ocorrido_em')
            ->get();
    }

    /**
     * @return Collection<int, ProdutoIndustrializadoConsumo> consumos com produto/material carregados
     */
    public static function produtosDaRemessa(RemessaIndustrializacao $remessa): Collection
    {
        return ProdutoIndustrializadoConsumo::where('remessa_industrializacao_id', $remessa->id)
            ->with(['produto.material'])
            ->orderBy('ocorrido_em')
            ->get();
    }

    /**
     * Saldo ainda não conciliado/consumido de uma Remessa de Envio —
     * mesma fórmula já usada no guard de over-consumo da Action,
     * reexposta aqui só como conveniência de leitura (nunca uma
     * segunda regra).
     */
    public static function pendenteDeConsumo(RemessaIndustrializacao $remessa): ?float
    {
        if ($remessa->direcao !== DirecaoRemessaIndustrializacao::Envio) {
            return null;
        }

        $consumido = (float) ProdutoIndustrializadoConsumo::where('remessa_industrializacao_id', $remessa->id)->sum('quantidade_consumida');

        return round((float) $remessa->quantidade - $consumido, 3);
    }
}
