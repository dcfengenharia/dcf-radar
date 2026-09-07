<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.1 — classificação simples e explícita, nunca um
 * score matemático arbitrário (instrução explícita do pedido).
 */
enum CriticidadeLicao: string
{
    case Baixa = 'baixa';
    case Media = 'media';
    case Alta = 'alta';
    case Critica = 'critica';

    public function label(): string
    {
        return match ($this) {
            self::Baixa => 'Baixa',
            self::Media => 'Média',
            self::Alta => 'Alta',
            self::Critica => 'Crítica',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Baixa => 'secondary',
            self::Media => 'info',
            self::Alta => 'warning',
            self::Critica => 'danger',
        };
    }

    /**
     * Ciclo 23, Etapa 23.4 — peso ORDINAL só pra desempate de ordenação
     * (Seção 34 do pedido: "criticidade da própria lição" como 2º
     * critério de ordem) — nunca um score de probabilidade/risco, é a
     * mesma classificação de 4 níveis já existente, só convertida pra
     * inteiro comparável.
     */
    public function peso(): int
    {
        return match ($this) {
            self::Baixa => 1,
            self::Media => 2,
            self::Alta => 3,
            self::Critica => 4,
        };
    }
}
