<?php

namespace App\Enums;

/**
 * Estado de aplicabilidade de uma categoria de Health Check numa importação
 * específica — Ciclo 10 (Caminho 3 da revisão funcional/arquitetural,
 * "Transparência Baseline"). Puramente de apresentação: nunca influencia o
 * Score, nunca é persistido — sempre derivado em tempo de leitura (ver
 * App\Support\HealthCheck\AplicabilidadeCategoria).
 *
 * Não confundir com HealthCheckSeveridade: este enum não julga a saúde do
 * cronograma, só informa se as regras daquela categoria chegaram a rodar
 * nesta importação — por isso as cores/ícones são deliberadamente neutros,
 * nunca reaproveitam o vermelho/laranja de severidade.
 */
enum EstadoAplicabilidadeCategoria: string
{
    case Avaliada = 'avaliada';
    case ParcialmenteAvaliada = 'parcialmente_avaliada';
    case NaoAvaliada = 'nao_avaliada';

    public function label(): string
    {
        return match ($this) {
            self::Avaliada => 'Avaliada',
            self::ParcialmenteAvaliada => 'Parcialmente avaliada',
            self::NaoAvaliada => 'Não avaliada nesta importação',
        };
    }

    /** Classe Bootstrap (badges bg-label-*) — deliberadamente neutra, nunca a cor de severidade. */
    public function cor(): string
    {
        return match ($this) {
            self::Avaliada => 'secondary',
            self::ParcialmenteAvaliada => 'info',
            self::NaoAvaliada => 'dark',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Avaliada => 'bx-check-circle',
            self::ParcialmenteAvaliada => 'bx-info-circle',
            self::NaoAvaliada => 'bx-minus-circle',
        };
    }
}
