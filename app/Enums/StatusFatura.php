<?php

namespace App\Enums;

enum StatusFatura: string
{
    case Pendente = 'pendente';
    case Pago = 'pago';
    case Vencido = 'vencido';
    case Cancelado = 'cancelado';
    case Estornado = 'estornado';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Pago => 'Pago',
            self::Vencido => 'Vencido',
            self::Cancelado => 'Cancelado',
            self::Estornado => 'Estornado',
        };
    }

    public function corBadge(): string
    {
        return match ($this) {
            self::Pendente => 'warning',
            self::Pago => 'success',
            self::Vencido => 'danger',
            self::Cancelado => 'secondary',
            self::Estornado => 'dark',
        };
    }
}
