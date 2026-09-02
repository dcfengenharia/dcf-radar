<?php

namespace App\DTOs\Gestao\Cockpit;

/**
 * Ciclo 21, Etapa 21.5 — resumo de cobertura MATERIAL (nunca prontidão
 * operacional completa) de um horizonte (2/4/8 semanas), sempre derivado
 * de `App\Support\Gestao\CoberturaMaterialAtividadeQuery::porObra()`,
 * nunca recalculado no Cockpit (Seção 2 do pedido).
 *
 * **Mapa de agrupamento dos 8 estados de `EstadoCoberturaMaterial` nos 4
 * grupos do pedido (Seção 7), editorial e documentado, nunca um fato
 * objetivo — revisável**:
 * - `cobertas` = `Coberto` (fisicamente reservado e sem déficit).
 * - `parcial` = `ParcialmenteCoberto`, `RecebidoAguardandoDisponibilizacao`
 *   (material já chegou fisicamente, só falta reservar formalmente),
 *   `DeficitAposConsumoEmergencial` (já teve reserva formal, mas o
 *   Material como um todo está em déficit — existe algum progresso, mas
 *   com um problema).
 * - `descobertas` = `SemCobertura`, `AguardandoCompra`,
 *   `CompradoAguardandoRecebimento` — nada fisicamente disponível pra
 *   ESTA atividade ainda, qualquer que seja o estágio do pipeline de
 *   compra (mesmo com Pedido já Emitido, honesto sobre "ainda não
 *   chegou" — Seção 32: nunca converter ausência física em "coberto").
 * - `informacaoInsuficiente` = `InformacaoInsuficiente` (Pacote sem
 *   Atividade vinculada, ou vice-versa — nunca contado como "coberto"
 *   nem como "descoberto").
 *
 * **`percentualCoberturaAvaliavel`** (Seção 8, fórmula sempre explícita,
 * nunca um percentual enganoso): `cobertas / (total - informacaoInsuficiente)`
 * — `InformacaoInsuficiente` NUNCA entra no denominador. `null` quando o
 * denominador (atividades avaliáveis) é zero — nunca 0% nem 100%
 * inventados.
 */
final class CockpitProntidaoHorizonte
{
    public function __construct(
        public readonly int $horizonteDias,
        public readonly int $horizonteSemanas,
        public readonly int $total,
        public readonly int $cobertas,
        public readonly int $parcial,
        public readonly int $descobertas,
        public readonly int $informacaoInsuficiente,
        public readonly ?float $percentualCoberturaAvaliavel,
    ) {
    }

    public function toArray(): array
    {
        return [
            'horizonte_dias' => $this->horizonteDias,
            'horizonte_semanas' => $this->horizonteSemanas,
            'total' => $this->total,
            'cobertas' => $this->cobertas,
            'parcial' => $this->parcial,
            'descobertas' => $this->descobertas,
            'informacao_insuficiente' => $this->informacaoInsuficiente,
            'percentual_cobertura_avaliavel' => $this->percentualCoberturaAvaliavel,
        ];
    }
}
