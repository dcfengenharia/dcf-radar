<?php

namespace App\Enums;

enum StatusGrd: string
{
    case Rascunho = 'rascunho';
    case Emitida = 'emitida';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Emitida => 'Emitida',
        };
    }

    public function estaEmitida(): bool
    {
        return $this === self::Emitida;
    }
}
