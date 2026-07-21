<?php

namespace App\Enums;

enum StatusProgramacaoSemanal: string
{
    case Aberta = 'aberta';
    case Fechada = 'fechada';

    public function label(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::Fechada => 'Fechada',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Aberta => 'primary',
            self::Fechada => 'success',
        };
    }
}
