<?php

namespace App\Enums;

/**
 * Natureza de uma regra de Health Check — separa regras que avaliam a
 * QUALIDADE DO PLANEJAMENTO (estrutura, lógica, datas planejadas, folgas,
 * baseline) das que avaliam a EXECUÇÃO REAL da obra (datas reais, HH
 * realizado, percentual concluído, atraso).
 *
 * Regra de negócio (ver CLAUDE.md, "Health Check — Separação Baseline x
 * Avanço"): importação de Baseline avalia só `Planejamento`; importação de
 * Avanço/Ambos avalia `Planejamento` + `Execucao` (todas as 36 regras).
 * Não existe caso "Ambos" nesta classificação — uma regra `Planejamento` já
 * participa naturalmente dos dois contextos.
 */
enum HealthCheckNaturezaRegra: string
{
    case Planejamento = 'planejamento';
    case Execucao = 'execucao';

    public function label(): string
    {
        return match ($this) {
            self::Planejamento => 'Planejamento',
            self::Execucao => 'Execução',
        };
    }
}
