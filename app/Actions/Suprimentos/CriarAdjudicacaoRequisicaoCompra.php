<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Exceptions\RequisicaoCompraAdjudicacaoInvalidaException;
use App\Models\Fornecedor;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraAdjudicacao;
use App\Models\RequisicaoCompraAnexo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 2 — cria o CABEÇALHO de uma decisão de adjudicação (sem itens
 * ainda — mesmo padrão em 2 passos de `CriarRequisicaoCompra` +
 * `AtualizarRascunhoRequisicaoCompra::adicionarItem()`).
 *
 * **Fronteira de lifecycle (Seção 8 do pedido)**: só é possível
 * adjudicar uma RC já `Emitida`/`Concluida`, nunca Rascunho — mesma
 * fronteira já usada por `CriarPedidoCompra` (a quantidade de
 * `RequisicaoCompraItem` só é real/congelada a partir da emissão da RC,
 * e a adjudicação distribui exatamente essa quantidade congelada entre
 * fornecedores). Nenhum gate configurável novo, nenhuma etapa de
 * "Cotação"/"Aprovação" obrigatória (Seção 33).
 *
 * **Fornecedor SEMPRE revalidado FRESH, nunca via relação/objeto já
 * carregado, nunca `withTrashed()`** (mesmo padrão de
 * `EmitirPedidoCompra`, 19.5.CORREÇÃO) — um fornecedor de outra obra ou
 * soft-deletado é rejeitado, nunca silenciosamente aceito.
 */
class CriarAdjudicacaoRequisicaoCompra
{
    public function execute(
        RequisicaoCompra $rc,
        Fornecedor $fornecedor,
        string $justificativa,
        ?string $observacao,
        ?RequisicaoCompraAnexo $anexo,
        User $usuario,
    ): RequisicaoCompraAdjudicacao {
        return DB::transaction(function () use ($rc, $fornecedor, $justificativa, $observacao, $anexo, $usuario) {
            $rc = RequisicaoCompra::whereKey($rc->id)->lockForUpdate()->firstOrFail();

            $this->garantirRcAdjudicavel($rc);
            $this->garantirFornecedorValido($rc, $fornecedor);
            $this->garantirAnexoValido($rc, $anexo);
            $this->garantirJustificativaPreenchida($justificativa);

            return RequisicaoCompraAdjudicacao::create([
                'requisicao_compra_id' => $rc->id,
                'fornecedor_id' => $fornecedor->id,
                'status' => StatusAdjudicacaoRequisicaoCompra::Ativa,
                'decidido_por_id' => $usuario->id,
                'decidido_em' => now(),
                'justificativa' => $justificativa,
                'observacao' => $observacao,
                'anexo_id' => $anexo?->id,
            ]);
        });
    }

    private function garantirRcAdjudicavel(RequisicaoCompra $rc): void
    {
        if ($rc->estaRascunho()) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'Só é possível registrar uma adjudicação a partir de uma Requisição de Compra já Emitida.'
            );
        }
    }

    private function garantirFornecedorValido(RequisicaoCompra $rc, Fornecedor $fornecedor): void
    {
        $fresh = Fornecedor::query()->where('obra_id', $rc->obra_id)->whereKey($fornecedor->id)->first();

        if (! $fresh) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'O fornecedor selecionado não pertence a esta obra ou não está mais disponível.'
            );
        }
    }

    private function garantirAnexoValido(RequisicaoCompra $rc, ?RequisicaoCompraAnexo $anexo): void
    {
        if ($anexo && $anexo->requisicao_compra_id !== $rc->id) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'O documento de suporte selecionado não pertence a esta Requisição de Compra.'
            );
        }
    }

    private function garantirJustificativaPreenchida(string $justificativa): void
    {
        if (trim($justificativa) === '') {
            throw new RequisicaoCompraAdjudicacaoInvalidaException('Informe a justificativa da decisão de adjudicação.');
        }
    }
}
