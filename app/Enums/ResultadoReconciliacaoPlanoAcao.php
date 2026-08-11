<?php

namespace App\Enums;

/**
 * Resultado de UMA reconciliação de PlanoAcao contra uma nova importação
 * (PlanoAcaoReconciliador) — evento histórico, gravado em
 * PlanoAcaoReconciliacao, nunca em PlanoAcao::status (Fase 4, decisão do
 * usuário: esses conceitos são eventos de reconciliação, não status
 * permanentes da ação).
 */
enum ResultadoReconciliacaoPlanoAcao: string
{
    case Persistente = 'persistente';
    case Agravado     = 'agravado';
    case Alterado     = 'alterado';
    case Resolvido    = 'resolvido';

    public function label(): string
    {
        return match ($this) {
            self::Persistente => 'Persistente',
            self::Agravado => 'Agravado',
            self::Alterado => 'Alterado — requer revisão',
            self::Resolvido => 'Resolvido automaticamente',
        };
    }
}
