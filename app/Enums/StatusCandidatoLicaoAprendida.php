<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.3 — workflow mínimo, ambos terminais (Seção 10 do
 * pedido: "não adicionar estados sem necessidade concreta"). Nenhuma
 * transição de volta pra Pendente nesta etapa.
 */
enum StatusCandidatoLicaoAprendida: string
{
    case Pendente = 'pendente';
    case Convertido = 'convertido';
    case Descartado = 'descartado';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Convertido => 'Convertido',
            self::Descartado => 'Descartado',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Pendente => 'warning',
            self::Convertido => 'success',
            self::Descartado => 'secondary',
        };
    }
}
