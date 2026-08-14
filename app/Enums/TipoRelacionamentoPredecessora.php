<?php

namespace App\Enums;

/**
 * Tipo de relacionamento de predecessora no MSPDI (elemento `Type` dentro
 * de `PredecessorLink`). Códigos numéricos do schema do MS Project — nunca
 * espalhar esses números pelo código, sempre passar por
 * fromCodigoMsProject().
 */
enum TipoRelacionamentoPredecessora: string
{
    case FinishToFinish = 'FF';
    case FinishToStart = 'FS';
    case StartToFinish = 'SF';
    case StartToStart = 'SS';

    /**
     * Códigos do MSPDI: 0=FF, 1=FS, 2=SF, 3=SS. Código desconhecido/fora
     * do range retorna null — nunca inventa um tipo.
     */
    public static function fromCodigoMsProject(int $codigo): ?self
    {
        return match ($codigo) {
            0 => self::FinishToFinish,
            1 => self::FinishToStart,
            2 => self::StartToFinish,
            3 => self::StartToStart,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FinishToFinish => 'Término-Término (FF)',
            self::FinishToStart => 'Término-Início (FS)',
            self::StartToFinish => 'Início-Término (SF)',
            self::StartToStart => 'Início-Início (SS)',
        };
    }
}
