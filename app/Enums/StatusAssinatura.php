<?php

namespace App\Enums;

enum StatusAssinatura: string
{
    case Trial = 'trial';
    case Ativa = 'ativa';
    case Cancelada = 'cancelada';
    case Suspensa = 'suspensa';
    case Inadimplente = 'inadimplente';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Ativa => 'Ativa',
            self::Cancelada => 'Cancelada',
            self::Suspensa => 'Suspensa',
            self::Inadimplente => 'Inadimplente',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Trial => 'warning',
            self::Ativa => 'success',
            self::Cancelada => 'secondary',
            self::Suspensa => 'dark',
            self::Inadimplente => 'danger',
        };
    }

    public function concedeAcesso(): bool
    {
        return match ($this) {
            self::Trial, self::Ativa, self::Inadimplente => true,
            self::Cancelada, self::Suspensa => false,
        };
    }
}
