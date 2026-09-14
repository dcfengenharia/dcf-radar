<?php

namespace App\Enums;

/**
 * Etapa 3 — distingue só os 2 caminhos de código ESTRUTURALMENTE
 * diferentes que criam uma linha de `PedidoCompraPrevisaoEntrega`:
 * `Inicial` (primeira previsão conhecida, criada por `EmitirPedidoCompra`
 * no instante da emissão) e `Revisao` (qualquer mudança posterior,
 * sempre via `App\Actions\Suprimentos\AtualizarPrevisaoEntregaPedidoCompra`).
 * Nunca uma taxonomia de MOTIVO de negócio (Fornecedor/Interna/etc.) —
 * isso é texto livre em `motivo`/`observacao`, sem necessidade real de
 * um enum fixo (Seção 5 do pedido).
 */
enum OrigemPrevisaoEntregaPedido: string
{
    case Inicial = 'inicial';
    case Revisao = 'revisao';

    public function label(): string
    {
        return match ($this) {
            self::Inicial => 'Previsão inicial',
            self::Revisao => 'Revisão de prazo',
        };
    }
}
