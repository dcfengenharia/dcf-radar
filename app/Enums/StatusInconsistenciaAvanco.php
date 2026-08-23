<?php

namespace App\Enums;

/**
 * Ciclo 17, A.9.6 — status funcional de uma InconsistenciaAvanco. Enxuto
 * de propósito (mesmo critério já usado em StatusPlanoAcao): esta fase só
 * implementa ABERTA -> TRATADA, nunca reabertura (decisão do usuário —
 * "se no futuro for necessário reabrir, pode virar etapa separada com
 * histórico próprio"). Uma ocorrência recém-detectada pelo Detector nasce
 * sempre Aberta (`default('aberta')` na migration) — nunca setado à mão
 * pelo Detector, que não conhece este enum.
 */
enum StatusInconsistenciaAvanco: string
{
    case Aberta = 'aberta';
    case Tratada = 'tratada';

    public function label(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::Tratada => 'Tratada',
        };
    }
}
