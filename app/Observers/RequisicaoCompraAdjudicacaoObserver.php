<?php

namespace App\Observers;

use App\Exceptions\RequisicaoCompraAdjudicacaoImutavelException;
use App\Models\RequisicaoCompraAdjudicacao;

/**
 * Etapa 2 — mesmo padrão exato de `RequisicaoCompraObserver`/
 * `ListaEngenhariaObserver`: bloqueia `delete()`/`forceDelete()`
 * incondicionalmente. Uma decisão comercial de adjudicação nunca
 * desaparece (Seção 9 do pedido: "não sobrescrever silenciosamente") —
 * o único mecanismo de "desfazer" é `status = Cancelada`
 * (`App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra::cancelar()`),
 * nunca um `delete()`. `forceDelete()` sempre delega pra `delete()`
 * (dispara `deleting` antes de `performDeleteOnModel()`) — um único
 * guard cobre as duas chamadas.
 */
class RequisicaoCompraAdjudicacaoObserver
{
    public function deleting(RequisicaoCompraAdjudicacao $adjudicacao): void
    {
        throw new RequisicaoCompraAdjudicacaoImutavelException(
            'Uma decisão de adjudicação nunca é excluída — cancele-a (preservando o histórico) em vez de tentar apagá-la.'
        );
    }
}
