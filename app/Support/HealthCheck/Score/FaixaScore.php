<?php

namespace App\Support\HealthCheck\Score;

/**
 * Faixas de interpretação do Score de Saúde — única fonte de verdade dos
 * thresholds (nunca espalhar 90/80/70/60 em Blade/Controller/Livewire).
 */
enum FaixaScore: string
{
    case Excelente = 'excelente';
    case Bom = 'bom';
    case Atencao = 'atencao';
    case NecessitaAtencao = 'necessita_atencao';
    case Critico = 'critico';

    public function label(): string
    {
        return match ($this) {
            self::Excelente => 'Excelente',
            self::Bom => 'Bom',
            self::Atencao => 'Atenção',
            self::NecessitaAtencao => 'Necessita atenção',
            self::Critico => 'Crítico',
        };
    }

    /** Classe Bootstrap (badges bg-label-*, alerts) — mesmo idioma de cores já usado no resto do sistema. */
    public function cor(): string
    {
        return match ($this) {
            self::Excelente => 'success',
            self::Bom => 'success',
            self::Atencao => 'warning',
            self::NecessitaAtencao => 'warning',
            self::Critico => 'danger',
        };
    }

    /**
     * 90–100 Excelente · 80–89 Bom · 70–79 Atenção · 60–69 Necessita atenção
     * · 0–59 Crítico. Faixas são uma interpretação do sistema — o
     * diagnóstico de verdade continua sendo o detalhe das ocorrências.
     */
    public static function paraScore(int $score): self
    {
        return match (true) {
            $score >= 90 => self::Excelente,
            $score >= 80 => self::Bom,
            $score >= 70 => self::Atencao,
            $score >= 60 => self::NecessitaAtencao,
            default => self::Critico,
        };
    }
}
