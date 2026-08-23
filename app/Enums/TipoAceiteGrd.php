<?php

namespace App\Enums;

/**
 * Ciclo 18, Etapa 18.5.9 — os 2 tipos possíveis de aceite de entrega.
 * `SemAssinatura` cobre o caso operacional real ("recebido por João,
 * assinatura indisponível") sem exigir captura visual — nenhum dos dois
 * é "assinatura digital" no sentido jurídico/ICP-Brasil.
 */
enum TipoAceiteGrd: string
{
    case Assinatura = 'assinatura';
    case SemAssinatura = 'sem_assinatura';

    public function label(): string
    {
        return match ($this) {
            self::Assinatura => 'Assinatura',
            self::SemAssinatura => 'Aceite sem assinatura',
        };
    }
}
