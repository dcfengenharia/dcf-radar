<?php

namespace App\Observers;

use App\Exceptions\PrevisaoEntregaInvalidaException;
use App\Models\PedidoCompraPrevisaoEntrega;

/**
 * Etapa 3 — mesmo padrão exato de `RecebimentoPedidoObserver`/
 * `RequisicaoCompraAdjudicacaoObserver`: bloqueia `updating()`/
 * `deleting()` incondicionalmente. A evolução da promessa comercial é um
 * fato histórico — corrigir um erro de registro é sempre um evento NOVO
 * (nova revisão), nunca um `update()`/`delete()` da linha antiga.
 * `forceDelete()` sempre delega pra `delete()` (dispara `deleting` antes
 * de `performDeleteOnModel()`) — um único guard cobre as duas chamadas.
 */
class PedidoCompraPrevisaoEntregaObserver
{
    public function updating(PedidoCompraPrevisaoEntrega $previsao): void
    {
        throw new PrevisaoEntregaInvalidaException(
            'Um registro histórico de previsão de entrega nunca é alterado — registre uma nova revisão em vez disso.'
        );
    }

    public function deleting(PedidoCompraPrevisaoEntrega $previsao): void
    {
        throw new PrevisaoEntregaInvalidaException(
            'Um registro histórico de previsão de entrega nunca é excluído — o histórico é sempre preservado.'
        );
    }
}
