<?php

namespace App\Actions\Estoque;

use App\Exceptions\AplicacaoConciliacaoFechadaException;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\MovimentacaoEstoque;
use App\Support\Estoque\PoliticaConciliacaoAplicacao;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.4 — remove UMA linha de Aplicação, enquanto a Saída
 * dona ainda não estiver 100% conciliada. Bloqueado incondicionalmente
 * quando a Saída já está fechada (decisão do usuário: excluir uma linha
 * só para "reabrir" artificialmente uma conciliação já completa nunca é
 * permitido nesta etapa).
 */
class RemoverAplicacaoMaterialEstoque
{
    public function execute(AplicacaoMaterialEstoque $aplicacao): void
    {
        DB::transaction(function () use ($aplicacao) {
            $saidaTravada = MovimentacaoEstoque::whereKey($aplicacao->movimentacao_estoque_id)->lockForUpdate()->firstOrFail();

            if (PoliticaConciliacaoAplicacao::saidaEstaFechada($saidaTravada)) {
                throw new AplicacaoConciliacaoFechadaException(
                    'Esta Saída já está 100% conciliada — não é possível excluir uma aplicação dela.'
                );
            }

            $aplicacao->delete();
        });
    }
}
