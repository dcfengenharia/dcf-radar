<?php

namespace App\Enums;

/**
 * Ciclo 19, Etapa 19.6 — status DERIVADO de um `PedidoCompraItem` em
 * relação aos seus recebimentos físicos (nunca uma coluna persistida):
 * NaoRecebido (`quantidadeRecebida() <= 0`), ParcialmenteRecebido
 * (`0 < recebida < pedida`), Recebido (`recebida >= pedida`). Ver
 * `App\Models\PedidoCompraItem::statusRecebimento()`.
 */
enum StatusRecebimentoItem: string
{
    case NaoRecebido = 'nao_recebido';
    case ParcialmenteRecebido = 'parcialmente_recebido';
    case Recebido = 'recebido';

    public function label(): string
    {
        return match ($this) {
            self::NaoRecebido => 'Não recebido',
            self::ParcialmenteRecebido => 'Parcialmente recebido',
            self::Recebido => 'Recebido',
        };
    }
}
