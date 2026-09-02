<?php

namespace App\DTOs\Gestao\Cockpit;

/**
 * Ciclo 22, Etapa 22.2 — 1 linha da tabela "Prontidão Documental" (2/4/8
 * semanas). Mesma filosofia de `CockpitProntidaoHorizonte` (Ciclo 21.5):
 * `percentualLiberadoAvaliavel` sempre com denominador EXPLÍCITO
 * (liberadas / (total - informacaoInsuficiente)) — `informacaoInsuficiente`
 * nunca entra no numerador nem no denominador (Seção 10 do pedido).
 */
final readonly class CockpitProntidaoEngenhariaHorizonte
{
    public function __construct(
        public int $horizonteDias,
        public int $horizonteSemanas,
        public int $total,
        public int $liberadas,
        public int $parciais,
        public int $bloqueadas,
        public int $informacaoInsuficiente,
        public ?float $percentualLiberadoAvaliavel,
    ) {
    }
}
