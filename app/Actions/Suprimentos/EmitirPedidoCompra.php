<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusPedidoCompra;
use App\Exceptions\AtribuicaoAdjudicacaoObrigatoriaException;
use App\Exceptions\FornecedorPedidoInvalidoException;
use App\Exceptions\PedidoCompraEmissaoInvalidaException;
use App\Exceptions\PedidoCompraImutavelException;
use App\Exceptions\SaldoAdjudicacaoFornecedorInsuficienteException;
use App\Exceptions\SaldoParcelaPedidoInsuficienteException;
use App\Exceptions\SaldoRequisicaoCompraInsuficienteException;
use App\Enums\OrigemPrevisaoEntregaPedido;
use App\Exceptions\SaldoAdjudicacaoInsuficienteException;
use App\Models\Fornecedor;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItemAdjudicacao;
use App\Models\PedidoCompraItemParcela;
use App\Models\PedidoCompraPrevisaoEntrega;
use App\Models\RequisicaoCompraAdjudicacaoItem;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoCompraItemParcela;
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
 *
 * **Etapa 2.CORREÇÃO — atribuição obrigatória de proveniência**: além do
 * teto agregado por fornecedor (Etapa 2), quando o alvo (item ou
 * parcela) tem adjudicação Ativa, a emissão agora também exige que a
 * ponte explícita `PedidoCompraItemAdjudicacao` cubra a quantidade
 * INTEIRA sendo emitida — nunca permite um Pedido nascer com uma fração
 * de origem desconhecida quando o fornecedor tem 2+ adjudicações sobre o
 * mesmo alvo (gap de proveniência histórica fechado pela auditoria
 * adversarial do Fechamento da Etapa 2).
 *
 * **Fechamento Probatório Final — achado objetivo corrigido**: o teto
 * agregado por fornecedor (acima) e a completude de atribuição (acima)
 * juntos ainda deixavam passar um caso real — dois Pedidos Rascunho
 * podiam, cada um, atribuir sua bridge até o limite CHEIO da MESMA
 * `RequisicaoCompraAdjudicacaoItem` (porque `saldoNaoConsumidoViaBridge()`
 * só conta bridges de Pedido já `Emitido`, e nenhum dos dois rascunhos
 * contava o outro), e os dois emitirem com sucesso sequencialmente
 * quando o fornecedor tinha 2+ adjudicações cuja soma agregada ainda
 * comportava as duas emissões separadamente — estourando a capacidade
 * real daquela linha específica. Corrigido com uma revalidação nova, sob
 * lock, por `RequisicaoCompraAdjudicacaoItem` referenciada pela
 * proveniência deste Pedido, na emissão.
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

                // Etapa 2 (Adjudicação) — o 3º teto, revalidado sob lock na
                // emissão. Só se aplica ao item SEM parcela (o caso com
                // parcela é revalidado abaixo, junto do teto de RCItemParcela)
                // e só quando o item TEM alguma adjudicação Ativa registrada
                // (compatibilidade — Seção 13).
                if (! $rcItem->parcelas()->exists() && $rcItem->quantidadeAdjudicadaAtivaSemParcela() > 0.0005) {
                    $saldoFornecedor = $rcItem->saldoAdjudicadoParaFornecedor($pedido->fornecedor_id);

                    if ($quantidadeDesejada > $saldoFornecedor + 0.0005) {
                        throw new SaldoAdjudicacaoFornecedorInsuficienteException(
                            "Um dos itens deste Pedido excede a quota adjudicada a este fornecedor ({$saldoFornecedor}) — outro Pedido do mesmo fornecedor consumiu parte dela enquanto este rascunho estava aberto.",
                            $saldoFornecedor,
                            $quantidadeDesejada
                        );
                    }

                    // Etapa 2.CORREÇÃO — o teto agregado acima só fecha
                    // saldo; a proveniência HISTÓRICA exige que a
                    // atribuição explícita (ponte `PedidoCompraItemAdjudicacao`)
                    // cubra a quantidade INTEIRA sendo emitida, nunca só
                    // parte dela — senão o Pedido nasceria com uma fração
                    // de origem desconhecida.
                    $atribuido = $item->quantidadeAtribuidaAdjudicacaoSemParcela();
                    if (abs($atribuido - $quantidadeDesejada) > 0.0005) {
                        throw new AtribuicaoAdjudicacaoObrigatoriaException(
                            "Este item tem adjudicação ativa para o fornecedor deste Pedido, mas a atribuição explícita de origem cobre apenas {$atribuido} de {$quantidadeDesejada} — associe a(s) adjudicação(ões) de origem antes de emitir.",
                            round($quantidadeDesejada - $atribuido, 3)
                        );
                    }
                }
            }

            // Rastreabilidade Quantitativa, Etapa 1 — revalida, sob lock,
            // que o detalhamento por Atividade deste Pedido continua
            // dentro da quota que a RC de origem ofereceu, fechando a
            // mesma corrida já fechada acima pra `quantidade_pedida`.
            $parcelasDoPedido = PedidoCompraItemParcela::whereIn('pedido_compra_item_id', $itens->pluck('id'))->get();

            if ($parcelasDoPedido->isNotEmpty()) {
                $parcelasPorItem = $parcelasDoPedido->groupBy('pedido_compra_item_id');

                foreach ($parcelasPorItem as $pedidoItemId => $grupo) {
                    $item = $itens->firstWhere('id', $pedidoItemId);

                    foreach ($grupo->groupBy('atividade_necessidade_material_id') as $necessidadeId => $subgrupo) {
                        $rcParcela = RequisicaoCompraItemParcela::where('requisicao_compra_item_id', $item->requisicao_compra_item_id)
                            ->where('atividade_necessidade_material_id', $necessidadeId)
                            ->lockForUpdate()
                            ->first();

                        if (! $rcParcela) {
                            throw new PedidoCompraEmissaoInvalidaException('Uma necessidade detalhada neste Pedido não foi encontrada na Requisição de Compra de origem.');
                        }

                        $totalEsteItem = (float) $subgrupo->sum('quantidade');
                        $saldoDisponivel = $rcParcela->saldoOficialParaPedido();

                        if ($totalEsteItem > $saldoDisponivel + 0.0005) {
                            throw new SaldoParcelaPedidoInsuficienteException(
                                "O detalhamento por Atividade de um item deste Pedido excede a quota ainda disponível ({$saldoDisponivel}) na Requisição de Compra de origem — outro Pedido já emitido consumiu parte dela enquanto este rascunho estava aberto.",
                                $saldoDisponivel,
                                $totalEsteItem
                            );
                        }

                        // Etapa 2 (Adjudicação) — o 3º teto no nível da
                        // parcela, revalidado sob o MESMO lock já adquirido
                        // acima. Compatibilidade: pulado quando a parcela
                        // nunca teve nenhuma adjudicação Ativa registrada.
                        if ($rcParcela->quantidadeAdjudicadaAtiva() > 0.0005) {
                            $saldoFornecedor = $rcParcela->saldoAdjudicadoParaFornecedor($pedido->fornecedor_id);

                            if ($totalEsteItem > $saldoFornecedor + 0.0005) {
                                throw new SaldoAdjudicacaoFornecedorInsuficienteException(
                                    "O detalhamento por Atividade de um item deste Pedido excede a quota adjudicada a este fornecedor ({$saldoFornecedor}) — outro Pedido do mesmo fornecedor consumiu parte dela enquanto este rascunho estava aberto.",
                                    $saldoFornecedor,
                                    $totalEsteItem
                                );
                            }

                            // Etapa 2.CORREÇÃO — mesma exigência de
                            // proveniência precisa, agora no nível da
                            // parcela: a soma da atribuição explícita de
                            // TODAS as `PedidoCompraItemParcela` desta
                            // necessidade precisa cobrir exatamente o que
                            // está sendo emitido.
                            $atribuido = (float) $subgrupo->sum(fn ($p) => $p->quantidadeAtribuidaAdjudicacao());
                            if (abs($atribuido - $totalEsteItem) > 0.0005) {
                                throw new AtribuicaoAdjudicacaoObrigatoriaException(
                                    "O detalhamento por Atividade de um item deste Pedido tem adjudicação ativa para o fornecedor, mas a atribuição explícita de origem cobre apenas {$atribuido} de {$totalEsteItem} — associe a(s) adjudicação(ões) de origem antes de emitir.",
                                    round($totalEsteItem - $atribuido, 3)
                                );
                            }
                        }
                    }
                }
            }

            // Etapa 2.CORREÇÃO (Fechamento Probatório Final — achado
            // objetivo): TETO 4/5 (Seção 5) — o teto agregado por
            // fornecedor acima fecha o total por fornecedor, mas nunca
            // detectava dois Pedidos Rascunho consumindo, cada um até o
            // limite CHEIO, a MESMA linha específica de adjudicação
            // (`RequisicaoCompraAdjudicacaoItem`), quando o fornecedor
            // tem 2+ adjudicações cuja soma agregada ainda comporta os
            // dois Pedidos separadamente. Revalida, sob lock e na ordem
            // já estabelecida (recurso da Adjudicação sempre por
            // último), que cada linha de adjudicação referenciada pela
            // proveniência deste Pedido ainda tem saldo suficiente pra
            // cobrir exatamente o que ESTE Pedido está prestes a
            // oficializar.
            $bridgesDoPedido = PedidoCompraItemAdjudicacao::whereIn('pedido_compra_item_id', $itens->pluck('id'))->get();

            if ($bridgesDoPedido->isNotEmpty()) {
                $adjudicacaoItemIds = $bridgesDoPedido->pluck('requisicao_compra_adjudicacao_item_id')->unique()->sort()->values();

                $adjudicacaoItensTravados = RequisicaoCompraAdjudicacaoItem::whereIn('id', $adjudicacaoItemIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($bridgesDoPedido->groupBy('requisicao_compra_adjudicacao_item_id') as $adjudicacaoItemId => $grupoBridges) {
                    $adjudicacaoItemTravado = $adjudicacaoItensTravados->get($adjudicacaoItemId);

                    if (! $adjudicacaoItemTravado) {
                        throw new PedidoCompraEmissaoInvalidaException('Uma linha de adjudicação referenciada por este Pedido não foi encontrada.');
                    }

                    $solicitadoNestePedido = (float) $grupoBridges->sum('quantidade');
                    $saldoDisponivel = $adjudicacaoItemTravado->saldoNaoConsumidoViaBridge();

                    if ($solicitadoNestePedido > $saldoDisponivel + 0.0005) {
                        throw new SaldoAdjudicacaoInsuficienteException(
                            "Uma linha de adjudicação referenciada por este Pedido não tem mais saldo suficiente ({$saldoDisponivel} disponível, {$solicitadoNestePedido} atribuído por este Pedido) — outro Pedido já emitido consumiu parte dela enquanto este rascunho estava aberto.",
                            $saldoDisponivel,
                            $solicitadoNestePedido
                        );
                    }
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

            // Etapa 3, Seção 7 — registra o fato histórico da PRIMEIRA
            // previsão conhecida, no instante em que ela se torna um
            // compromisso comercial formal (emissão). Nunca duplica se o
            // Pedido já tinha histórico (ex.: previsão já revisada
            // enquanto ainda era Rascunho, via
            // `AtualizarPrevisaoEntregaPedidoCompra`) — a linha `Inicial`
            // é sempre a PRIMEIRA da vida do Pedido, nunca recriada.
            if (! PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->exists()) {
                PedidoCompraPrevisaoEntrega::create([
                    'pedido_compra_id' => $pedido->id,
                    'data_prevista' => $pedido->data_prevista_entrega,
                    'origem' => OrigemPrevisaoEntregaPedido::Inicial,
                    'registrado_por_id' => $usuario->id,
                    'registrado_em' => now(),
                    'motivo' => null,
                    'observacao' => null,
                ]);
            }

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
