<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusPedidoCompra;
use App\Exceptions\ParcelaNecessidadeInvalidaException;
use App\Exceptions\PedidoCompraImutavelException;
use App\Exceptions\SaldoParcelaPedidoInsuficienteException;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItem;
use App\Models\PedidoCompraItemParcela;
use App\Models\RequisicaoCompraItemParcela;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Rastreabilidade Quantitativa, Etapa 1 — único ponto de escrita de
 * `PedidoCompraItemParcela` (mesmo padrão de
 * `AtualizarRascunhoPedidoCompra`).
 *
 * **NUNCA proporcional/inferida** (Revisão Arquitetural 2, Seção 2,
 * decisão fechada explicitamente contra a proposta anterior de
 * `breakdownPorAtividade()` calculado por proporção): toda distribuição
 * aqui é uma escolha EXPLÍCITA do usuário, sempre validada contra a
 * "quota" real que a `RequisicaoCompraItemParcela` de origem oferece —
 * um Pedido nunca pode consumir uma necessidade que a própria RC não
 * detalhou pra ele (Seção 6/O do pedido).
 *
 * **Ordem de lock**: `PedidoCompraItem` → `PedidoCompra` (mesma ordem
 * de `AtualizarRascunhoPedidoCompra`) → `RequisicaoCompraItemParcela`
 * (a "quota" de origem, recurso que Pedidos concorrentes disputam) —
 * nunca trava `AtividadeNecessidadeMaterial` diretamente aqui (a
 * necessidade já foi validada/travada no momento em que a RC detalhou
 * a parcela; o Pedido só disputa a QUOTA já concedida, nunca a
 * necessidade bruta de novo).
 *
 * **Sem guarda de "já consumido depois"**: mesmo raciocínio de
 * `AtualizarDistribuicaoParcelaRequisicaoCompra` — nada neste ciclo
 * referencia `PedidoCompraItemParcela` depois de criada, então editar/
 * remover enquanto o Pedido é Rascunho nunca corre risco de deixar um
 * consumidor posterior órfão.
 */
class AtualizarDistribuicaoParcelaPedidoCompra
{
    public function adicionarParcela(PedidoCompraItem $item, AtividadeNecessidadeMaterial $necessidade, float $quantidade, User $usuario): PedidoCompraItemParcela
    {
        return DB::transaction(function () use ($item, $necessidade, $quantidade, $usuario) {
            $item = PedidoCompraItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $pedido = PedidoCompra::whereKey($item->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $rcParcela = RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $item->requisicao_compra_item_id)
                ->where('atividade_necessidade_material_id', $necessidade->id)
                ->lockForUpdate()
                ->first();

            $this->garantirParcelaExisteNaRc($rcParcela);
            $this->garantirQuantidadePositiva($quantidade);
            $this->validarSaldoItem($item, $quantidade, excluirParcelaId: null);
            $this->validarSaldoRc($rcParcela, $quantidade, excluirParcelaId: null);

            try {
                return PedidoCompraItemParcela::create([
                    'pedido_compra_item_id' => $item->id,
                    'atividade_necessidade_material_id' => $necessidade->id,
                    'quantidade' => $quantidade,
                    'created_by_id' => $usuario->id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ParcelaNecessidadeInvalidaException(
                        'Esta necessidade já está detalhada neste item do Pedido — edite a quantidade existente em vez de adicionar de novo.'
                    );
                }

                throw $e;
            }
        });
    }

    public function alterarQuantidade(PedidoCompraItemParcela $parcela, float $novaQuantidade): void
    {
        DB::transaction(function () use ($parcela, $novaQuantidade) {
            $item = PedidoCompraItem::whereKey($parcela->pedido_compra_item_id)->lockForUpdate()->firstOrFail();
            $pedido = PedidoCompra::whereKey($item->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $rcParcela = RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $item->requisicao_compra_item_id)
                ->where('atividade_necessidade_material_id', $parcela->atividade_necessidade_material_id)
                ->lockForUpdate()
                ->first();

            $this->garantirParcelaExisteNaRc($rcParcela);
            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->validarSaldoItem($item, $novaQuantidade, excluirParcelaId: $parcela->id);
            $this->validarSaldoRc($rcParcela, $novaQuantidade, excluirParcelaId: $parcela->id);

            $parcela->update(['quantidade' => $novaQuantidade]);
        });
    }

    public function removerParcela(PedidoCompraItemParcela $parcela): void
    {
        DB::transaction(function () use ($parcela) {
            $item = PedidoCompraItem::whereKey($parcela->pedido_compra_item_id)->lockForUpdate()->firstOrFail();
            $pedido = PedidoCompra::whereKey($item->pedido_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($pedido);

            $parcela->delete();
        });
    }

    private function garantirRascunho(PedidoCompra $pedido): void
    {
        if ($pedido->status !== StatusPedidoCompra::Rascunho) {
            throw new PedidoCompraImutavelException(
                'Este Pedido/Ordem de Compra já foi emitido — não é possível alterar a distribuição por Atividade de seus itens.'
            );
        }
    }

    /** Seção O do pedido: "Pedido não pode consumir parcela não existente na RCItem mãe." */
    private function garantirParcelaExisteNaRc(?RequisicaoCompraItemParcela $rcParcela): void
    {
        if (! $rcParcela) {
            throw new ParcelaNecessidadeInvalidaException(
                'Esta necessidade não foi detalhada na Requisição de Compra de origem deste item — o Pedido só pode distribuir o que a própria RC já ofereceu.'
            );
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new ParcelaNecessidadeInvalidaException('A quantidade distribuída precisa ser maior que zero.');
        }
    }

    /** Guarda — soma das parcelas deste PedidoItem nunca excede sua própria quantidade_pedida. */
    private function validarSaldoItem(PedidoCompraItem $item, float $quantidadeDesejada, ?string $excluirParcelaId): void
    {
        $query = PedidoCompraItemParcela::where('pedido_compra_item_id', $item->id);
        if ($excluirParcelaId) {
            $query->where('id', '!=', $excluirParcelaId);
        }
        $jaDetalhado = (float) $query->sum('quantidade');
        $saldo = round((float) $item->quantidade_pedida - $jaDetalhado, 3);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            throw new SaldoParcelaPedidoInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda não detalhado ({$saldo}) deste item do Pedido.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }

    /**
     * Guarda — nunca consumir mais do que a quota que a RC de origem
     * ofereceu (exemplo obrigatório do pedido: RCItem=300A+300B; nunca
     * permitir 400A mesmo com PedidoItem.quantidade_pedida=400).
     */
    private function validarSaldoRc(RequisicaoCompraItemParcela $rcParcela, float $quantidadeDesejada, ?string $excluirParcelaId): void
    {
        $saldo = $rcParcela->saldoOficialParaPedido($excluirParcelaId);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            throw new SaldoParcelaPedidoInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda disponível ({$saldo}) desta parcela na Requisição de Compra de origem — outro Pedido já emitido consumiu parte dela.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }
}
