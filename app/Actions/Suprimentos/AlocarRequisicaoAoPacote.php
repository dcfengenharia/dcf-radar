<?php

namespace App\Actions\Suprimentos;

use App\Exceptions\AlocacaoConsumidaPorDestinacaoPlanejadaException;
use App\Exceptions\AlocacaoConsumidaPorRequisicaoCompraException;
use App\Exceptions\AlocacaoRequisicaoInvalidaException;
use App\Exceptions\SaldoRequisicaoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\ItemSuprimento;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoPlanejamentoItem;
use App\Support\Estoque\ConciliacaoDestinacao;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ciclo 19, Etapa 19.3 — único ponto de escrita pra alocação quantitativa
 * de um `RequisicaoPlanejamentoItem` a um `ItemSuprimento` (Pacote de
 * Compra). Mesmo padrão de
 * `App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento`:
 * guard de regra de negócio + lock na própria Action, sem Observer
 * dedicado em `AlocacaoRequisicaoPacote` (único escritor real).
 *
 * **19.3.CORREÇÃO — ordem de lock DETERMINÍSTICA e ÚNICA em todo o
 * projeto**: `RequisicaoPlanejamentoItem::lockForUpdate()` **primeiro**,
 * `ItemSuprimento::lockForUpdate()` **segundo** — sempre nessa ordem, nos
 * 3 métodos (`alocar`/`alterarQuantidade`/`remover`) E no único caller de
 * delete de Pacote (`⚡suprimentos.blade.php::excluirItem()`, que só
 * precisa do segundo lock, já que não mexe em RPItem). O lock em RPItem
 * já existia (protege o saldo — `quantidade_requisitada`, o recurso que
 * duas alocações concorrentes disputam); o lock em `ItemSuprimento` é
 * NOVO nesta correção — serializa contra um `delete()` concorrente do
 * Pacote (achado C da auditoria adversarial: sem esse lock comum, um
 * `AlocacaoRequisicaoPacote` podia ser criado apontando pra um
 * `ItemSuprimento` que acabara de ser soft-deletado). Como
 * `ItemSuprimento` usa `SoftDeletes`, `ItemSuprimento::whereKey($id)
 * ->lockForUpdate()->firstOrFail()` já respeita o scope automático
 * (`deleted_at IS NULL`) — se o Pacote já foi soft-deletado antes desta
 * transação começar, a query simplesmente não o encontra e lança
 * `ModelNotFoundException`, sem precisar de nenhuma checagem manual
 * extra (mesmo mecanismo já comprovado pra `ItemTakeOff` na 19.2.CORREÇÃO).
 *
 * Como `alocacoes_requisicao_pacote` tem `unique(requisicao_planejamento_item_id,
 * item_suprimento_id)`, cada PAR RPItem×Pacote só tem NO MÁXIMO 1 linha —
 * editar substitui o valor antigo, nunca soma uma segunda linha, elimina
 * "contar a própria linha duas vezes" por construção.
 *
 * **Só RP Emitida é alocável (seção 8)** — nunca confia na UI, sempre
 * revalida `$rpItem->requisicao->estaEmitida()` dentro da transação,
 * sobre o registro travado.
 *
 * **Ciclo 19, Etapa 19.4, seções 44-46 — ordem de lock ESTENDIDA**:
 * `RequisicaoPlanejamentoItem → ItemSuprimento → AlocacaoRequisicaoPacote`
 * (a própria linha alterada/removida entra como 3º lock, depois dos 2
 * já existentes). `alterarQuantidade()` nunca pode reduzir abaixo do que
 * já foi consumido por `RequisicaoCompraItem`; `remover()` nunca pode
 * remover uma alocação com qualquer consumo de RC. Livre de corrida
 * porque `App\Actions\Suprimentos\CriarRequisicaoCompra`/
 * `AtualizarRascunhoRequisicaoCompra` (que criam `RequisicaoCompraItem`,
 * consumindo esta alocação) também travam `AlocacaoRequisicaoPacote` na
 * MESMA linha, na MESMA posição da ordem — mesma disciplina "não voltar
 * a criar a mesma corrida" já aplicada 2x neste ciclo (19.2.CORREÇÃO/
 * 19.3.CORREÇÃO).
 *
 * **Ciclo 20, Etapa 20.2 — 3º consumidor da alocação**: além de
 * `RequisicaoCompraItem`, `App\Models\DestinacaoPlanejadaMaterial`
 * (Ciclo 20) também deriva sua demanda formal a partir da SOMA das
 * alocações de um par Pacote+Material. `alterarQuantidade()`/`remover()`
 * ganharam um guard irmão (`garantirDestinacaoNaoInvalidada()`) — reduzir
 * ou remover uma alocação nunca pode deixar a demanda formal do par
 * abaixo do que já está planejado em Destinação. Livre de corrida pela
 * MESMA razão de sempre: `App\Actions\Estoque\AtualizarDestinacaoPlanejada`
 * também trava `ItemSuprimento` (posição 2) antes de ler o total formal.
 */
class AlocarRequisicaoAoPacote
{
    public function alocar(RequisicaoPlanejamentoItem $rpItem, ItemSuprimento $pacote, float $quantidade): AlocacaoRequisicaoPacote
    {
        return DB::transaction(function () use ($rpItem, $pacote, $quantidade) {
            $rpItem = RequisicaoPlanejamentoItem::whereKey($rpItem->id)->lockForUpdate()->firstOrFail();
            $pacote = ItemSuprimento::whereKey($pacote->id)->lockForUpdate()->firstOrFail();

            $this->garantirRpEmitida($rpItem);
            $this->garantirMesmaObra($rpItem, $pacote);
            $this->garantirQuantidadePositiva($quantidade);
            $this->validarSaldo($rpItem, $quantidade, excluirAlocacaoId: null);

            return AlocacaoRequisicaoPacote::create([
                'requisicao_planejamento_item_id' => $rpItem->id,
                'item_suprimento_id' => $pacote->id,
                'quantidade_alocada' => $quantidade,
            ]);
        });
    }

    public function alterarQuantidade(AlocacaoRequisicaoPacote $alocacao, float $novaQuantidade): void
    {
        DB::transaction(function () use ($alocacao, $novaQuantidade) {
            $rpItem = RequisicaoPlanejamentoItem::whereKey($alocacao->requisicao_planejamento_item_id)
                ->lockForUpdate()
                ->firstOrFail();
            ItemSuprimento::whereKey($alocacao->item_suprimento_id)->lockForUpdate()->firstOrFail();
            $alocacao = AlocacaoRequisicaoPacote::whereKey($alocacao->id)->lockForUpdate()->firstOrFail();

            $this->garantirRpEmitida($rpItem);
            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->validarSaldo($rpItem, $novaQuantidade, excluirAlocacaoId: $alocacao->id);
            $this->garantirNaoAbaixoDoConsumidoPorRc($alocacao, $novaQuantidade);
            $this->garantirDestinacaoNaoInvalidada($alocacao, $novaQuantidade);

            $alocacao->update(['quantidade_alocada' => $novaQuantidade]);
        });
    }

    public function remover(AlocacaoRequisicaoPacote $alocacao): void
    {
        DB::transaction(function () use ($alocacao) {
            RequisicaoPlanejamentoItem::whereKey($alocacao->requisicao_planejamento_item_id)
                ->lockForUpdate()
                ->firstOrFail();
            ItemSuprimento::whereKey($alocacao->item_suprimento_id)->lockForUpdate()->firstOrFail();
            $alocacao = AlocacaoRequisicaoPacote::whereKey($alocacao->id)->lockForUpdate()->firstOrFail();

            $this->garantirSemConsumoPorRc($alocacao);
            $this->garantirDestinacaoNaoInvalidada($alocacao, 0.0);

            $alocacao->delete();
        });
    }

    /**
     * Ciclo 19, Etapa 19.4.CORREÇÃO (item 19, reafirmado) — uma alocação
     * nunca pode ser reduzida abaixo do consumo OFICIAL (RC `Emitida`/
     * `Concluida` — nunca Rascunho, que não é compromisso formal).
     * Reduzir abaixo do que RCs em rascunho "pretendem" consumir é
     * PERMITIDO de propósito (item 18 do pedido de correção): os drafts
     * ficam stale e são revalidados de verdade na emissão
     * (`EmitirRequisicaoCompra`), nunca travados preventivamente aqui.
     * Soma lida via `AlocacaoRequisicaoPacote::quantidadeConsumidaOficialPorRc()`
     * (fonte única) DEPOIS do lock nesta linha — livre de corrida contra
     * `AtualizarRascunhoRequisicaoCompra`/`EmitirRequisicaoCompra`, que
     * travam a MESMA linha antes de criar/emitir um `RequisicaoCompraItem`.
     */
    private function garantirNaoAbaixoDoConsumidoPorRc(AlocacaoRequisicaoPacote $alocacao, float $novaQuantidade): void
    {
        $consumidoOficial = $alocacao->quantidadeConsumidaOficialPorRc();

        if ($novaQuantidade < $consumidoOficial - 0.0005) {
            throw new AlocacaoConsumidaPorRequisicaoCompraException(
                "Esta alocação já tem {$consumidoOficial} consumido oficialmente (RC Emitida/Concluída) — não é possível reduzir abaixo desse valor."
            );
        }
    }

    /**
     * Ciclo 19, Etapa 19.4.CORREÇÃO, item 18 — DIFERENTE do guard de
     * `alterarQuantidade()`: remover é um DELETE físico da linha, e
     * `requisicao_compra_itens.alocacao_requisicao_pacote_id` é
     * `restrictOnDelete()` (evidência histórica, mesmo padrão de toda
     * FK "nunca cascade" do projeto) — o MySQL bloqueia esse DELETE
     * sempre que QUALQUER `RequisicaoCompraItem` ainda apontar pra cá,
     * rascunho ou não. Isso não é uma regra de saldo/negócio (drafts
     * continuam não sendo "consumo oficial"), é a própria FK garantindo
     * que nenhuma linha — nem rascunho — fique com referência quebrada.
     * `alterarQuantidade()` nunca tem esse problema (é um UPDATE, a
     * linha continua existindo) — só `remover()` precisa desta checagem
     * mais ampla.
     */
    private function garantirSemConsumoPorRc(AlocacaoRequisicaoPacote $alocacao): void
    {
        if (RequisicaoCompraItem::where('alocacao_requisicao_pacote_id', $alocacao->id)->exists()) {
            throw new AlocacaoConsumidaPorRequisicaoCompraException(
                'Esta alocação já possui item(ns) de Requisição de Compra (rascunho ou formal) e não pode ser removida. Remova ou realoque esses itens primeiro.'
            );
        }
    }

    /**
     * Ciclo 20, Etapa 20.2 — a mesma alocação também alimenta a demanda
     * formal lida por App\Support\Estoque\ConciliacaoDestinacao (SUM por
     * par Pacote+Material). `$quantidadeAposOperacao` é a nova
     * contribuição desta linha depois da operação (a própria
     * `$novaQuantidade` em `alterarQuantidade()`, ou `0.0` em
     * `remover()`, que a apaga por completo). Sem Material associado ao
     * ItemTakeOff desta alocação, nenhuma Destinação pode existir pra
     * este par — guard vira no-op.
     */
    private function garantirDestinacaoNaoInvalidada(AlocacaoRequisicaoPacote $alocacao, float $quantidadeAposOperacao): void
    {
        $alocacao->loadMissing('requisicaoItem.itemTakeOff');
        $materialId = $alocacao->requisicaoItem?->itemTakeOff?->material_id;

        if (! $materialId) {
            return;
        }

        $formalAtual = ConciliacaoDestinacao::quantidadeFormal($alocacao->item_suprimento_id, $materialId);
        $novoFormal = round($formalAtual - (float) $alocacao->quantidade_alocada + $quantidadeAposOperacao, 3);
        $destinado = ConciliacaoDestinacao::quantidadeDestinada($alocacao->item_suprimento_id, $materialId);

        if ($novoFormal < $destinado - 0.0005) {
            throw new AlocacaoConsumidaPorDestinacaoPlanejadaException(
                "Esta alteração reduziria a demanda formal deste Material neste Pacote para {$novoFormal}, "
                . "abaixo do que já está planejado em Destinação(ões) ({$destinado}). "
                . 'Ajuste ou remova a(s) Destinação(ões) Planejada(s) primeiro.'
            );
        }
    }

    private function garantirRpEmitida(RequisicaoPlanejamentoItem $rpItem): void
    {
        $rpItem->loadMissing('requisicao');

        if (! $rpItem->requisicao || ! $rpItem->requisicao->estaEmitida()) {
            throw new AlocacaoRequisicaoInvalidaException(
                'Só é possível alocar itens de uma Requisição do Planejamento já Emitida.'
            );
        }
    }

    private function garantirMesmaObra(RequisicaoPlanejamentoItem $rpItem, ItemSuprimento $pacote): void
    {
        $rpItem->loadMissing('requisicao');

        if ($pacote->obra_id !== $rpItem->requisicao?->obra_id) {
            throw new InvalidArgumentException('Este Pacote de Compra não pertence à mesma obra da Requisição do Planejamento.');
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new InvalidArgumentException('A quantidade alocada precisa ser maior que zero.');
        }
    }

    /**
     * Saldo = quantidade_requisitada do RPItem menos a soma já alocada
     * (excluindo a própria linha quando estamos editando-a).
     */
    private function validarSaldo(RequisicaoPlanejamentoItem $rpItem, float $quantidadeDesejada, ?string $excluirAlocacaoId): void
    {
        $query = AlocacaoRequisicaoPacote::query()
            ->where('requisicao_planejamento_item_id', $rpItem->id);

        if ($excluirAlocacaoId) {
            $query->where('id', '!=', $excluirAlocacaoId);
        }

        $alocado = (float) $query->sum('quantidade_alocada');
        $saldoDisponivel = round((float) $rpItem->quantidade_requisitada - $alocado, 3);

        if ($quantidadeDesejada > $saldoDisponivel + 0.0005) {
            throw new SaldoRequisicaoInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda não alocado ({$saldoDisponivel}) deste item da Requisição.",
                $saldoDisponivel,
                $quantidadeDesejada
            );
        }
    }
}
