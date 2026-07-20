<?php

namespace App\Enums;

enum StatusItemSuprimento: string
{
    case NoInicio = 'no_inicio';
    case EmAndamento = 'em_andamento';
    case EmRisco = 'em_risco';
    case Atrasado = 'atrasado';
    case Concluido = 'concluido';

    public function label(): string
    {
        return match ($this) {
            self::NoInicio => 'No início',
            self::EmAndamento => 'Em andamento',
            self::EmRisco => 'Em risco',
            self::Atrasado => 'Atrasado',
            self::Concluido => 'Concluído',
        };
    }
}
