<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusPedidoCompra;
use App\Exceptions\ParcelaNecessidadeInvalidaException;
use App\Exceptions\PedidoCompraImutavelException;
use App\Exceptions\SaldoRequisicaoCompraInsuficienteException;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItem;
use App\Models\PedidoCompraItemParcela;
use App\Models\RequisicaoCompraItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ciclo 19, Etapa 19.5 — único ponto de escrita pra itens de um Pedido
 * Rascunho (mesmo padrão de `AtualizarRascunhoRequisicaoCompra`).
 *
 * **Ordem de lock — nova extensão do mesmo princípio de 19.4.CORREÇÃO**:
 * `PedidoCompra::lockForUpdate()` (protege o `estaRascunho()` deste
 * Pedido) seguido de `RequisicaoCompraItem::lockForUpdate()` (protege o
 * saldo — o recurso que Pedidos concorrentes disputam, mesmo papel que
 * `AlocacaoRequisicaoPacote` tem na camada RC).
 *
 * **Saldo OFICIAL = só Pedido `Emitido`, nunca Rascunho** (mesma
 * filosofia de 19.4.CORREÇÃO): múltiplos rascunhos de Pedido podem
 * reservar até o saldo oficial CHEIO cada um — só a emissão revalida de
 * verdade.
 */
class AtualizarRascunhoPedidoCompra
{
    public function adicionarItem(PedidoCompra $pedido, RequisicaoCompraItem $rcItem, float $quantidade): PedidoCompraItem
    {
        return DB::transaction(function () use ($pedido, $rcItem, $quantidade) {
            $pedido = PedidoCompra::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $rcItem = RequisicaoCompraItem::whereKey($rcItem->id)->lockForUpdate()->firstOrFail();
            $this->garantirMesmaRc($pedido, $rcItem);
            $this->garantirQuantidadePositiva($quantidade);
            $this->validarSaldo($rcItem, $quantidade, excluirItemId: null);

            return PedidoCompraItem::create([
                'pedido_compra_id' => $pedido->id,
                'requisicao_compra_item_id' => $rcItem->id,
                'quantidade_pedida' => $quantidade,
            ]);
        });
    }

    public function alterarQuantidade(PedidoCompraItem $item, float $novaQuantidade): void
    {
        DB::transaction(function () use ($item, $novaQuantidade) {
            $pedido = PedidoCompra::whereKey($item->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $rcItem = RequisicaoCompraItem::whereKey($item->requisicao_compra_item_id)->lockForUpdate()->firstOrFail();
            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->validarSaldo($rcItem, $novaQuantidade, excluirItemId: $item->id);
            $this->garantirNaoAbaixoDoDetalhadoPorParcela($item, $novaQuantidade);

            $item->update(['quantidade_pedida' => $novaQuantidade]);
        });
    }

    public function removerItem(PedidoCompraItem $item): void
    {
        DB::transaction(function () use ($item) {
            $pedido = PedidoCompra::whereKey($item->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $item->delete();
        });
    }

    private function garantirRascunho(PedidoCompra $pedido): void
    {
        if ($pedido->status !== StatusPedidoCompra::Rascunho) {
            throw new PedidoCompraImutavelException(
                'Este Pedido/Ordem de Compra já foi emitido — não é possível alterar seus itens.'
            );
        }
    }

    private function garantirMesmaRc(PedidoCompra $pedido, RequisicaoCompraItem $rcItem): void
    {
        if ($rcItem->requisicao_compra_id !== $pedido->requisicao_compra_id) {
            throw new InvalidArgumentException('Este item não pertence à Requisição de Compra deste Pedido.');
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new InvalidArgumentException('A quantidade pedida precisa ser maior que zero.');
        }
    }

    /**
     * Rastreabilidade Quantitativa, Etapa 1 — mesmo raciocínio de
     * `AtualizarRascunhoRequisicaoCompra::garantirNaoAbaixoDoDetalhadoPorParcela()`,
     * um nível abaixo: reduzir `quantidade_pedida` não pode quebrar a
     * guarda "soma das parcelas deste PedidoItem <= quantidade_pedida"
     * retroativamente.
     */
    private function garantirNaoAbaixoDoDetalhadoPorParcela(PedidoCompraItem $item, float $novaQuantidade): void
    {
        $jaDetalhado = (float) PedidoCompraItemParcela::where('pedido_compra_item_id', $item->id)->sum('quantidade');

        if ($novaQuantidade < $jaDetalhado - 0.0005) {
            throw new ParcelaNecessidadeInvalidaException(
                "Este item já tem {$jaDetalhado} distribuído por Atividade — reduza a distribuição antes de reduzir a quantidade pedida abaixo desse valor."
            );
        }
    }

    private function validarSaldo(RequisicaoCompraItem $rcItem, float $quantidadeDesejada, ?string $excluirItemId): void
    {
        $saldoDisponivel = $rcItem->saldoOficialParaPedido($excluirItemId);

        if ($quantidadeDesejada > $saldoDisponivel + 0.0005) {
            throw new SaldoRequisicaoCompraInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo oficial ainda disponível ({$saldoDisponivel}) deste item da Requisição de Compra.",
                $saldoDisponivel,
                $quantidadeDesejada
            );
        }
    }
}
