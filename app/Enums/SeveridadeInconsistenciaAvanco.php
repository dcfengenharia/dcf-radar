<?php

namespace App\Enums;

/**
 * Ciclo 17, A.9.4 — enum pequeno e dedicado, deliberadamente NÃO reaproveita
 * HealthCheckSeveridade (5 níveis, com peso() acoplado ao Score do Health
 * Check — domínio diferente). Ver
 * App\Services\DetectorInconsistenciasAvanco::severidade() para a matriz
 * completa de decisão.
 */
enum SeveridadeInconsistenciaAvanco: string
{
    case Informativa = 'informativa';
    case Atencao = 'atencao';
    case Critica = 'critica';

    public function label(): string
    {
        return match ($this) {
            self::Informativa => 'Informativa',
            self::Atencao => 'Atenção',
            self::Critica => 'Crítica',
        };
    }
}
