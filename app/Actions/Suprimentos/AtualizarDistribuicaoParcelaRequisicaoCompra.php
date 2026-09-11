<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoCompra;
use App\Exceptions\ParcelaNecessidadeInvalidaException;
use App\Exceptions\RequisicaoCompraImutavelException;
use App\Exceptions\SaldoParcelaNecessidadeInsuficienteException;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoCompraItemParcela;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Rastreabilidade Quantitativa, Etapa 1 — único ponto de escrita de
 * `RequisicaoCompraItemParcela` (mesmo padrão de
 * `AtualizarRascunhoRequisicaoCompra`: guard de status na própria
 * Action, sem Observer dedicado).
 *
 * **Ordem de lock**: `RequisicaoCompraItem` → `RequisicaoCompra` (mesma
 * ordem já usada por `AtualizarRascunhoRequisicaoCompra`) → só então
 * `AtividadeNecessidadeMaterial` (recurso NOVO, nunca antes disputado
 * simultaneamente com RC/RCItem — travá-lo por ÚLTIMO, sempre depois de
 * RC/RCItem, evita qualquer inversão contra o total-order já
 * estabelecido no Ciclo 19: `RequisicaoPlanejamentoItem → ItemSuprimento
 * → AlocacaoRequisicaoPacote`, que nunca precisa tocar
 * `AtividadeNecessidadeMaterial`).
 *
 * **Duas guardas independentes** (Revisão Arquitetural 2, Seção 4):
 * (A) soma das parcelas de UMA RCItem nunca excede `RCItem.quantidade`
 * — sempre verificada, RC Rascunho ou não, é consistência interna do
 * próprio item; (B) soma de UMA parcela em RCs comercialmente válidas
 * (`Emitida`/`Concluida`) nunca excede
 * `AtividadeNecessidadeMaterial.quantidade_necessaria` — só conta RC já
 * emitida, mesma filosofia de `AlocacaoRequisicaoPacote::
 * quantidadeConsumidaOficialPorRc()` (múltiplos rascunhos concorrentes
 * podem, cada um, detalhar até o saldo cheio; só a emissão
 * — `EmitirRequisicaoCompra` — revalida de verdade e serializa).
 *
 * **Sem guarda de "já consumido por Pedido"**: diferente de
 * `AlocacaoRequisicaoPacote` (cujo saldo pode ser reduzido mesmo depois
 * de já existir RC referenciando-a), uma `RequisicaoCompraItemParcela`
 * só é editável enquanto sua RC é Rascunho — e um Pedido só pode
 * existir a partir de uma RC já Emitida (`CriarPedidoCompra`). Portanto,
 * no instante em que esta Action permite editar/remover uma parcela, é
 * estruturalmente impossível que algum Pedido já a tenha consumido —
 * mesmo raciocínio já usado (e não duplicado ali) em
 * `AtualizarRascunhoRequisicaoCompra::removerItem()`.
 */
class AtualizarDistribuicaoParcelaRequisicaoCompra
{
    public function adicionarParcela(RequisicaoCompraItem $item, AtividadeNecessidadeMaterial $necessidade, float $quantidade, User $usuario): RequisicaoCompraItemParcela
    {
        return DB::transaction(function () use ($item, $necessidade, $quantidade, $usuario) {
            $item = RequisicaoCompraItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $rc = RequisicaoCompra::whereKey($item->requisicao_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rc);

            $necessidade = AtividadeNecessidadeMaterial::whereKey($necessidade->id)->lockForUpdate()->firstOrFail();
            $this->garantirMesmaObra($rc, $necessidade);
            $this->garantirMaterialCompativel($item, $necessidade);
            $this->garantirQuantidadePositiva($quantidade);
            $this->validarSaldoItem($item, $quantidade, excluirParcelaId: null);
            $this->validarSaldoNecessidade($necessidade, $quantidade, excluirParcelaId: null);

            try {
                return RequisicaoCompraItemParcela::create([
                    'requisicao_compra_item_id' => $item->id,
                    'atividade_necessidade_material_id' => $necessidade->id,
                    'quantidade' => $quantidade,
                    'created_by_id' => $usuario->id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ParcelaNecessidadeInvalidaException(
                        'Esta necessidade já está detalhada neste item da Requisição de Compra — edite a quantidade existente em vez de adicionar de novo.'
                    );
                }

                throw $e;
            }
        });
    }

    public function alterarQuantidade(RequisicaoCompraItemParcela $parcela, float $novaQuantidade): void
    {
        DB::transaction(function () use ($parcela, $novaQuantidade) {
            $item = RequisicaoCompraItem::whereKey($parcela->requisicao_compra_item_id)->lockForUpdate()->firstOrFail();
            $rc = RequisicaoCompra::whereKey($item->requisicao_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rc);

            $necessidade = AtividadeNecessidadeMaterial::whereKey($parcela->atividade_necessidade_material_id)->lockForUpdate()->firstOrFail();
            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->validarSaldoItem($item, $novaQuantidade, excluirParcelaId: $parcela->id);
            $this->validarSaldoNecessidade($necessidade, $novaQuantidade, excluirParcelaId: $parcela->id);

            $parcela->update(['quantidade' => $novaQuantidade]);
        });
    }

    public function removerParcela(RequisicaoCompraItemParcela $parcela): void
    {
        DB::transaction(function () use ($parcela) {
            $item = RequisicaoCompraItem::whereKey($parcela->requisicao_compra_item_id)->lockForUpdate()->firstOrFail();
            $rc = RequisicaoCompra::whereKey($item->requisicao_compra_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rc);

            $parcela->delete();
        });
    }

    private function garantirRascunho(RequisicaoCompra $rc): void
    {
        if ($rc->status !== StatusRequisicaoCompra::Rascunho) {
            throw new RequisicaoCompraImutavelException(
                'Esta Requisição de Compra já foi emitida — não é possível alterar a distribuição por Atividade de seus itens.'
            );
        }
    }

    /**
     * Nunca confia no ID vindo da UI (Seção 8 do pedido): a necessidade
     * precisa pertencer à MESMA obra da RC — comparação estrutural,
     * nunca inferida por permissão do usuário.
     */
    private function garantirMesmaObra(RequisicaoCompra $rc, AtividadeNecessidadeMaterial $necessidade): void
    {
        if ($rc->obra_id !== $necessidade->obra_id) {
            throw new ParcelaNecessidadeInvalidaException('Esta necessidade não pertence à mesma obra desta Requisição de Compra.');
        }
    }

    /**
     * "Correspondência inequívoca" (Seção 8/9 do pedido) — nunca ligar
     * parcela de Material A a item de Material B. Os dois lados
     * precisam resolver pro MESMO Material, com identidade conhecida (o
     * item de TakeOff por trás desta RCItem precisa ter `material_id`
     * preenchido — sem isso, a correspondência é indeterminável e a
     * distribuição é recusada, nunca assumida).
     */
    private function garantirMaterialCompativel(RequisicaoCompraItem $item, AtividadeNecessidadeMaterial $necessidade): void
    {
        $item->loadMissing('alocacao.requisicaoItem.itemTakeOff');
        $materialItem = $item->alocacao?->requisicaoItem?->itemTakeOff?->material_id;
        $materialNecessidade = $necessidade->material()?->id;

        if ($materialItem === null || $materialNecessidade === null || $materialItem !== $materialNecessidade) {
            throw new ParcelaNecessidadeInvalidaException(
                'Não foi possível confirmar que esta necessidade corresponde ao mesmo Material deste item da Requisição de Compra — associe o Material ao item de TakeOff (ou revise a necessidade) antes de detalhar por Atividade.'
            );
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new ParcelaNecessidadeInvalidaException('A quantidade distribuída precisa ser maior que zero.');
        }
    }

    /** Guarda A — soma das parcelas desta RCItem nunca excede a quantidade do item. Sempre verificada. */
    private function validarSaldoItem(RequisicaoCompraItem $item, float $quantidadeDesejada, ?string $excluirParcelaId): void
    {
        $query = RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $item->id);
        if ($excluirParcelaId) {
            $query->where('id', '!=', $excluirParcelaId);
        }
        $jaDetalhado = (float) $query->sum('quantidade');
        $saldo = round((float) $item->quantidade - $jaDetalhado, 3);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            throw new SaldoParcelaNecessidadeInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda não detalhado ({$saldo}) deste item da Requisição de Compra.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }

    /** Guarda B — soma desta parcela em RCs comercialmente válidas nunca excede a necessidade total. */
    private function validarSaldoNecessidade(AtividadeNecessidadeMaterial $necessidade, float $quantidadeDesejada, ?string $excluirParcelaId): void
    {
        $saldo = $necessidade->saldoOficialParaDetalheRc($excluirParcelaId);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            throw new SaldoParcelaNecessidadeInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda não detalhado ({$saldo}) desta necessidade — outra Requisição de Compra já emitida já consumiu parte dela.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }
}
