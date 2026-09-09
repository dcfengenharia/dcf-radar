<?php

namespace App\Enums;

/**
 * Melhoria "Posto Operacional" — as duas origens possíveis de uma
 * `AtividadeNecessidadeMaterial`, mutuamente exclusivas (garantido em 2
 * camadas: `App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade` +
 * CHECK constraint `anm_origem_check` no banco). Nunca uma 3ª origem
 * "mista" — a UI sempre identifica qual das duas é, sem ambiguidade
 * visual.
 */
enum OrigemNecessidadeMaterialAtividade: string
{
    case TakeOff = 'take_off';
    case Operacional = 'operacional';

    public function label(): string
    {
        return match ($this) {
            self::TakeOff => 'TakeOff',
            self::Operacional => 'Necessidade operacional',
        };
    }
}
