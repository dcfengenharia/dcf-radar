<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.2 — status de uma ReservaEstoque. Enxuto de
 * propósito (instrução explícita do pedido, Seção 23): "não antecipar
 * estados desnecessários se forem deriváveis" — Parcialmente
 * Consumida/Consumida pertencem à Saída física (Ciclo 20.3, ainda não
 * implementada) e nunca são antecipados aqui. Só 2 estados, transição
 * única e irreversível (Ativa -> Liberada, nunca o contrário — mesmo
 * espírito de StatusPlanoAcao/StatusRequisicaoPlanejamento: enum
 * pequeno, sem reabertura nesta fase).
 */
enum StatusReservaEstoque: string
{
    case Ativa = 'ativa';
    case Liberada = 'liberada';

    public function label(): string
    {
        return match ($this) {
            self::Ativa => 'Ativa',
            self::Liberada => 'Liberada',
        };
    }

    public function estaAtiva(): bool
    {
        return $this === self::Ativa;
    }
}
