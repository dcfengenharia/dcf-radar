<?php

namespace App\Observers;

use App\Exceptions\AplicacaoConciliacaoFechadaException;
use App\Exceptions\AplicacaoConciliacaoInvalidaException;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\MovimentacaoEstoque;
use App\Support\Estoque\PoliticaConciliacaoAplicacao;

/**
 * Ciclo 20, Etapa 20.4 — barreira SEMÂNTICA (nunca o mecanismo de
 * atomicidade — isso é o lock em `MovimentacaoEstoque` sempre adquirido
 * primeiro pelas 3 Actions de Aplicação, mesma disciplina já documentada
 * em `ItemTakeOffObserver`/`ItemSuprimentoObserver`/`RequisicaoCompraObserver`
 * desde o Ciclo 19): bloqueia criar/editar/excluir uma
 * `AplicacaoMaterialEstoque` quando a Saída dona já está 100%
 * conciliada — cobre a Action oficial, qualquer chamada direta
 * (`$aplicacao->save()`), e qualquer código futuro que toque o model.
 *
 * Mass-update/delete via Query Builder (`AplicacaoMaterialEstoque::
 * where(...)->update()`) continua sendo API PROIBIDA por convenção
 * arquitetural, nunca por trigger de banco — mesma limitação estrutural
 * já aceita em toda a árvore de Estoque desde o Ciclo 20.1.
 */
class AplicacaoMaterialEstoqueObserver
{
    public function creating(AplicacaoMaterialEstoque $aplicacao): void
    {
        $saida = MovimentacaoEstoque::find($aplicacao->movimentacao_estoque_id);

        if ($saida && PoliticaConciliacaoAplicacao::saidaEstaFechada($saida)) {
            throw new AplicacaoConciliacaoFechadaException(
                'Esta Saída já está 100% conciliada — não é possível adicionar novas aplicações a ela.'
            );
        }
    }

    public function updating(AplicacaoMaterialEstoque $aplicacao): void
    {
        if ($aplicacao->isDirty(['movimentacao_estoque_id', 'tenant_id', 'obra_id', 'registrado_por'])) {
            throw new AplicacaoConciliacaoInvalidaException(
                'Não é possível reatribuir uma Aplicação para outra Saída/tenant/obra/autor — crie uma nova Aplicação.'
            );
        }

        $saida = MovimentacaoEstoque::find($aplicacao->getOriginal('movimentacao_estoque_id'));

        if ($saida && PoliticaConciliacaoAplicacao::saidaEstaFechada($saida)) {
            throw new AplicacaoConciliacaoFechadaException(
                'Esta Saída já está 100% conciliada — as aplicações dela estão congeladas.'
            );
        }
    }

    public function deleting(AplicacaoMaterialEstoque $aplicacao): void
    {
        $saida = MovimentacaoEstoque::find($aplicacao->movimentacao_estoque_id);

        if ($saida && PoliticaConciliacaoAplicacao::saidaEstaFechada($saida)) {
            throw new AplicacaoConciliacaoFechadaException(
                'Esta Saída já está 100% conciliada — não é possível excluir uma aplicação dela (reabrir uma conciliação fechada não é permitido).'
            );
        }
    }
}
