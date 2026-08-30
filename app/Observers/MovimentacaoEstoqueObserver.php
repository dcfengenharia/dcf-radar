<?php

namespace App\Observers;

use App\Exceptions\MovimentacaoEstoqueImutavelException;
use App\Models\MovimentacaoEstoque;

/**
 * Ciclo 20, Etapa 20.1 — mesmo padrão exato de
 * App\Observers\RecebimentoPedidoObserver (Ciclo 19, 19.6.CORREÇÃO):
 * updating() e deleting() bloqueiam SEMPRE, incondicionalmente. Nenhum
 * campo de uma MovimentacaoEstoque já criada pode ser alterado — não
 * existe "correção", só estorno/ajuste futuro como um EVENTO NOVO
 * (fase futura, não implementada nesta etapa).
 *
 * Limitação estrutural conhecida e aceita (mesma já documentada em
 * RecebimentoPedidoObserver): Query Builder cru e mass update Eloquent
 * bypassam Observers por natureza do framework — nenhum writer de
 * produção usa qualquer uma das duas formas contra esta tabela; ambas
 * são API PROIBIDA por convenção arquitetural, não por trigger de
 * banco.
 */
class MovimentacaoEstoqueObserver
{
    public function updating(MovimentacaoEstoque $movimentacao): void
    {
        throw new MovimentacaoEstoqueImutavelException(
            'Movimentações de estoque são fatos históricos e não podem ser alteradas ou excluídas.'
        );
    }

    public function deleting(MovimentacaoEstoque $movimentacao): void
    {
        throw new MovimentacaoEstoqueImutavelException(
            'Movimentações de estoque são fatos históricos e não podem ser alteradas ou excluídas.'
        );
    }
}
