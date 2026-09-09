<?php

namespace App\Support\Estoque;

use App\Enums\EstadoNecessidadeMaterialAtividade;
use App\Enums\StatusReservaEstoque;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\ReservaEstoque;
use Illuminate\Support\Collection;

/**
 * Melhoria "Posto Operacional" — classe/DTO/query ÚNICA (Seção 9 do
 * pedido), fora do Blade, pra "esta atividade tem o que precisa, agora,
 * considerando o físico/reservado da obra inteira?". Deliberadamente
 * SEPARADA de `App\Support\Gestao\CoberturaMaterialAtividadeQuery`
 * (Ciclo 21.1) — aquela responde "saúde do pipeline de compra por
 * Pacote" (macro, Cockpit), esta responde "esta necessidade individual
 * está coberta" (popup, sob demanda). Nunca a mesma pergunta, nunca a
 * mesma fórmula — reaproveita só `App\Support\Estoque\SaldoEstoque`/
 * `SaldoReserva` (as fontes físicas autoritativas), nunca duplica a
 * matemática delas.
 *
 * **`Coberta` significa cobertura ESPECÍFICA e real** (Seção 9, "IMPORTANTE"
 * do pedido): `reservado_atividade >= necessario` — nunca "existe
 * estoque físico em algum lugar da obra". Esse é exatamente o motivo de
 * `DisponivelParaReserva` existir como estado PRÓPRIO, separado de
 * `Coberta`.
 *
 * **Unidade incompatível — nunca calculada, nunca um zero mentiroso**:
 * quando `AtividadeNecessidadeMaterial.unidade_medida_id` diverge da
 * unidade canônica do Material efetivo, NENHUMA subtração/divisão é
 * executada — todos os campos quantitativos (`fisico_obra`/
 * `reservado_obra`/`livre_obra`/`reservado_atividade`/
 * `faltante_para_reservar`/`deficit`) ficam `null`, e o estado é sempre
 * `UnidadeIncompativel`.
 *
 * **Custo O(1) por chamada** (Section 15 do pedido): 3 queries fixas por
 * atividade, independente do número de necessidades — `SaldoEstoque::
 * porMateriaisNaObra()`/`SaldoReserva::porMateriaisNaObra()` (já batch,
 * 1 query cada) + 1 agregação própria de "reservado por necessidade"
 * (`GROUP BY necessidade_atividade_id`), nunca 1 query por linha.
 */
class CoberturaNecessidadeAtividadeQuery
{
    /**
     * @return Collection<int, array> uma linha por AtividadeNecessidadeMaterial
     */
    public static function porAtividade(Atividade $atividade): Collection
    {
        $necessidades = AtividadeNecessidadeMaterial::query()
            ->where('atividade_id', $atividade->id)
            ->with([
                'itemTakeOff.material.unidadeMedida',
                'materialDireto.unidadeMedida',
                'unidadeMedida',
                'autor',
            ])
            ->get();

        if ($necessidades->isEmpty()) {
            return collect();
        }

        $materiaisPorNecessidade = $necessidades->mapWithKeys(
            fn (AtividadeNecessidadeMaterial $n) => [$n->id => $n->material()]
        );

        $materialIds = $materiaisPorNecessidade->filter()->map(fn ($m) => $m->id)->unique()->values()->all();

        $fisicoPorMaterial = SaldoEstoque::porMateriaisNaObra($materialIds, $atividade->obra_id);
        $reservadoObraPorMaterial = SaldoReserva::porMateriaisNaObra($materialIds, $atividade->obra_id);

        $reservadoAtividadePorNecessidade = ReservaEstoque::query()
            ->whereIn('necessidade_atividade_id', $necessidades->pluck('id'))
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->selectRaw('necessidade_atividade_id, SUM(quantidade) as total')
            ->groupBy('necessidade_atividade_id')
            ->get()
            ->keyBy('necessidade_atividade_id')
            ->map(fn ($row) => (float) $row->total);

        return $necessidades->map(function (AtividadeNecessidadeMaterial $necessidade) use (
            $materiaisPorNecessidade, $fisicoPorMaterial, $reservadoObraPorMaterial, $reservadoAtividadePorNecessidade
        ) {
            $material = $materiaisPorNecessidade[$necessidade->id];
            $necessario = (float) $necessidade->quantidade_necessaria;
            $reservadoAtividade = (float) ($reservadoAtividadePorNecessidade[$necessidade->id] ?? 0.0);

            $unidadeCompativel = $material !== null
                && $necessidade->unidade_medida_id === $material->unidade_medida_id;

            if (! $unidadeCompativel) {
                return [
                    'necessidade' => $necessidade,
                    'material' => $material,
                    'unidade_necessidade' => $necessidade->unidadeMedida,
                    'unidade_material' => $material?->unidadeMedida,
                    'unidade_compativel' => false,
                    'necessario' => $necessario,
                    'fisico_obra' => null,
                    'reservado_obra' => null,
                    'livre_obra' => null,
                    'reservado_atividade' => $reservadoAtividade,
                    'faltante_para_reservar' => null,
                    'deficit' => null,
                    'estado' => EstadoNecessidadeMaterialAtividade::UnidadeIncompativel,
                ];
            }

            $fisicoObra = (float) ($fisicoPorMaterial[$material->id] ?? 0.0);
            $reservadoObra = (float) ($reservadoObraPorMaterial[$material->id] ?? 0.0);
            $livreObra = round($fisicoObra - $reservadoObra, 3);

            $faltanteParaReservar = round(max(0, $necessario - $reservadoAtividade), 3);
            $deficit = round(max(0, $faltanteParaReservar - $livreObra), 3);

            return [
                'necessidade' => $necessidade,
                'material' => $material,
                'unidade_necessidade' => $necessidade->unidadeMedida,
                'unidade_material' => $material->unidadeMedida,
                'unidade_compativel' => true,
                'necessario' => $necessario,
                'fisico_obra' => $fisicoObra,
                'reservado_obra' => $reservadoObra,
                'livre_obra' => $livreObra,
                'reservado_atividade' => $reservadoAtividade,
                'faltante_para_reservar' => $faltanteParaReservar,
                'deficit' => $deficit,
                'estado' => self::classificar($faltanteParaReservar, $livreObra, $reservadoAtividade),
            ];
        })->values();
    }

    /**
     * Árvore de decisão ÚNICA (nunca duplicada em nenhum outro ponto do
     * código). Tolerância de 0.0005 pra comparação de ponto flutuante,
     * mesmo padrão já usado em toda a Etapa 20/21.
     */
    private static function classificar(float $faltanteParaReservar, float $livreObra, float $reservadoAtividade): EstadoNecessidadeMaterialAtividade
    {
        if ($faltanteParaReservar <= 0.0005) {
            return EstadoNecessidadeMaterialAtividade::Coberta;
        }

        if ($livreObra >= $faltanteParaReservar - 0.0005) {
            return EstadoNecessidadeMaterialAtividade::DisponivelParaReserva;
        }

        if ($reservadoAtividade > 0.0005 || $livreObra > 0.0005) {
            return EstadoNecessidadeMaterialAtividade::Parcial;
        }

        return EstadoNecessidadeMaterialAtividade::SemEstoque;
    }
}
