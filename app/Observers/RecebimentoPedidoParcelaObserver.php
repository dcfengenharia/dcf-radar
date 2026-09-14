<?php

namespace App\Observers;

use App\Exceptions\RecebimentoConciliacaoFechadaException;
use App\Exceptions\RecebimentoConciliacaoInvalidaException;
use App\Models\RecebimentoPedido;
use App\Models\RecebimentoPedidoParcela;
use App\Support\Suprimentos\PoliticaDistribuicaoRecebimento;

/**
 * Fechamento Adversarial Etapa 3 — barreira SEMÂNTICA (nunca o
 * mecanismo de atomicidade — isso é o lock em `RecebimentoPedido`
 * sempre adquirido primeiro pela Action oficial, mesma disciplina já
 * documentada em `AplicacaoMaterialEstoqueObserver`/
 * `ItemTakeOffObserver`/`ItemSuprimentoObserver`): bloqueia criar/
 * editar/excluir uma distribuição quando o recebimento dono já está
 * 100% distribuído — cobre a Action oficial, qualquer chamada direta
 * (`$distribuicao->save()`), e qualquer código futuro que toque o
 * model.
 *
 * Mass-update/delete via Query Builder continua sendo API PROIBIDA por
 * convenção arquitetural, nunca por trigger de banco — mesma limitação
 * estrutural já aceita em toda a árvore de Suprimentos/Estoque.
 */
class RecebimentoPedidoParcelaObserver
{
    public function creating(RecebimentoPedidoParcela $distribuicao): void
    {
        $recebimento = RecebimentoPedido::find($distribuicao->recebimento_pedido_id);

        if ($recebimento && PoliticaDistribuicaoRecebimento::recebimentoEstaFechado($recebimento)) {
            throw new RecebimentoConciliacaoFechadaException(
                'Este recebimento já está 100% distribuído por necessidade — não é possível adicionar novas distribuições a ele.'
            );
        }
    }

    public function updating(RecebimentoPedidoParcela $distribuicao): void
    {
        if ($distribuicao->isDirty(['recebimento_pedido_id', 'pedido_compra_item_parcela_id', 'tenant_id', 'created_by_id'])) {
            throw new RecebimentoConciliacaoInvalidaException(
                'Não é possível reatribuir uma distribuição para outro recebimento/parcela/tenant/autor — crie uma nova distribuição.'
            );
        }

        // Nunca exclui a própria linha do cálculo de "fechado" (mesmo
        // padrão exato de AplicacaoMaterialEstoqueObserver) — a pergunta
        // é sempre "este recebimento está fechado AGORA?", nunca "ficaria
        // aberto se eu alterasse esta linha?". Uma vez fechado, NENHUMA
        // distribuição dele é editável, nem mesmo pra reduzir a própria
        // contribuição.
        $recebimento = RecebimentoPedido::find($distribuicao->getOriginal('recebimento_pedido_id'));

        if ($recebimento && PoliticaDistribuicaoRecebimento::recebimentoEstaFechado($recebimento)) {
            throw new RecebimentoConciliacaoFechadaException(
                'Este recebimento já está 100% distribuído por necessidade — as distribuições dele estão congeladas.'
            );
        }
    }

    public function deleting(RecebimentoPedidoParcela $distribuicao): void
    {
        $recebimento = RecebimentoPedido::find($distribuicao->recebimento_pedido_id);

        // Nunca exclui a própria linha do cálculo (mesmo padrão exato de
        // AplicacaoMaterialEstoqueObserver::deleting()) — reabrir uma
        // conciliação já fechada excluindo justamente a linha que a
        // fechou não é permitido (decisão explícita).
        if ($recebimento && PoliticaDistribuicaoRecebimento::recebimentoEstaFechado($recebimento)) {
            throw new RecebimentoConciliacaoFechadaException(
                'Este recebimento já está 100% distribuído por necessidade — não é possível excluir uma distribuição dele (reabrir uma conciliação fechada não é permitido).'
            );
        }
    }
}
