<?php

namespace App\Enums;

enum OrigemItemTakeOff: string
{
    case Importado = 'importado';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Importado => 'Importado',
            self::Manual => 'Manual',
        };
    }
}
