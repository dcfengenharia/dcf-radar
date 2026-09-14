<?php

namespace App\Actions\Suprimentos;

use App\Enums\OrigemPrevisaoEntregaPedido;
use App\Exceptions\PrevisaoEntregaInvalidaException;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraPrevisaoEntrega;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 3 — único ponto de escrita pra REVISAR a previsão de entrega de
 * um Pedido já existente (Rascunho OU Emitido — Seção 9 do pedido:
 * diferente de quantidade/fornecedor, prazo PODE mudar mesmo depois da
 * emissão, mas toda mudança fica historicamente registrada, nunca um
 * UPDATE silencioso).
 *
 * **`Inicial` só nasce quando genuinamente NADA comercial aconteceu
 * ainda — Fechamento Adversarial, Seção 5, achado corrigido**: a versão
 * original decidia `Inicial`/`Revisao` só pela EXISTÊNCIA de histórico
 * (`$jaTemHistorico`), o que rotulava incorretamente a primeira revisão
 * formal de um Pedido LEGADO (já `Emitido`, com `data_prevista_entrega`
 * conhecida mas sem nenhuma linha de histórico) como `Inicial` —
 * fabricando a narrativa de que a nova data era a promessa feita NA
 * PRÓPRIA EMISSÃO, e apagando silenciosamente o fato de que já existia
 * um compromisso comercial real e conhecido antes desta revisão. A
 * regra correta usa `estaEmitido()`: `Inicial` só quando o Pedido AINDA
 * é Rascunho e não tem histórico nenhum (nada comercial existe ainda —
 * mesmo cenário já coberto por `EmitirPedidoCompra`, que cria a própria
 * linha `Inicial` na emissão quando esta Action nunca foi chamada
 * antes). Qualquer chamada sobre um Pedido já `Emitido` é SEMPRE
 * `Revisao`, mesmo sem nenhum histórico prévio (legado) — nunca inventa
 * autoria/data pro valor legado em si (ele nunca vira uma linha própria,
 * só o NOVO valor é registrado, como revisão de um compromisso já
 * existente e conhecido, mesmo que não rastreado até agora).
 *
 * **Disciplina de autoridade única (Seção 6)**: `data_prevista_entrega`
 * de `PedidoCompra` continua sendo o snapshot/cache da previsão vigente
 * — esta Action SEMPRE escreve o novo evento histórico e atualiza o
 * snapshot na MESMA transação, sob o MESMO lock — nunca divergem.
 *
 * **Concorrência (Seção 32)**: `PedidoCompra::lockForUpdate()` garante
 * que duas atualizações simultâneas nunca produzem um snapshot que não
 * corresponda ao ÚLTIMO evento histórico efetivamente gravado.
 */
class AtualizarPrevisaoEntregaPedidoCompra
{
    public function execute(
        PedidoCompra $pedido,
        \DateTimeInterface $novaData,
        User $usuario,
        ?string $motivo,
        ?string $observacao,
    ): PedidoCompraPrevisaoEntrega {
        return DB::transaction(function () use ($pedido, $novaData, $usuario, $motivo, $observacao) {
            $pedido = PedidoCompra::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            $jaTemHistorico = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->exists();

            // Inicial só quando NADA comercial já existe: Rascunho E sem
            // nenhum histórico. Emitido sem histórico é sempre um LEGADO
            // com compromisso já conhecido — a revisão nova é sempre
            // Revisao, nunca Inicial (Fechamento Adversarial, Seção 5).
            $ehRevisaoDeAlgoJaConhecido = $jaTemHistorico || $pedido->estaEmitido();

            $this->garantirMotivoQuandoRevisao($ehRevisaoDeAlgoJaConhecido, $motivo);

            $evento = PedidoCompraPrevisaoEntrega::create([
                'pedido_compra_id' => $pedido->id,
                'data_prevista' => $novaData,
                'origem' => $ehRevisaoDeAlgoJaConhecido ? OrigemPrevisaoEntregaPedido::Revisao : OrigemPrevisaoEntregaPedido::Inicial,
                'registrado_por_id' => $usuario->id,
                'registrado_em' => now(),
                'motivo' => $motivo,
                'observacao' => $observacao,
            ]);

            // Snapshot atualizado na MESMA transação — Seção 6, nunca
            // duas verdades independentes.
            $pedido->forceFill(['data_prevista_entrega' => $novaData])->save();

            return $evento;
        });
    }

    /**
     * A primeira previsão de um Rascunho GENUINAMENTE sem nenhum
     * compromisso comercial anterior nunca exige motivo — não é uma
     * "mudança" de nada, é o primeiro fato. Toda REVISÃO (inclusive a
     * primeira revisão formal de um legado já Emitido, Seção 5) exige
     * motivo não-vazio (mesmo princípio já usado em
     * `RequisicaoCompraAdjudicacao.justificativa` — nunca alterar uma
     * decisão comercial em silêncio).
     */
    private function garantirMotivoQuandoRevisao(bool $ehRevisao, ?string $motivo): void
    {
        if ($ehRevisao && trim((string) $motivo) === '') {
            throw new PrevisaoEntregaInvalidaException('Informe o motivo da revisão de prazo.');
        }
    }
}
