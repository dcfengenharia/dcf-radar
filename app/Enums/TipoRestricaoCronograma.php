<?php

namespace App\Enums;

/**
 * Tipo de restrição de data no MSPDI (elemento `ConstraintType` da Task).
 * Códigos numéricos do schema do MS Project.
 */
enum TipoRestricaoCronograma: string
{
    case AssimQuePossivel = 'ASAP';
    case OMaisTardePossivel = 'ALAP';
    case DeveComecarEm = 'MSO';
    case DeveTerminarEm = 'MFO';
    case NaoComecarAntesDe = 'SNET';
    case NaoComecarDepoisDe = 'SNLT';
    case NaoTerminarAntesDe = 'FNET';
    case NaoTerminarDepoisDe = 'FNLT';

    /**
     * Códigos do MSPDI: 0=ASAP, 1=ALAP, 2=MSO, 3=MFO, 4=SNET, 5=SNLT,
     * 6=FNET, 7=FNLT. Código desconhecido/fora do range retorna null.
     */
    public static function fromCodigoMsProject(int $codigo): ?self
    {
        return match ($codigo) {
            0 => self::AssimQuePossivel,
            1 => self::OMaisTardePossivel,
            2 => self::DeveComecarEm,
            3 => self::DeveTerminarEm,
            4 => self::NaoComecarAntesDe,
            5 => self::NaoComecarDepoisDe,
            6 => self::NaoTerminarAntesDe,
            7 => self::NaoTerminarDepoisDe,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AssimQuePossivel => 'O mais breve possível (ASAP)',
            self::OMaisTardePossivel => 'O mais tarde possível (ALAP)',
            self::DeveComecarEm => 'Deve começar em (MSO)',
            self::DeveTerminarEm => 'Deve terminar em (MFO)',
            self::NaoComecarAntesDe => 'Não começar antes de (SNET)',
            self::NaoComecarDepoisDe => 'Não começar depois de (SNLT)',
            self::NaoTerminarAntesDe => 'Não terminar antes de (FNET)',
            self::NaoTerminarDepoisDe => 'Não terminar depois de (FNLT)',
        };
    }

    /** ASAP/ALAP são "livres" (não fixam uma data específica) — os outros 6 tipos impõem uma data. */
    public function imposDataFixa(): bool
    {
        return $this !== self::AssimQuePossivel && $this !== self::OMaisTardePossivel;
    }
}
