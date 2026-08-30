<?php

namespace App\Enums;

/**
 * Ciclo 19, Etapa 19.6 — situação de ENTREGA FÍSICA derivada de um
 * `PedidoCompra` (nunca coluna persistida, nunca confundida com
 * `StatusPedidoCompra` — que é o status DOCUMENTAL/comercial,
 * Rascunho|Emitido). Um Pedido `Emitido` sempre nasce `NaoIniciada` (sem
 * nenhum recebimento ainda) e evolui pra `Parcial`/`Completa` conforme os
 * recebimentos físicos são registrados. Ver
 * `App\Models\PedidoCompra::situacaoEntrega()`.
 */
enum SituacaoEntregaPedido: string
{
    case NaoIniciada = 'nao_iniciada';
    case Parcial = 'parcial';
    case Completa = 'completa';

    public function label(): string
    {
        return match ($this) {
            self::NaoIniciada => 'Não iniciada',
            self::Parcial => 'Entrega parcial',
            self::Completa => 'Entrega completa',
        };
    }
}
