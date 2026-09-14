<?php

namespace App\Actions\Suprimentos;

use App\Exceptions\RecebimentoConciliacaoFechadaException;
use App\Exceptions\RecebimentoConciliacaoInvalidaException;
use App\Models\PedidoCompraItemParcela;
use App\Models\RecebimentoPedido;
use App\Models\RecebimentoPedidoParcela;
use App\Models\User;
use App\Support\Suprimentos\PoliticaDistribuicaoRecebimento;
use Illuminate\Support\Facades\DB;

/**
 * Fechamento Adversarial Etapa 3 (Seções 9-14/21) — único ponto de
 * escrita de `RecebimentoPedidoParcela`. Registra, de forma sempre
 * EXPLÍCITA (nunca FIFO/proporcional/heurística — Seção 8), pra qual
 * necessidade/parcela uma fração de um recebimento já registrado
 * efetivamente se destina.
 *
 * **Decoupled do ato de receber** (Seção 9, Opção B — "posteriormente
 * em uma conciliação" — preferência explícita do pedido: "receber
 * primeiro é fato; classificar destino lógico pode ser feito depois"):
 * `RegistrarRecebimentoPedido` nunca foi alterado, nunca exige
 * distribuição — esta Action é sempre uma etapa OPCIONAL e POSTERIOR.
 *
 * **Ordem de lock — total order determinístico**: `RecebimentoPedido`
 * (o "aberto/fechado" que a Política deriva) SEMPRE primeiro, depois
 * `PedidoCompraItemParcela` (o outro saldo disputado, TETO B) — nunca a
 * ordem inversa, em nenhum caller.
 *
 * **Tetos (Seção 13)**: A — soma das distribuições deste recebimento
 * nunca excede a quantidade recebida (`PoliticaDistribuicaoRecebimento::
 * pendente()`); B — soma das distribuições desta parcela nunca excede
 * `PedidoCompraItemParcela.quantidade`; C — a parcela alvo precisa
 * pertencer ao MESMO `PedidoCompraItem` do recebimento (nunca item ou
 * Pedido diferente — subsume o TETO D do pedido original, já que o
 * `pedido_compra_item_id` de uma parcela intrinsecamente amarra a UM
 * único Pedido); E — tenant/obra coerentes, garantido transitivamente
 * pelo TETO C (mesmo item ⇒ mesmo Pedido ⇒ mesma obra/tenant) + o
 * global scope automático de `BelongsToTenant` nas duas travas.
 */
class DistribuirRecebimentoPedidoPorParcela
{
    public function execute(
        RecebimentoPedido $recebimento,
        PedidoCompraItemParcela $parcela,
        float $quantidade,
        ?User $usuario,
    ): RecebimentoPedidoParcela {
        return DB::transaction(function () use ($recebimento, $parcela, $quantidade, $usuario) {
            if ($quantidade <= 0) {
                throw new RecebimentoConciliacaoInvalidaException('A quantidade distribuída precisa ser maior que zero.');
            }

            $recebimentoTravado = RecebimentoPedido::whereKey($recebimento->id)->lockForUpdate()->firstOrFail();
            $parcelaTravada = PedidoCompraItemParcela::whereKey($parcela->id)->lockForUpdate()->firstOrFail();

            // TETO C/D — a parcela alvo precisa pertencer ao MESMO
            // PedidoCompraItem do recebimento (nunca item/Pedido diferente).
            if ($parcelaTravada->pedido_compra_item_id !== $recebimentoTravado->pedido_compra_item_id) {
                throw new RecebimentoConciliacaoInvalidaException(
                    'Esta parcela pertence a um item/Pedido diferente do recebimento — não é possível atribuir recebimento entre itens/Pedidos distintos.'
                );
            }

            // TETO A — nunca distribuir além do que este recebimento
            // efetivamente recebeu.
            $pendenteRecebimento = PoliticaDistribuicaoRecebimento::pendente($recebimentoTravado);
            if ($quantidade > $pendenteRecebimento + 0.0005) {
                throw new RecebimentoConciliacaoInvalidaException(
                    "Quantidade solicitada ({$quantidade}) excede o saldo ainda não distribuído deste recebimento ({$pendenteRecebimento})."
                );
            }

            // TETO B — nunca distribuir além do que a própria parcela
            // representa como necessidade.
            $jaAtribuidoAParcela = PoliticaDistribuicaoRecebimento::totalAtribuidoAParcela($parcelaTravada);
            $saldoParcela = round((float) $parcelaTravada->quantidade - $jaAtribuidoAParcela, 3);
            if ($quantidade > $saldoParcela + 0.0005) {
                throw new RecebimentoConciliacaoInvalidaException(
                    "Quantidade solicitada ({$quantidade}) excede o saldo ainda não atribuído desta parcela ({$saldoParcela})."
                );
            }

            return RecebimentoPedidoParcela::create([
                'recebimento_pedido_id' => $recebimentoTravado->id,
                'pedido_compra_item_parcela_id' => $parcelaTravada->id,
                'quantidade' => $quantidade,
                'created_by_id' => $usuario?->id,
            ]);
        });
    }

    /**
     * Remoção só permitida enquanto o recebimento dono ainda está
     * "aberto" (mesma checagem já feita pelo Observer — revalidada aqui
     * sob lock, nunca só confiada ao evento `deleting()`).
     *
     * **Nunca exclui a própria distribuição do cálculo de "fechado"**
     * (mesmo padrão exato de `RemoverAplicacaoMaterialEstoque`, Ciclo
     * 20.4) — a pergunta é sempre "este recebimento está fechado AGORA,
     * com este estado?", nunca "ficaria aberto se eu removesse esta
     * linha?". Reabrir uma conciliação já fechada excluindo justamente a
     * linha que a fechou não é permitido (decisão explícita).
     */
    public function remover(RecebimentoPedidoParcela $distribuicao): void
    {
        DB::transaction(function () use ($distribuicao) {
            $recebimentoTravado = RecebimentoPedido::whereKey($distribuicao->recebimento_pedido_id)->lockForUpdate()->firstOrFail();

            if (PoliticaDistribuicaoRecebimento::recebimentoEstaFechado($recebimentoTravado)) {
                throw new RecebimentoConciliacaoFechadaException(
                    'Este recebimento já está 100% distribuído por necessidade — não é possível excluir uma distribuição dele.'
                );
            }

            $distribuicao->delete();
        });
    }
}
