<?php

namespace App\Support\Estoque;

use App\Enums\TipoMovimentacaoEstoque;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\MovimentacaoEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.4 — compara, SEM REESCREVER nada, Planejado
 * (`DestinacaoPlanejadaMaterial`) × Real (`AplicacaoMaterialEstoque`)
 * por Pacote+Material+Frente (Seção 20), e agrega a pendência de
 * conciliação de Saídas por obra/Material (Seção 34).
 *
 * **Diferença ≠ desvio** (Seção 21) — os campos `planejado`/`aplicado`/
 * `delta` aqui são só ARITMÉTICA (aplicado - planejado), nunca uma
 * afirmação de causalidade "os X a mais em B vieram de A". Causalidade
 * só é afirmada por `App\Support\Estoque\DesviosAplicacao`, quando há
 * rastreabilidade direta via Reserva.
 *
 * **Acumulado, não só a última Saída** (Seção 32) — `aplicado` soma
 * TODAS as Aplicações históricas daquele Pacote+Material+Frente, nunca
 * só a Aplicação mais recente.
 */
class ConciliacaoAplicacao
{
    /**
     * Planejado × Real por Frente, pra um par Pacote+Material — uma
     * linha por Frente que aparece em QUALQUER um dos dois lados (união,
     * nunca só o lado planejado — uma Frente que recebeu aplicação sem
     * nunca ter sido planejada também aparece, com planejado=0).
     *
     * @return Collection<int, array{frente_trabalho_id: string, planejado: float, aplicado: float, delta: float}>
     */
    public static function porPacoteMaterialFrente(string $itemSuprimentoId, string $materialId): Collection
    {
        $planejadoPorFrente = DestinacaoPlanejadaMaterial::query()
            ->where('item_suprimento_id', $itemSuprimentoId)
            ->where('material_id', $materialId)
            ->selectRaw('frente_trabalho_id, SUM(quantidade_planejada) as total')
            ->groupBy('frente_trabalho_id')
            ->pluck('total', 'frente_trabalho_id')
            ->map(fn ($v) => round((float) $v, 3));

        $aplicadoPorFrente = AplicacaoMaterialEstoque::query()
            ->where('item_suprimento_id', $itemSuprimentoId)
            ->whereHas('movimentacaoEstoque', fn ($q) => $q->where('material_id', $materialId))
            ->selectRaw('frente_trabalho_id, SUM(quantidade) as total')
            ->groupBy('frente_trabalho_id')
            ->pluck('total', 'frente_trabalho_id')
            ->map(fn ($v) => round((float) $v, 3));

        $frenteIds = $planejadoPorFrente->keys()->merge($aplicadoPorFrente->keys())->unique()->values();

        return $frenteIds->map(function (string $frenteId) use ($planejadoPorFrente, $aplicadoPorFrente) {
            $planejado = (float) ($planejadoPorFrente[$frenteId] ?? 0.0);
            $aplicado = (float) ($aplicadoPorFrente[$frenteId] ?? 0.0);

            return [
                'frente_trabalho_id' => $frenteId,
                'planejado' => $planejado,
                'aplicado' => $aplicado,
                'delta' => round($aplicado - $planejado, 3),
            ];
        })->values();
    }

    /**
     * Pendência de conciliação (Seção 34) de UMA Saída — mesma fórmula
     * de `PoliticaConciliacaoAplicacao::pendente()`, reexposta aqui só
     * como conveniência de leitura pra UI (nunca uma segunda regra).
     */
    public static function pendenteDaSaida(MovimentacaoEstoque $saida): float
    {
        return PoliticaConciliacaoAplicacao::pendente($saida);
    }

    /**
     * Agrega pendência de conciliação por Material, escopado por obra —
     * NUNCA soma quantidade entre Materiais/unidades incompatíveis
     * (Seção 34: "agregar sem misturar unidades"). 1 query pra Saídas +
     * 1 query pra Aplicações (agrupada), nunca 1 SUM por Saída.
     *
     * @return Collection<string, array{material_id: string, total_saido: float, total_aplicado: float, pendente: float}>
     */
    public static function pendenteAgregadaPorObra(string $obraId): Collection
    {
        $saidasPorMaterial = MovimentacaoEstoque::query()
            ->where('obra_id', $obraId)
            ->where('tipo', TipoMovimentacaoEstoque::Saida->value)
            ->selectRaw('material_id, SUM(quantidade) as total')
            ->groupBy('material_id')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        if ($saidasPorMaterial->isEmpty()) {
            return collect();
        }

        $saidaIds = MovimentacaoEstoque::query()
            ->where('obra_id', $obraId)
            ->where('tipo', TipoMovimentacaoEstoque::Saida->value)
            ->pluck('id');

        $aplicadoPorMaterial = AplicacaoMaterialEstoque::query()
            ->whereIn('movimentacao_estoque_id', $saidaIds)
            ->join('movimentacoes_estoque', 'movimentacoes_estoque.id', '=', 'aplicacoes_material_estoque.movimentacao_estoque_id')
            ->selectRaw('movimentacoes_estoque.material_id as material_id, SUM(aplicacoes_material_estoque.quantidade) as total')
            ->groupBy('movimentacoes_estoque.material_id')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));

        return $saidasPorMaterial->map(function (float $totalSaido, string $materialId) use ($aplicadoPorMaterial) {
            $totalAplicado = (float) ($aplicadoPorMaterial[$materialId] ?? 0.0);

            return [
                'material_id' => $materialId,
                'total_saido' => $totalSaido,
                'total_aplicado' => $totalAplicado,
                'pendente' => round($totalSaido - $totalAplicado, 3),
            ];
        })->values();
    }
}
