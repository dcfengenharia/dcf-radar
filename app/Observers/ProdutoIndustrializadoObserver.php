<?php

namespace App\Observers;

use App\Exceptions\OrdemIndustrializacaoImutavelException;
use App\Models\OrdemIndustrializacao;
use App\Models\ProdutoIndustrializado;

/**
 * Ciclo 20, Etapa 20.5 — um ProdutoIndustrializado só pode ser criado/
 * editado/excluído enquanto a Ordem dona ainda é Rascunho (Seção 17:
 * "não sobrescrever quantidade prevista" depois que a Ordem já
 * comprometeu formalmente com o Fornecedor). Depois de Emitida, o
 * conjunto de Produtos fica congelado pra sempre.
 */
class ProdutoIndustrializadoObserver
{
    public function creating(ProdutoIndustrializado $produto): void
    {
        $this->garantirOrdemRascunho($produto);
    }

    public function updating(ProdutoIndustrializado $produto): void
    {
        $this->garantirOrdemRascunho($produto);
    }

    public function deleting(ProdutoIndustrializado $produto): void
    {
        $this->garantirOrdemRascunho($produto);
    }

    private function garantirOrdemRascunho(ProdutoIndustrializado $produto): void
    {
        $ordem = OrdemIndustrializacao::find($produto->ordem_industrializacao_id);

        if ($ordem && ! $ordem->estaRascunho()) {
            throw new OrdemIndustrializacaoImutavelException(
                'A Ordem de Industrialização já foi emitida — os produtos previstos não podem mais ser alterados.'
            );
        }
    }
}
