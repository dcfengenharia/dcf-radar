<?php

namespace App\Support\Industrializacao;

use App\Enums\DirecaoRemessaIndustrializacao;
use App\Models\Material;
use App\Models\OrdemIndustrializacao;
use App\Models\ProdutoIndustrializadoConsumo;
use App\Models\RemessaIndustrializacao;
use App\Support\Estoque\SaldoEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.5 — dashboard mínimo de matéria-prima em terceiro
 * (Seção 46): por Ordem+Material, quanto foi enviado / consumido /
 * devolvido / e qual o saldo atual em custódia do terceiro. Nunca soma
 * unidades incompatíveis (sempre por par Ordem+Material).
 *
 * **Diferença não classificada** (Seção 23, decisão explícita — "não
 * criar inventário/ajuste contábil nesta etapa"): `enviado - consumido
 * - devolvido`, exibida como "possível sucata/perda/pendência —
 * sem classificação própria nesta fase", NUNCA rotulada
 * automaticamente como "perda" ou "sucata". Puramente derivada, nunca
 * uma linha/coluna própria.
 */
class SaldoMateriaPrimaIndustrializacao
{
    public static function porOrdemMaterial(OrdemIndustrializacao $ordem, Material $material): array
    {
        $enviado = (float) RemessaIndustrializacao::where('ordem_industrializacao_id', $ordem->id)
            ->where('material_id', $material->id)
            ->where('direcao', DirecaoRemessaIndustrializacao::Envio->value)
            ->sum('quantidade');

        $devolvido = (float) RemessaIndustrializacao::where('ordem_industrializacao_id', $ordem->id)
            ->where('material_id', $material->id)
            ->where('direcao', DirecaoRemessaIndustrializacao::RetornoSobra->value)
            ->sum('quantidade');

        $remessaIdsEnvio = RemessaIndustrializacao::where('ordem_industrializacao_id', $ordem->id)
            ->where('material_id', $material->id)
            ->where('direcao', DirecaoRemessaIndustrializacao::Envio->value)
            ->pluck('id');

        $consumido = (float) ProdutoIndustrializadoConsumo::whereIn('remessa_industrializacao_id', $remessaIdsEnvio)->sum('quantidade_consumida');

        $localTerceiro = $ordem->localTerceiro;
        $saldoEmTerceiro = $localTerceiro ? SaldoEstoque::porMaterialLocal($material, $localTerceiro) : 0.0;

        $diferencaNaoClassificada = round($enviado - $consumido - $devolvido, 3);

        return [
            'enviado' => round($enviado, 3),
            'consumido' => round($consumido, 3),
            'devolvido' => round($devolvido, 3),
            'saldo_em_terceiro' => round($saldoEmTerceiro, 3),
            'diferenca_nao_classificada' => $diferencaNaoClassificada,
        ];
    }

    /**
     * Todos os pares Ordem+Material com alguma remessa, pra montar o
     * dashboard sem o chamador precisar descobrir os pares sozinho.
     *
     * @return Collection<int, array{ordem_industrializacao_id: string, material_id: string}>
     */
    public static function paresComRemessa(string $obraId): Collection
    {
        return RemessaIndustrializacao::where('obra_id', $obraId)
            ->select('ordem_industrializacao_id', 'material_id')
            ->distinct()
            ->get()
            ->map(fn ($r) => ['ordem_industrializacao_id' => $r->ordem_industrializacao_id, 'material_id' => $r->material_id]);
    }
}
