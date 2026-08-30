<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusPedidoCompra;
use App\Exceptions\FornecedorPedidoInvalidoException;
use App\Exceptions\PedidoCompraEmissaoInvalidaException;
use App\Exceptions\PedidoCompraImutavelException;
use App\Exceptions\SaldoRequisicaoCompraInsuficienteException;
use App\Models\Fornecedor;
use App\Models\PedidoCompra;
use App\Models\RequisicaoCompraItem;
use App\Models\User;
use App\Models\Work;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 19, Etapa 19.5 — transição Rascunho -> Emitido. Mesmo padrão
 * exato de `EmitirRequisicaoCompra`/`EmitirRequisicaoPlanejamento`: lock
 * em linha estável da obra (Work) pra numeração serializada,
 * `UNIQUE(obra_id, numero)` como defesa final; tudo dentro de UMA
 * transação — se qualquer validação falhar, nada é escrito.
 *
 * **Revalidação total**: nunca confia no saldo visto quando o rascunho
 * foi montado — trava (`lockForUpdate`, `orderBy('id')`, determinismo
 * explícito) cada `RequisicaoCompraItem` envolvido e recalcula o saldo
 * OFICIAL (só outros Pedidos `Emitido`) na hora, ANTES de congelar
 * qualquer snapshot.
 *
 * **Snapshots — item 38 do pedido**: nunca depende de "RCItem vivo" pra
 * descrição — copia diretamente dos campos `*_snapshot` já congelados
 * em `RequisicaoCompraItem` (por sua vez congelados na emissão da RC),
 * nunca reatravessa a cadeia inteira até `ItemTakeOff` de novo.
 *
 * **19.5.CORREÇÃO — Fornecedor SEMPRE revalidado FRESH, nunca via
 * relação já carregada**: achado C confirmado na auditoria adversarial
 * — `$pedido->fornecedor()->first()` respeitava o scope de SoftDeletes
 * e retornava `null` silenciosamente quando o Fornecedor tinha sido
 * soft-deletado entre o rascunho e a emissão, congelando um Pedido
 * formal SEM identidade de fornecedor. Corrigido: uma query FRESH
 * (`Fornecedor::query()->where('obra_id', ...)->whereKey(...)
 * ->first()`, nunca `withTrashed()` — nunca "ressuscita" um fornecedor
 * inativo silenciosamente) roda ANTES de qualquer escrita; ausência
 * (soft-deletado, ou cross-obra por algum caminho que tenha burlado a
 * UI) bloqueia a emissão inteira com `FornecedorPedidoInvalidoException`
 * — zero número/status/emitido_em/snapshot parcial.
 *
 * **19.5.CORREÇÃO — `data_prevista_entrega` obrigatória na emissão**:
 * Rascunho pode ficar incompleto (sem essa data), mas Pedido Emitido
 * SEMPRE tem — é o que elimina, por construção, a ambiguidade de
 * `RequisicaoCompra::dataProjetadaAtendimento()` (nunca mais existe um
 * Pedido Emitido silenciosamente ignorado nesse cálculo por falta de
 * data). Validado aqui na Action, não como `NOT NULL` no banco — a
 * coluna continua nullable pra Rascunho incompleto ser um estado
 * legítimo.
 */
class EmitirPedidoCompra
{
    public function execute(PedidoCompra $pedido, User $usuario): PedidoCompra
    {
        return DB::transaction(function () use ($pedido, $usuario) {
            Work::whereKey($pedido->obra_id)->lockForUpdate()->firstOrFail();

            $pedido = PedidoCompra::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if ($pedido->status !== StatusPedidoCompra::Rascunho) {
                throw new PedidoCompraImutavelException('Este Pedido/Ordem de Compra já foi emitido e não pode ser emitido novamente.');
            }

            $itens = $pedido->itens()->get();
            if ($itens->isEmpty()) {
                throw new PedidoCompraEmissaoInvalidaException('O Pedido/Ordem de Compra precisa ter ao menos 1 item antes de ser emitido.');
            }

            if (! $pedido->data_prevista_entrega) {
                throw new PedidoCompraEmissaoInvalidaException(
                    'Informe a data prevista de entrega antes de emitir o Pedido.'
                );
            }

            // Fresh, nunca via relação/objeto já carregado — nunca
            // withTrashed(): um Fornecedor soft-deletado (ou de outra
            // obra, defesa em profundidade) bloqueia a emissão inteira,
            // antes de qualquer escrita.
            $fornecedor = Fornecedor::query()
                ->where('obra_id', $pedido->obra_id)
                ->whereKey($pedido->fornecedor_id)
                ->first();

            if (! $fornecedor) {
                throw new FornecedorPedidoInvalidoException(
                    'O fornecedor selecionado não está mais disponível. Selecione um fornecedor ativo antes de emitir o Pedido.'
                );
            }

            $rcItemIds = $itens->pluck('requisicao_compra_item_id')->all();

            $rcItensTravados = RequisicaoCompraItem::whereIn('id', $rcItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($itens as $item) {
                $rcItem = $rcItensTravados->get($item->requisicao_compra_item_id);

                if (! $rcItem) {
                    throw new PedidoCompraEmissaoInvalidaException('Um item da Requisição de Compra deste Pedido não foi encontrado.');
                }

                // exclui a própria linha ($item) — este Pedido ainda é Rascunho
                // aqui, então quantidadeConsumidaOficialPorPedido() já não a
                // conta (filtra por status Emitido); nada a excluir por id.
                $saldoDisponivel = $rcItem->saldoOficialParaPedido();
                $quantidadeDesejada = (float) $item->quantidade_pedida;

                if ($quantidadeDesejada > $saldoDisponivel + 0.0005) {
                    throw new SaldoRequisicaoCompraInsuficienteException(
                        "Um dos itens deste Pedido não tem mais saldo suficiente ({$saldoDisponivel} disponível, {$quantidadeDesejada} pedido) — outro Pedido consumiu o saldo enquanto este rascunho estava aberto.",
                        $saldoDisponivel,
                        $quantidadeDesejada
                    );
                }
            }

            // Só depois de TODAS as validações passarem: congela os snapshots.
            foreach ($itens as $item) {
                $rcItem = $rcItensTravados->get($item->requisicao_compra_item_id);

                $item->forceFill([
                    'codigo_item_snapshot' => $rcItem->codigo_item_snapshot,
                    'descricao_snapshot' => $rcItem->descricao_snapshot,
                    'unidade_snapshot' => $rcItem->unidade_snapshot,
                    'lista_codigo_snapshot' => $rcItem->lista_codigo_snapshot,
                    'tipo_lista_snapshot' => $rcItem->tipo_lista_snapshot,
                    'documento_codigo_snapshot' => $rcItem->documento_codigo_snapshot,
                    'revisao_snapshot' => $rcItem->revisao_snapshot,
                ])->save();
            }

            $proximoNumero = (int) PedidoCompra::withTrashed()->where('obra_id', $pedido->obra_id)->max('numero') + 1;

            $pedido->forceFill([
                'numero' => $proximoNumero,
                'status' => StatusPedidoCompra::Emitido,
                'fornecedor_nome_snapshot' => $fornecedor->nome,
                'fornecedor_cnpj_snapshot' => $fornecedor->cnpj,
                'emitido_em' => now(),
                'emitido_por' => $usuario->id,
            ])->save();

            // Ciclo 19, Etapa 19.7, seção 42 — evento direto (não espera o
            // Scheduler diário): emitir um Pedido pode ser a primeira
            // demanda formal "pendente" de um Pacote (Condição C passa a
            // considerar esse saldo), ou mudar `dataProjetadaAtendimento()`
            // (Alerta A). O disparo da Notification (não a escrita da
            // Restrição) é adiado pra `DB::afterCommit()` dentro do
            // próprio serviço — uma falha real na sincronização da
            // Restrição em si continua propagando e revertendo esta
            // transação (mesmo tratamento de qualquer outra escrita aqui).
            $pacoteId = \App\Models\RequisicaoCompra::where('id', $pedido->requisicao_compra_id)->value('item_suprimento_id');
            $pacote = $pacoteId ? \App\Models\ItemSuprimento::find($pacoteId) : null;
            if ($pacote) {
                SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote, $usuario->id);
            }

            return $pedido->fresh('itens');
        });
    }
}
