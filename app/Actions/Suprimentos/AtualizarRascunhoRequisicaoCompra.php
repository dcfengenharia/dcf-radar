<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoCompra;
use App\Exceptions\ParcelaNecessidadeInvalidaException;
use App\Exceptions\RequisicaoCompraImutavelException;
use App\Exceptions\SaldoAlocacaoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoCompraItemParcela;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ciclo 19, Etapa 19.4 — único ponto de escrita pra itens de uma RC
 * Rascunho (mesmo padrão de `AtualizarRascunhoRequisicaoPlanejamento`/
 * `AtualizarRascunhoGrd`: guard de status na própria Action, sem
 * Observer dedicado em `RequisicaoCompraItem`).
 *
 * **Ordem de lock**: `RequisicaoCompra::lockForUpdate()` (protege o
 * `estaRascunho()` desta RC) seguido de
 * `AlocacaoRequisicaoPacote::lockForUpdate()` (protege o saldo — o
 * recurso que RCs concorrentes disputam, e que `AlocarRequisicaoAoPacote::
 * alterarQuantidade()/remover()` também trava, na mesma posição da
 * ordem estendida documentada lá: RequisicaoPlanejamentoItem →
 * ItemSuprimento → AlocacaoRequisicaoPacote).
 *
 * Como `requisicao_compra_itens` tem
 * `UNIQUE(requisicao_compra_id, alocacao_requisicao_pacote_id)`, cada RC
 * só pode ter NO MÁXIMO 1 linha por alocação — editar SUBSTITUI o valor,
 * nunca soma uma segunda linha.
 */
class AtualizarRascunhoRequisicaoCompra
{
    public function adicionarItem(RequisicaoCompra $rc, AlocacaoRequisicaoPacote $alocacao, float $quantidade): RequisicaoCompraItem
    {
        return DB::transaction(function () use ($rc, $alocacao, $quantidade) {
            $rc = RequisicaoCompra::whereKey($rc->id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rc);

            $alocacao = AlocacaoRequisicaoPacote::whereKey($alocacao->id)->lockForUpdate()->firstOrFail();
            $this->garantirMesmoPacote($rc, $alocacao);
            $this->garantirQuantidadePositiva($quantidade);
            $this->validarSaldo($alocacao, $quantidade, excluirItemId: null);

            return RequisicaoCompraItem::create([
                'requisicao_compra_id' => $rc->id,
                'alocacao_requisicao_pacote_id' => $alocacao->id,
                'quantidade' => $quantidade,
            ]);
        });
    }

    public function alterarQuantidade(RequisicaoCompraItem $item, float $novaQuantidade): void
    {
        DB::transaction(function () use ($item, $novaQuantidade) {
            $rc = RequisicaoCompra::whereKey($item->requisicao_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rc);

            $alocacao = AlocacaoRequisicaoPacote::whereKey($item->alocacao_requisicao_pacote_id)->lockForUpdate()->firstOrFail();
            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->validarSaldo($alocacao, $novaQuantidade, excluirItemId: $item->id);
            $this->garantirNaoAbaixoDoDetalhadoPorParcela($item, $novaQuantidade);

            $item->update(['quantidade' => $novaQuantidade]);
        });
    }

    public function removerItem(RequisicaoCompraItem $item): void
    {
        DB::transaction(function () use ($item) {
            $rc = RequisicaoCompra::whereKey($item->requisicao_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rc);

            $item->delete();
        });
    }

    private function garantirRascunho(RequisicaoCompra $rc): void
    {
        if ($rc->status !== StatusRequisicaoCompra::Rascunho) {
            throw new RequisicaoCompraImutavelException(
                'Esta Requisição de Compra já foi emitida — não é possível alterar seus itens.'
            );
        }
    }

    private function garantirMesmoPacote(RequisicaoCompra $rc, AlocacaoRequisicaoPacote $alocacao): void
    {
        if ($alocacao->item_suprimento_id !== $rc->item_suprimento_id) {
            throw new InvalidArgumentException('Esta alocação não pertence ao Pacote desta Requisição de Compra.');
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new InvalidArgumentException('A quantidade precisa ser maior que zero.');
        }
    }

    /**
     * Rastreabilidade Quantitativa, Etapa 1 — a Guarda A de
     * `AtualizarDistribuicaoParcelaRequisicaoCompra` (soma das parcelas
     * desta RCItem <= RCItem.quantidade) só é verificada no momento de
     * ADICIONAR/ALTERAR uma parcela — reduzir a própria `quantidade` do
     * item DEPOIS de já detalhado por Atividade poderia quebrar essa
     * invariante retroativamente sem este guard.
     */
    private function garantirNaoAbaixoDoDetalhadoPorParcela(RequisicaoCompraItem $item, float $novaQuantidade): void
    {
        $jaDetalhado = (float) RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $item->id)->sum('quantidade');

        if ($novaQuantidade < $jaDetalhado - 0.0005) {
            throw new ParcelaNecessidadeInvalidaException(
                "Este item já tem {$jaDetalhado} distribuído por Atividade — reduza a distribuição antes de reduzir a quantidade abaixo desse valor."
            );
        }
    }

    /**
     * Ciclo 19, Etapa 19.4.CORREÇÃO — saldo OFICIAL = quantidade_alocada
     * menos a soma consumida por RC `Emitida`/`Concluida` (nunca
     * Rascunho — mesma filosofia de `RequisicaoPlanejamentoItem`,
     * "rascunho nunca consome saldo oficial"). Como esta própria RC
     * ainda é Rascunho no momento em que este método roda (guard
     * `garantirRascunho()` já garantiu isso antes de chegar aqui), ela
     * NUNCA aparece na soma calculada por `quantidadeConsumidaOficialPorRc()`
     * — não precisa excluir a própria linha por status, só por id (edição).
     * Consequência deliberada: múltiplos rascunhos concorrentes podem
     * cada um reservar até o saldo oficial cheio — só a EMISSÃO
     * (`EmitirRequisicaoCompra`) revalida de verdade e serializa.
     */
    private function validarSaldo(AlocacaoRequisicaoPacote $alocacao, float $quantidadeDesejada, ?string $excluirItemId): void
    {
        $saldoDisponivel = $alocacao->saldoOficialParaRc($excluirItemId);

        if ($quantidadeDesejada > $saldoDisponivel + 0.0005) {
            throw new SaldoAlocacaoInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo oficial ainda disponível ({$saldoDisponivel}) desta alocação.",
                $saldoDisponivel,
                $quantidadeDesejada
            );
        }
    }
}
