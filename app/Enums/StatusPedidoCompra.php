<?php

namespace App\Enums;

/**
 * Ciclo 19, Etapa 19.5 — Rascunho|Emitido. Sem Cancelado/Concluido nesta
 * fase (sem precedente/requisito real confirmado — mesma decisão já
 * tomada pra StatusGrd/StatusRequisicaoPlanejamento/StatusRequisicaoCompra
 * em suas respectivas primeiras etapas). Rascunho→Emitido é ação humana
 * explícita.
 */
enum StatusPedidoCompra: string
{
    case Rascunho = 'rascunho';
    case Emitido = 'emitido';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Emitido => 'Emitido',
        };
    }
}
