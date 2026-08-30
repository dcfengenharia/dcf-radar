<?php

namespace App\Enums;

/**
 * Ciclo 19, Etapa 19.4 — Rascunho|Emitida|Concluida. Rascunho→Emitida é
 * ação humana explícita (mesmo padrão de StatusRequisicaoPlanejamento/
 * StatusGrd). Emitida→Concluida é DERIVADA da progressão real das
 * etapas (transicionada automaticamente quando a última etapa recebe
 * `data_realizada`) — nunca um botão manual desconectado da realidade
 * do fluxo. Sem Cancelada nesta fase — mesma decisão já tomada pra RP/
 * Grd: sem necessidade real confirmada.
 */
enum StatusRequisicaoCompra: string
{
    case Rascunho = 'rascunho';
    case Emitida = 'emitida';
    case Concluida = 'concluida';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Emitida => 'Emitida',
            self::Concluida => 'Concluída',
        };
    }
}
