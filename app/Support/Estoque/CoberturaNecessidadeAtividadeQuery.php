<?php

namespace App\Support\Estoque;

use App\Enums\EstadoNecessidadeMaterialAtividade;
use App\Enums\StatusReservaEstoque;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\ItemSuprimento;
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
     * Correção Segura do Falso Positivo (Revisão Arquitetural 2, Seção
     * 13) — "correspondência inequívoca" entre um Pacote de Suprimentos
     * e as `AtividadeNecessidadeMaterial` desta Atividade que ele
     * efetivamente atende: `origem=TakeOff` casa por `item_take_off_id`
     * (mesmo ItemTakeOff alcançado pelas `AlocacaoRequisicaoPacote`
     * deste Pacote, via `requisicaoItem.item_take_off_id`);
     * `origem=Operacional` casa por `material_id` (mesmo Material
     * resolvido pelo `item_take_off_id.material_id` dessas mesmas
     * alocações). Nunca inferido por texto/nome — sempre por FK real.
     *
     * @return Collection<int, AtividadeNecessidadeMaterial>
     */
    public static function necessidadesRelevantesParaPacote(Atividade $atividade, ItemSuprimento $pacote): Collection
    {
        $alocacoes = AlocacaoRequisicaoPacote::where('item_suprimento_id', $pacote->id)
            ->with('requisicaoItem.itemTakeOff:id,material_id')
            ->get();

        $itemTakeOffIds = $alocacoes->pluck('requisicaoItem.itemTakeOff.id')->filter()->unique()->values();
        $materialIds = $alocacoes->pluck('requisicaoItem.itemTakeOff.material_id')->filter()->unique()->values();

        if ($itemTakeOffIds->isEmpty() && $materialIds->isEmpty()) {
            return collect();
        }

        return AtividadeNecessidadeMaterial::query()
            ->where('atividade_id', $atividade->id)
            ->where(function ($query) use ($itemTakeOffIds, $materialIds) {
                $query->whereIn('item_take_off_id', $itemTakeOffIds)
                    ->orWhereIn('material_id', $materialIds);
            })
            ->get();
    }

    /**
     * Correção Segura do Falso Positivo (Seção 13-17) — usada só pelos
     * sincronizadores automáticos de Restrição de Suprimentos, NUNCA
     * pela Central de Prontidão/popup do Plano Semanal (que continuam
     * usando `porAtividade()` diretamente, uma linha por necessidade).
     *
     * Retorna:
     * - `null` — nenhuma `AtividadeNecessidadeMaterial` deste Pacote
     *   nesta Atividade foi encontrada (sem correspondência inequívoca)
     *   — o chamador NUNCA deve suprimir o alerta comercial neste caso,
     *   mantendo o comportamento de sempre.
     * - `true` — TODAS as necessidades relevantes estão `Coberta` ou
     *   `DisponivelParaReserva` (o Material já está fisicamente na obra,
     *   comprometido ou livre o suficiente) — o alerta comercial NUNCA
     *   deve virar bloqueio operacional desta Atividade.
     * - `false` — pelo menos uma necessidade relevante NÃO está coberta
     *   — o comportamento de bloqueio de sempre é preservado.
     */
    public static function estadoCobreTodasParaPacote(Atividade $atividade, ItemSuprimento $pacote): ?bool
    {
        $relevantes = self::necessidadesRelevantesParaPacote($atividade, $pacote);

        if ($relevantes->isEmpty()) {
            return null;
        }

        $cobertura = self::porAtividade($atividade)->keyBy(fn (array $linha) => $linha['necessidade']->id);

        foreach ($relevantes as $necessidade) {
            $linha = $cobertura->get($necessidade->id);
            $estado = $linha['estado'] ?? null;

            if (! in_array($estado, [EstadoNecessidadeMaterialAtividade::Coberta, EstadoNecessidadeMaterialAtividade::DisponivelParaReserva], true)) {
                return false;
            }
        }

        return true;
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
