<?php

namespace App\Enums;

enum TipoCronogramaImportacao: string
{
    case Baseline = 'baseline';
    case Avanco = 'avanco';
    case Ambos = 'ambos';

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'Linha de Base',
            self::Avanco => 'Realizado/Tendência',
            self::Ambos => 'Linha de Base + Realizado/Tendência',
        };
    }
}
