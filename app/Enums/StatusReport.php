<?php

namespace App\Enums;

enum StatusReport: string
{
    case Rascunho = 'rascunho';
    case Emitido = 'emitido';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Emitido => 'Emitido',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Rascunho => 'warning',
            self::Emitido => 'success',
        };
    }
}
