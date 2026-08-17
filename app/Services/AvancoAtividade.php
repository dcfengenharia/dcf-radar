<?php

namespace App\Services;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\AvancoPeriodo;
use Illuminate\Support\Collection;

/**
 * Ciclo 17, A.8 — fonte canônica ÚNICA do indicador resumido "% Realizado"
 * de uma atividade, usada igualmente pela tabela do Lookahead (em lote) e
 * pelo popup de detalhe (uma atividade) — elimina a duas-implementações-da-
 * mesma-regra que causava divergência entre os dois (tabela lia
 * AvancoPeriodo bruto em Mensal; popup derivava de
 * CurvaAvanco::calcular(), que usa a granularidade escolhida no seletor do
 * gráfico — Semanal por padrão — e aplica CurvaAjuste).
 *
 * Deliberadamente INDEPENDENTE de CurvaAvanco::calcular():
 *
 * — Granularidade sempre Mensal (self::GRANULARIDADE_CANONICA), nunca a
 *   escolha de visualização do gráfico (modalGranularidade) — o indicador
 *   resumido é um FATO sobre a fotografia selecionada, não uma
 *   representação visual; a granularidade da curva só decide como os
 *   PONTOS são agrupados/rotulados, nunca o que o número final significa.
 *
 * — NUNCA aplica CurvaAjuste. Achado da investigação (Ciclo 17, A.8):
 *   `curva_ajustes` não tem NENHUMA coluna de escopo por atividade (nem
 *   `atividade_id`, nem `cronograma_importacao_id`) — o modelo de ajuste
 *   é inteiramente sobre agregações (obra inteira, ou filtrado por
 *   pacote/etapa/disciplina/frente/entregável/equipe/personalizados/
 *   faturamento direto), nunca uma atividade individual. E a query de
 *   ajustes dentro de `CurvaAvanco::calcular()` NUNCA filtra por
 *   `atividadeId` — quando chamada com `atividadeId` e nenhum outro
 *   filtro (como o popup de detalhe do Lookahead faz), ela cai no
 *   "bucket" de ajustes GLOBAIS (todos os filtros null, curva geral do
 *   empreendimento) e aplica esse valor à curva de QUALQUER atividade
 *   individual — contaminação real, pré-existente, fora do escopo desta
 *   correção (seria uma mudança de comportamento de
 *   `CurvaAvanco::calcular()`, usado também por Curvas S/Report/Dashboard/
 *   Benchmarking). Este serviço evita o problema por construção, nunca
 *   reaproveitando esse caminho — o indicador é sempre a soma bruta de
 *   `avanco_periodos.horas`, o mesmo valor que o próprio ajuste usa como
 *   "valor_calculado" antes de qualquer edição manual.
 */
class AvancoAtividade
{
    private const GRANULARIDADE_CANONICA = GranularidadePeriodo::Mensal;

    /**
     * HH Previsto por atividade (uma linha por atividade_id, só as que têm
     * ao menos 1 registro — ausência de chave = sem dado, nunca zero).
     */
    public function hhPrevistoEmLote(iterable $atividadeIds, ?string $baselineImportacaoId): Collection
    {
        return $this->somaPorAtividade($atividadeIds, $baselineImportacaoId, SerieAvanco::Previsto);
    }

    /**
     * HH Realizado por atividade — mesma forma de hhPrevistoEmLote(), pro
     * lado do avanço.
     */
    public function hhRealizadoEmLote(iterable $atividadeIds, ?string $avancoImportacaoId): Collection
    {
        return $this->somaPorAtividade($atividadeIds, $avancoImportacaoId, SerieAvanco::Realizado);
    }

    private function somaPorAtividade(iterable $atividadeIds, ?string $importacaoId, SerieAvanco $serie): Collection
    {
        if (!$importacaoId) {
            return collect();
        }

        $ids = $atividadeIds instanceof Collection ? $atividadeIds->all() : $atividadeIds;
        if (empty($ids)) {
            return collect();
        }

        return AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
            ->where('serie', $serie->value)
            ->where('granularidade', self::GRANULARIDADE_CANONICA->value)
            ->whereIn('atividade_id', $ids)
            ->selectRaw('atividade_id, SUM(horas) as total_horas')
            ->groupBy('atividade_id')
            ->pluck('total_horas', 'atividade_id');
    }

    /**
     * Deriva o percentual de UMA atividade a partir dos mapas já resolvidos
     * em lote (nunca dispara query própria — o chamador resolve os mapas
     * uma única vez, por isso este método é seguro de chamar dentro de um
     * `map()`/`foreach` sem virar N+1).
     *
     * null quando: sem HH Previsto pra essa atividade (sem denominador);
     * Previsto soma <= 0 (nada pra rebasear); ou sem NENHUM registro
     * Realizado pra essa atividade nessa importação (sem dado, nunca 0%
     * inventado). Só retorna 0.0 quando existe ao menos 1 registro
     * Realizado somando exatamente zero.
     */
    public function percentualDoMapa(string $atividadeId, Collection $hhPrevistoPorAtividade, Collection $hhRealizadoPorAtividade): ?float
    {
        if (!$hhPrevistoPorAtividade->has($atividadeId)) {
            return null;
        }

        $previsto = (float) $hhPrevistoPorAtividade->get($atividadeId);
        if ($previsto <= 0) {
            return null;
        }

        if (!$hhRealizadoPorAtividade->has($atividadeId)) {
            return null;
        }

        return round((float) $hhRealizadoPorAtividade->get($atividadeId) / $previsto * 100, 1);
    }
}
