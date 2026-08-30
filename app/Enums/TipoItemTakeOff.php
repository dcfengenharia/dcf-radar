<?php

namespace App\Enums;

enum TipoItemTakeOff: string
{
    case Material = 'material';
    case Instrumento = 'instrumento';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Instrumento => 'Instrumento',
        };
    }
}
