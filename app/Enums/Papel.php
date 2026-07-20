<?php

namespace App\Enums;

enum Papel: string
{
    case Admin = 'admin';
    case GerentePlanejamento = 'gerente_planejamento';
    case Engenheiro = 'engenheiro';
    case Encarregado = 'encarregado';
    case ClienteLeitura = 'cliente_leitura';

    public function nivel(): int
    {
        return match ($this) {
            self::Admin => 5,
            self::GerentePlanejamento => 4,
            self::Engenheiro => 3,
            self::Encarregado => 2,
            self::ClienteLeitura => 1,
        };
    }

    public function podeAoMenos(self $minimo): bool
    {
        return $this->nivel() >= $minimo->nivel();
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::GerentePlanejamento => 'Gerente de Planejamento',
            self::Engenheiro => 'Engenheiro',
            self::Encarregado => 'Encarregado',
            self::ClienteLeitura => 'Cliente (Leitura)',
        };
    }
}
