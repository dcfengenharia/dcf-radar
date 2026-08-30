<?php

namespace App\Actions\Suprimentos;

use App\Exceptions\RecebimentoPedidoInvalidoException;
use App\Exceptions\SaldoPedidoInsuficienteException;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\User;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 19, Etapa 19.6 — registra UM evento append-only de recebimento
 * físico sobre um `PedidoCompraItem`. Mesmo padrão exato de
 * `App\Actions\Engenharia\RegistrarRecolhimento` (Ciclo 18, 18.5.1): nunca
 * edita nem apaga um evento anterior — `RecebimentoPedido` é append-only
 * por contrato de domínio (só este método cria linhas em
 * `recebimentos_pedido`, `App\Observers\RecebimentoPedidoObserver` bloqueia
 * qualquer update/delete de instância incondicionalmente — 19.6.CORREÇÃO).
 *
 * **NUNCA sobrescreve `PedidoCompraItem.quantidade_pedida`** — o
 * compromisso comercial original é imutável desde a emissão do Pedido
 * (19.5); recebimento é sempre um fato separado.
 *
 * **Concorrência**: `PedidoCompraItem::whereKey(...)->lockForUpdate()` é
 * adquirido ANTES de qualquer cálculo de `quantidadeRecebida()`, mesmo
 * mecanismo já usado em toda a cadeia de saldo do Ciclo 19
 * (`AlocacaoRequisicaoPacote`/`RequisicaoCompraItem`) e em
 * `RegistrarRecolhimento` — duas tentativas concorrentes sobre o MESMO
 * item disputam o lock da mesma linha; a segunda só prossegue depois que
 * a primeira commita, e nesse ponto já vê a soma atualizada.
 *
 * **Só Pedido `Emitido` pode receber** (seção 26 do pedido) — Rascunho
 * nunca chega perto de ter saldo/necessidade de recebimento; revalidado
 * aqui, nunca confiado só à UI.
 *
 * **Data retroativa é permitida, data futura é BLOQUEADA (Ciclo 19,
 * Etapa 19.6.CORREÇÃO — decisão de produto formalizada)**: `RecebimentoPedido`
 * representa um FATO já ocorrido, nunca uma programação — `PedidoCompra
 * .data_prevista_entrega` já é o campo de programação/previsão, `recebido_em`
 * não pode duplicar esse papel. `recebido_em > hoje` lança
 * `RecebimentoPedidoInvalidoException` antes de qualquer escrita; `recebido_em
 * <= hoje` (incluindo hoje) sempre permitido, sem limite de quão
 * retroativo (lançamento de NF atrasada, entrada manual/offline). A
 * cronologia física de `recebido_em` é o critério PRIMÁRIO usado por
 * `App\Models\PedidoCompraItem::dataConclusaoRecebimento()` desde a
 * correção do achado C2 — nunca `created_at`/`id` (esses seguem sendo só
 * desempate entre eventos do mesmo dia).
 */
class RegistrarRecebimentoPedido
{
    public function execute(
        PedidoCompraItem $item,
        float $quantidade,
        \DateTimeInterface $recebidoEm,
        User $usuario,
        ?string $observacao = null,
        ?string $localRecebimento = null,
    ): RecebimentoPedido {
        return DB::transaction(function () use ($item, $quantidade, $recebidoEm, $usuario, $observacao, $localRecebimento) {
            if ($quantidade <= 0) {
                throw new RecebimentoPedidoInvalidoException('A quantidade recebida precisa ser maior que zero.');
            }

            // Normaliza pra date (recebido_em é sempre um FATO diário, sem
            // hora) antes de comparar — nunca comparação de string frágil.
            $dataRecebimento = Carbon::parse($recebidoEm)->startOfDay();
            if ($dataRecebimento->gt(Carbon::today())) {
                throw new RecebimentoPedidoInvalidoException('A data do recebimento não pode estar no futuro.');
            }

            $itemTravado = PedidoCompraItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $pedido = PedidoCompra::whereKey($itemTravado->pedido_compra_id)->firstOrFail();

            if (! $pedido->estaEmitido()) {
                throw new RecebimentoPedidoInvalidoException('Só é possível registrar recebimento num Pedido/Ordem de Compra Emitido.');
            }

            $jaRecebido = (float) RecebimentoPedido::where('pedido_compra_item_id', $itemTravado->id)->sum('quantidade_recebida');
            $pedida = (float) $itemTravado->quantidade_pedida;

            if ($jaRecebido + $quantidade > $pedida + 0.0005) {
                $saldoDisponivel = round($pedida - $jaRecebido, 3);
                throw new SaldoPedidoInsuficienteException(
                    "Este item não tem mais saldo suficiente pra receber ({$saldoDisponivel} disponível, {$quantidade} informado).",
                    $saldoDisponivel,
                    $quantidade
                );
            }

            $evento = RecebimentoPedido::create([
                'pedido_compra_item_id' => $itemTravado->id,
                'quantidade_recebida' => $quantidade,
                'recebido_em' => $dataRecebimento,
                'registrado_por' => $usuario->id,
                'local_recebimento' => $localRecebimento,
                'observacao' => $observacao,
            ]);

            // Ciclo 19, Etapa 19.7, seção 42 — receber material pode
            // resolver a Restrição automática desta cadeia (saldo pendente
            // zerou). Notification real (se houver) é adiada pra
            // DB::afterCommit() dentro do próprio serviço. Resolução do
            // Pacote via queries explícitas (nunca `->relacao`) — os
            // models aqui não vêm com nada eager-loaded, e lazy loading
            // está bloqueado fora de produção.
            $itemPedidoId = \App\Models\RequisicaoCompraItem::where('id', $itemTravado->requisicao_compra_item_id)
                ->value('requisicao_compra_id');
            $pacoteId = $itemPedidoId
                ? \App\Models\RequisicaoCompra::where('id', $itemPedidoId)->value('item_suprimento_id')
                : null;
            $pacote = $pacoteId ? \App\Models\ItemSuprimento::find($pacoteId) : null;
            if ($pacote) {
                SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote, $usuario->id);
            }

            return $evento;
        });
    }
}
