<?php

namespace App\Enums;

enum StatusRestricao: string
{
    case Aberta              = 'aberta';
    case EmTratamento        = 'em_tratamento';
    case AguardandoTerceiros = 'aguardando_terceiros';
    case Resolvida           = 'resolvida';

    public function label(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::EmTratamento => 'Em Tratamento',
            self::AguardandoTerceiros => 'Aguardando Terceiros',
            self::Resolvida => 'Resolvida',
        };
    }
}
