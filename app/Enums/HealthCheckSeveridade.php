<?php

namespace App\Enums;

enum HealthCheckSeveridade: string
{
    case Critico = 'critico';
    case Alto = 'alto';
    case Medio = 'medio';
    case Baixo = 'baixo';
    case Informativo = 'informativo';

    public function label(): string
    {
        return match ($this) {
            self::Critico => 'Crítico',
            self::Alto => 'Alto',
            self::Medio => 'Médio',
            self::Baixo => 'Baixo',
            self::Informativo => 'Informativo',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Critico => '🔴',
            self::Alto => '🟠',
            self::Medio => '🟡',
            self::Baixo => '🟢',
            self::Informativo => '🔵',
        };
    }

    /** Classe Bootstrap (badges bg-label-*, alerts, textos) — mesmo idioma de cores já usado no resto do sistema. */
    public function cor(): string
    {
        return match ($this) {
            self::Critico => 'danger',
            self::Alto => 'warning',
            self::Medio => 'warning',
            self::Baixo => 'info',
            self::Informativo => 'secondary',
        };
    }

    /**
     * Peso pra um score futuro (não usado na Fase 1 — o cálculo de Score fica
     * pra uma etapa posterior, ver HealthCheckResultado::scorePreliminar()).
     */
    public function peso(): int
    {
        return match ($this) {
            self::Critico => -10,
            self::Alto => -5,
            self::Medio => -2,
            self::Baixo => -1,
            self::Informativo => 0,
        };
    }
}
