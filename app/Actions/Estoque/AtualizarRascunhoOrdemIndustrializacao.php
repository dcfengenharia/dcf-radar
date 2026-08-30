<?php

namespace App\Actions\Estoque;

use App\Exceptions\OrdemIndustrializacaoImutavelException;
use App\Exceptions\OrdemIndustrializacaoInvalidaException;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Material;
use App\Models\OrdemIndustrializacao;
use App\Models\ProdutoIndustrializado;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.5 — adiciona/remove/altera Produtos previstos de
 * uma OrdemIndustrializacao, sempre enquanto ela ainda é Rascunho
 * (`App\Observers\ProdutoIndustrializadoObserver` é a barreira
 * semântica real; aqui a checagem é só pra dar uma mensagem clara
 * cedo, mesma dupla camada de todo o domínio).
 */
class AtualizarRascunhoOrdemIndustrializacao
{
    public function adicionarProduto(
        OrdemIndustrializacao $ordem,
        Material $material,
        float $quantidadePrevista,
        User $usuario,
        ?DocumentoEngenhariaRevisao $documentoRevisao = null,
        ?string $observacao = null,
    ): ProdutoIndustrializado {
        return DB::transaction(function () use ($ordem, $material, $quantidadePrevista, $usuario, $documentoRevisao, $observacao) {
            $ordemTravada = OrdemIndustrializacao::whereKey($ordem->id)->lockForUpdate()->firstOrFail();

            $this->garantirRascunho($ordemTravada);
            $this->garantirQuantidadePositiva($quantidadePrevista);

            if ($documentoRevisao && $documentoRevisao->documento->obra_id !== $ordemTravada->obra_id) {
                throw new OrdemIndustrializacaoInvalidaException('Este Documento de Fabricação não pertence a esta obra.');
            }

            return ProdutoIndustrializado::create([
                'obra_id' => $ordemTravada->obra_id,
                'ordem_industrializacao_id' => $ordemTravada->id,
                'material_id' => $material->id,
                'documento_engenharia_revisao_id' => $documentoRevisao?->id,
                'quantidade_prevista' => $quantidadePrevista,
                'observacao' => $observacao,
                'created_by_id' => $usuario->id,
            ]);
        });
    }

    public function removerProduto(ProdutoIndustrializado $produto): void
    {
        DB::transaction(function () use ($produto) {
            $ordem = OrdemIndustrializacao::whereKey($produto->ordem_industrializacao_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($ordem);

            $produto->delete();
        });
    }

    public function alterarQuantidadePrevista(ProdutoIndustrializado $produto, float $novaQuantidade): ProdutoIndustrializado
    {
        return DB::transaction(function () use ($produto, $novaQuantidade) {
            $ordem = OrdemIndustrializacao::whereKey($produto->ordem_industrializacao_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($ordem);
            $this->garantirQuantidadePositiva($novaQuantidade);

            $produto->update(['quantidade_prevista' => $novaQuantidade]);

            return $produto->fresh();
        });
    }

    private function garantirRascunho(OrdemIndustrializacao $ordem): void
    {
        if (! $ordem->estaRascunho()) {
            throw new OrdemIndustrializacaoImutavelException(
                'Esta Ordem de Industrialização já foi emitida — os produtos previstos não podem mais ser alterados.'
            );
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new OrdemIndustrializacaoInvalidaException('A quantidade prevista precisa ser maior que zero.');
        }
    }
}
