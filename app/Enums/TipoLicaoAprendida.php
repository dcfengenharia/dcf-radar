<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.1 — natureza da lição registrada. Nomes do próprio
 * pedido, sem convenção prévia equivalente no projeto.
 */
enum TipoLicaoAprendida: string
{
    case Problema = 'problema';
    case BoaPratica = 'boa_pratica';
    case Oportunidade = 'oportunidade';
    case RiscoEvitado = 'risco_evitado';

    public function label(): string
    {
        return match ($this) {
            self::Problema => 'Problema',
            self::BoaPratica => 'Boa Prática',
            self::Oportunidade => 'Oportunidade',
            self::RiscoEvitado => 'Risco Evitado',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Problema => 'bx-error-circle',
            self::BoaPratica => 'bx-badge-check',
            self::Oportunidade => 'bx-bulb',
            self::RiscoEvitado => 'bx-shield-quarter',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Problema => 'danger',
            self::BoaPratica => 'success',
            self::Oportunidade => 'info',
            self::RiscoEvitado => 'warning',
        };
    }
}
