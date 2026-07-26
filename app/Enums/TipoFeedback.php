<?php

namespace App\Enums;

enum TipoFeedback: string
{
    case Erro = 'erro';
    case Melhoria = 'melhoria';
    case Critica = 'critica';

    public function label(): string
    {
        return match ($this) {
            self::Erro => 'Erro no sistema',
            self::Melhoria => 'Melhoria / Sugestão',
            self::Critica => 'Crítica',
        };
    }
}
