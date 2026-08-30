<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.5 — direção física de uma RemessaIndustrializacao.
 * Envio: Saida no Local próprio + Entrada no Local Terceiro. RetornoSobra:
 * o inverso (matéria-prima nunca consumida volta fisicamente à obra —
 * Seção 22, "sobra pode retornar à obra").
 */
enum DirecaoRemessaIndustrializacao: string
{
    case Envio = 'envio';
    case RetornoSobra = 'retorno_sobra';

    public function label(): string
    {
        return match ($this) {
            self::Envio => 'Envio ao terceiro',
            self::RetornoSobra => 'Retorno de sobra',
        };
    }
}
