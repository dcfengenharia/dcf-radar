<?php

namespace App\Enums;

/**
 * Ciclo 21, Etapa 21.6 — Seção 7 do pedido: "os thresholds devem ser
 * explícitos e, idealmente, parametrizáveis. Não chamar algo de
 * 'crítico' apenas por cor." Classificação SEMPRE derivada de
 * `ItemSuprimento::folgaAtendimento()` (semântica autoritativa da 19.5,
 * NUNCA recalculada) — este enum só rotula o número já calculado, nunca
 * decide o que é folga.
 *
 * Threshold de "pequena" (`App\Support\Gestao\CockpitSuprimentosQuery::
 * LIMIAR_FOLGA_PEQUENA_DIAS`, hoje 7 dias corridos) é uma constante
 * nomeada e documentada — nunca uma cor escolhida arbitrariamente.
 */
enum FaixaFolgaAtendimento: string
{
    case Positiva = 'positiva';
    case Pequena = 'pequena';
    case Atrasada = 'atrasada';
    case SemPrevisaoConfiavel = 'sem_previsao_confiavel';

    public function label(): string
    {
        return match ($this) {
            self::Positiva => 'Folga positiva',
            self::Pequena => 'Folga pequena',
            self::Atrasada => 'Atendimento previsto após a necessidade',
            self::SemPrevisaoConfiavel => 'Sem previsão confiável',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Positiva => 'success',
            self::Pequena => 'warning',
            self::Atrasada => 'danger',
            self::SemPrevisaoConfiavel => 'secondary',
        };
    }

    /**
     * Única regra de classificação — nunca duplicada em outro ponto do
     * código. `$folga` já vem de `ItemSuprimento::folgaAtendimento()`;
     * `null` = sem previsão confiável (Pedido nunca emitido, ou nenhuma
     * RC formal ainda). `$limiarPequenaDias` é sempre passado explícito
     * pelo chamador (nunca hardcoded aqui) — Seção 7: "parametrizável".
     */
    public static function classificar(?int $folga, int $limiarPequenaDias): self
    {
        if ($folga === null) {
            return self::SemPrevisaoConfiavel;
        }

        if ($folga < 0) {
            return self::Atrasada;
        }

        if ($folga <= $limiarPequenaDias) {
            return self::Pequena;
        }

        return self::Positiva;
    }
}
