<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoPlanejamento;
use App\Exceptions\RequisicaoPlanejamentoImutavelException;
use App\Exceptions\SaldoTakeOffInsuficienteException;
use App\Models\ItemTakeOff;
use App\Models\RequisicaoPlanejamento;
use App\Models\RequisicaoPlanejamentoItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ciclo 19, Etapa 19.2 — único ponto de escrita pra itens de uma RP
 * Rascunho (mesmo padrão de App\Actions\Engenharia\AtualizarRascunhoGrd:
 * guard de status na própria Action, sem Observer dedicado em
 * RequisicaoPlanejamentoItem — só a RP existe em mais de um "modo", os
 * itens nascem/morrem inteiramente dentro do ciclo de vida dela).
 *
 * **Concorrência no saldo (seção 10 do pedido)**: cada método faz
 * `ItemTakeOff::lockForUpdate()` — não `Work` inteiro — dentro de
 * `DB::transaction()`, ANTES de somar o requisitado e validar. Lock
 * granular por item (não pela obra inteira) permite edições concorrentes
 * em ITENS DIFERENTES prosseguirem em paralelo, serializando só quando
 * duas transações disputam o MESMO item.
 *
 * Rascunho NUNCA consome saldo oficial (seção 11) — só RPs `Emitida`
 * entram na soma de `validarSaldo()`. Como `requisicao_planejamento_itens`
 * tem `unique(requisicao_planejamento_id, item_take_off_id)`, cada RP só
 * pode ter NO MÁXIMO 1 linha por item — editar essa linha SUBSTITUI o
 * valor antigo (`update()`), nunca soma um segundo valor por cima; isso
 * elimina por construção o risco de "contar a própria linha duas vezes"
 * (seção 21) — a soma de saldo emitido nunca inclui rascunhos de
 * nenhuma RP (nem desta, nem de outras), então não há nada da própria
 * linha pra subtrair.
 */
class AtualizarRascunhoRequisicaoPlanejamento
{
    public function adicionarItem(RequisicaoPlanejamento $rp, string $itemTakeOffId, float $quantidade): RequisicaoPlanejamentoItem
    {
        return DB::transaction(function () use ($rp, $itemTakeOffId, $quantidade) {
            $rp = RequisicaoPlanejamento::whereKey($rp->id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rp);

            $itemTakeOff = ItemTakeOff::whereKey($itemTakeOffId)->lockForUpdate()->firstOrFail();
            $this->garantirMesmaObra($rp, $itemTakeOff);
            $this->garantirQuantidadePositiva($quantidade);
            $this->validarSaldo($itemTakeOff, $quantidade, excluirRequisicaoId: null);

            return RequisicaoPlanejamentoItem::create([
                'requisicao_planejamento_id' => $rp->id,
                'item_take_off_id' => $itemTakeOff->id,
                'quantidade_requisitada' => $quantidade,
            ]);
        });
    }

    public function alterarQuantidade(RequisicaoPlanejamentoItem $item, float $novaQuantidade): void
    {
        DB::transaction(function () use ($item, $novaQuantidade) {
            $rp = RequisicaoPlanejamento::whereKey($item->requisicao_planejamento_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rp);

            $itemTakeOff = ItemTakeOff::whereKey($item->item_take_off_id)->lockForUpdate()->firstOrFail();
            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->validarSaldo($itemTakeOff, $novaQuantidade, excluirRequisicaoId: null);

            $item->update(['quantidade_requisitada' => $novaQuantidade]);
        });
    }

    public function removerItem(RequisicaoPlanejamentoItem $item): void
    {
        DB::transaction(function () use ($item) {
            $rp = RequisicaoPlanejamento::whereKey($item->requisicao_planejamento_id)->lockForUpdate()->firstOrFail();
            $this->garantirRascunho($rp);

            $item->delete();
        });
    }

    private function garantirRascunho(RequisicaoPlanejamento $rp): void
    {
        if (! $rp->estaRascunho()) {
            throw new RequisicaoPlanejamentoImutavelException(
                'Esta Requisição do Planejamento já foi emitida — não é possível alterar seus itens.'
            );
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new InvalidArgumentException('A quantidade requisitada precisa ser maior que zero.');
        }
    }

    private function garantirMesmaObra(RequisicaoPlanejamento $rp, ItemTakeOff $itemTakeOff): void
    {
        $itemTakeOff->loadMissing('lista.revisao.documento');
        $obraId = $itemTakeOff->lista?->revisao?->documento?->obra_id;

        if ($obraId !== $rp->obra_id) {
            throw new InvalidArgumentException('Este item do Take Off não pertence à mesma obra da Requisição do Planejamento.');
        }
    }

    /**
     * Rascunho nunca consome saldo oficial — só soma o que já está
     * `Emitida` em OUTRAS RPs (nunca a própria RP em edição, nunca
     * rascunhos de terceiros).
     */
    private function validarSaldo(ItemTakeOff $itemTakeOff, float $quantidadeDesejada, ?string $excluirRequisicaoId): void
    {
        $query = RequisicaoPlanejamentoItem::query()
            ->where('item_take_off_id', $itemTakeOff->id)
            ->whereHas('requisicao', fn ($q) => $q->where('status', StatusRequisicaoPlanejamento::Emitida->value));

        if ($excluirRequisicaoId) {
            $query->where('requisicao_planejamento_id', '!=', $excluirRequisicaoId);
        }

        $requisitadaEmitida = (float) $query->sum('quantidade_requisitada');
        $saldoDisponivel = round((float) $itemTakeOff->quantidade - $requisitadaEmitida, 3);

        if ($quantidadeDesejada > $saldoDisponivel + 0.0005) {
            throw new SaldoTakeOffInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo disponível ({$saldoDisponivel}) do item \"{$itemTakeOff->descricao}\".",
                $saldoDisponivel,
                $quantidadeDesejada
            );
        }
    }
}
