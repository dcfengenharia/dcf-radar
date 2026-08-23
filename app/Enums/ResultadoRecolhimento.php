<?php

namespace App\Enums;

enum ResultadoRecolhimento: string
{
    case Recolhido = 'recolhido';
    case NaoLocalizado = 'nao_localizado';

    public function label(): string
    {
        return match ($this) {
            self::Recolhido => 'Recolhido',
            self::NaoLocalizado => 'Não Localizado',
        };
    }
}
