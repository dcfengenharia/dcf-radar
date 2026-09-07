<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 11/12) — 4 resultados possíveis pra uma
 * AVALIAÇÃO de reaplicação (nunca um "AguardandoAvaliacao" aqui — a
 * ausência de qualquer avaliação já É "aguardando", um estado
 * inteiramente derivado, jamais persistido neste enum).
 *
 * Significado estritamente limitado ao que foi de fato registrado
 * (Seção 12) — `Positivo` significa "a equipe registrou uma avaliação
 * positiva da reaplicação", NUNCA "a lição comprovadamente evitou
 * atraso/custo/problema". A UI (label()) preserva essa linguagem —
 * nunca afirma causalidade/resultado operacional medido.
 */
enum ResultadoAvaliacaoReaplicacao: string
{
    case Positivo = 'positivo';
    case Parcial = 'parcial';
    case Negativo = 'negativo';
    case NaoAplicavel = 'nao_aplicavel';

    public function label(): string
    {
        return match ($this) {
            self::Positivo => 'Avaliação Positiva',
            self::Parcial => 'Avaliação Parcial',
            self::Negativo => 'Avaliação Negativa',
            self::NaoAplicavel => 'Não Aplicável',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Positivo => 'success',
            self::Parcial => 'warning',
            self::Negativo => 'danger',
            self::NaoAplicavel => 'secondary',
        };
    }
}
