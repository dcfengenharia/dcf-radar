<?php

namespace App\Support\Estoque;

use App\Enums\StatusPedidoCompra;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\ItemTakeOff;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCompraItem;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — fonte ÚNICA de verdade pra "o Material
 * deste ItemTakeOff pode ser alterado agora?". Reaproveitada tanto por
 * App\Actions\Estoque\AssociarMaterialAoItemTakeOff (API oficial de
 * escrita) quanto por App\Observers\ItemTakeOffObserver (barreira de
 * defesa contra qualquer outro caminho de escrita de instância) — nunca
 * duas regras divergentes (achado da auditoria adversarial, Seção 15).
 *
 * **Primeira associação (material_id null) é SEMPRE permitida**,
 * independente de quanto a cadeia comercial já avançou — o corte de
 * imutabilidade protege contra REESCREVER uma identidade já afirmada,
 * nunca contra AFIRMAR uma identidade que nunca existiu. Sem essa
 * distinção, um ItemTakeOff cujo Pedido já foi emitido ANTES de alguém
 * associar Material nunca poderia ser associado — o que inverteria o
 * próprio propósito desta correção (fechar o Achado C1: hoje não existe
 * NENHUM caminho real de associação).
 *
 * **Corte de imutabilidade (decisão desta correção, Achado C2 fechado)**:
 * o Material torna-se imutável assim que existir o PRIMEIRO fato formal
 * que compromete comercialmente a identidade — investigado e decidido
 * como **Pedido de Compra Emitido** (não "primeira entrada em estoque",
 * que a auditoria provou insuficiente — Seção 9 do pedido de correção).
 * `RecebimentoPedido` nunca precisa de uma checagem própria: por
 * construção (`RegistrarRecebimentoPedido::execute()` já exige
 * `$pedido->estaEmitido()`), todo Recebimento pressupõe um Pedido já
 * Emitido — checar "existe Pedido Emitido na cadeia" cobre Recebimento
 * por transitividade, sem precisar de uma segunda query.
 * `MovimentacaoEstoque` também nunca precisa de checagem separada pelo
 * mesmo motivo (uma entrada só existe sobre um Recebimento, que só
 * existe sobre um Pedido Emitido) — mas é verificada mesmo assim,
 * diretamente e em primeiro lugar, por ser a consulta mais barata (O(1)
 * via breadcrumb denormalizado) e por ser a evidência mais forte
 * possível de comprometimento físico real.
 *
 * **RP/RC/Pedido em Rascunho NUNCA congelam** (Seção 10 do pedido) — são
 * descartáveis pela própria política já aprovada em Ciclo 19; só
 * `StatusPedidoCompra::Emitido` conta.
 *
 * **Ciclo 20, Etapa 20.2.CORREÇÃO — fecha o Achado C3 da auditoria
 * adversarial**: até esta correção, a política só conhecia o corte
 * COMERCIAL (Pedido Emitido/entrada física) — nunca o corte de
 * PLANEJAMENTO criado pela 20.2 (`DestinacaoPlanejadaMaterial`). Provado
 * empiricamente (auditoria, probe P09) que trocar o Material de um
 * ItemTakeOff ANTES de qualquer Pedido Emitido, mas DEPOIS que uma
 * Destinação já existe pro par Pacote+Material antigo, deixava a
 * Destinação ÓRFÃ (`ConciliacaoDestinacao::quantidadeFormal()` caindo a
 * 0 enquanto `quantidade_planejada` permanecia, sem nenhum guard
 * detectando o estado logicamente inválido resultante).
 *
 * **Decisão de política (conservadora, Seção 17 do pedido de correção)**:
 * se este ItemTakeOff participa (via QUALQUER `AlocacaoRequisicaoPacote`
 * de qualquer `RequisicaoPlanejamentoItem` seu) de um Pacote que já tem
 * uma `DestinacaoPlanejadaMaterial` pro Material ATUAL do item, o
 * Material congela — mesmo que reduzir só a contribuição DESTE item
 * ainda deixasse saldo formal suficiente pra cobrir a Destinação
 * (Opção B, descartada). Motivo: uma vez que o Planejamento já tomou uma
 * decisão operacional sobre "quanto desse Material, neste Pacote, vai
 * pra qual Frente", a COMPOSIÇÃO DOCUMENTAL que sustenta esse número
 * (quais ItemTakeOff contribuem) não deve ser reinterpretada
 * silenciosamente depois — mesmo que a aritmética "ainda feche".
 * Congelamento é POR PACOTE: um ItemTakeOff alocado em 2 Pacotes
 * diferentes só congela pelos Pacotes que efetivamente têm Destinação
 * pro Material atual; um Pacote sem nenhuma Destinação nunca bloqueia
 * (Seção 16 — não congelar demais só por existir Alocação).
 */
class PoliticaAssociacaoMaterial
{
    public static function podeAlterarMaterial(ItemTakeOff $item): bool
    {
        $materialIdAtual = $item->getOriginal('material_id');

        if ($materialIdAtual === null) {
            return true;
        }

        return ! self::possuiComprometimentoFormal($item) && ! self::possuiDestinacaoPlanejadaVinculada($item);
    }

    /**
     * True quando existe, pra este ItemTakeOff, pelo menos uma
     * MovimentacaoEstoque OU um PedidoCompra Emitido alcançável pela
     * cadeia comercial (RequisicaoPlanejamentoItem → AlocacaoRequisicaoPacote
     * → RequisicaoCompraItem → PedidoCompraItem → PedidoCompra).
     */
    public static function possuiComprometimentoFormal(ItemTakeOff $item): bool
    {
        if (MovimentacaoEstoque::where('item_take_off_id', $item->id)->exists()) {
            return true;
        }

        return PedidoCompraItem::query()
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value))
            ->whereHas('requisicaoCompraItem.alocacao.requisicaoItem', fn ($q) => $q->where('item_take_off_id', $item->id))
            ->exists();
    }

    /**
     * True quando este ItemTakeOff (pelo seu Material ORIGINAL — nunca
     * o novo valor "dirty" em trânsito durante um update()) participa de
     * algum Pacote que já tem uma DestinacaoPlanejadaMaterial pra esse
     * mesmo par Pacote+Material — corte de PLANEJAMENTO (Ciclo 20, Etapa
     * 20.2.CORREÇÃO), independente do corte comercial acima.
     *
     * **Achado corrigido durante a implementação (não um bug de
     * produção já exposto — pego pelos próprios testes desta correção,
     * antes de qualquer uso real)**: a primeira versão lia
     * `$item->material_id` — mas dentro do evento `updating()` do
     * Eloquent, o Model JÁ TEM o novo valor atribuído em memória ANTES
     * do evento disparar (`$item->material_id` já é o material NOVO,
     * não o antigo). Isso fazia a checagem procurar Destinação do
     * material ERRADO (o novo, que nunca teria Destinação nenhuma),
     * sempre retornando `false` e nunca bloqueando a troca. Corrigido
     * usando `getOriginal('material_id')` — mesmo idioma já usado por
     * `podeAlterarMaterial()` logo acima, agora propagado
     * consistentemente pra este método também.
     */
    public static function possuiDestinacaoPlanejadaVinculada(ItemTakeOff $item): bool
    {
        $materialIdOriginal = $item->getOriginal('material_id');

        if (! $materialIdOriginal) {
            return false;
        }

        $pacoteIds = AlocacaoRequisicaoPacote::query()
            ->whereHas('requisicaoItem', fn ($q) => $q->where('item_take_off_id', $item->id))
            ->pluck('item_suprimento_id')
            ->unique();

        if ($pacoteIds->isEmpty()) {
            return false;
        }

        return DestinacaoPlanejadaMaterial::whereIn('item_suprimento_id', $pacoteIds)
            ->where('material_id', $materialIdOriginal)
            ->exists();
    }

    /**
     * Ciclo 20, Etapa 20.3.CORREÇÃO — versão em LOTE de
     * podeAlterarMaterial(), exclusiva de listagens (fecha a parcela
     * remanescente do Achado B de N+1 em
     * ⚡estoque.blade.php::recebimentosPendentes() — a resolução da
     * cadeia ItemTakeOff/Material/incorporado já tinha sido batchada,
     * mas esta checagem, chamada 1x por linha dentro do MESMO `->map()`,
     * ainda contribuía com 1-2 queries por linha). O método de 1 item
     * acima NUNCA é alterado — continua sendo a única fonte de verdade
     * pra escrita (`AssociarMaterialAoItemTakeOff`/`ItemTakeOffObserver`);
     * este é só uma leitura em lote do MESMO resultado, reaproveitando
     * as MESMAS relações/nomes já usados no método de 1 item — nunca uma
     * regra paralela.
     *
     * Sempre um número FIXO de queries (≈6-7), nunca por linha.
     *
     * @param  Collection<int, ItemTakeOff>  $itens
     * @return Collection<string, bool> chave = item_take_off_id
     */
    public static function podeAlterarMaterialEmLote(Collection $itens): Collection
    {
        if ($itens->isEmpty()) {
            return collect();
        }

        // Só itens que JÁ TÊM material_id associado entram na análise —
        // igual ao método de 1 item, ausência de material_id original
        // sempre permite (primeira associação nunca congela).
        $itensComMaterial = $itens->filter(fn (ItemTakeOff $item) => $item->material_id !== null);
        $itemIds = $itensComMaterial->pluck('id');

        if ($itemIds->isEmpty()) {
            return $itens->mapWithKeys(fn (ItemTakeOff $item) => [$item->id => true]);
        }

        // ---- comprometimento formal: MovimentacaoEstoque (1 query) ----
        $idsComMovimentacao = MovimentacaoEstoque::whereIn('item_take_off_id', $itemIds)
            ->distinct()
            ->pluck('item_take_off_id')
            ->flip();

        // ---- comprometimento formal: Pedido Emitido na cadeia (mesma
        // relação exata do método de 1 item, só com whereIn + eager load
        // em vez de 1 query por item) ----
        $idsComPedidoEmitido = PedidoCompraItem::query()
            ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value))
            ->whereHas('requisicaoCompraItem.alocacao.requisicaoItem', fn ($q) => $q->whereIn('item_take_off_id', $itemIds))
            ->with('requisicaoCompraItem.alocacao.requisicaoItem:id,item_take_off_id')
            ->get()
            ->pluck('requisicaoCompraItem.alocacao.requisicaoItem.item_take_off_id')
            ->filter()
            ->unique()
            ->flip();

        // ---- corte de planejamento: Destinação vinculada ao Pacote do
        // item (mesma relação exata do método de 1 item, em lote) ----
        $alocacoes = AlocacaoRequisicaoPacote::query()
            ->whereHas('requisicaoItem', fn ($q) => $q->whereIn('item_take_off_id', $itemIds))
            ->with('requisicaoItem:id,item_take_off_id')
            ->get();

        $pacoteIdsPorItem = $alocacoes
            ->groupBy(fn (AlocacaoRequisicaoPacote $a) => $a->requisicaoItem?->item_take_off_id)
            ->map(fn ($grupo) => $grupo->pluck('item_suprimento_id')->unique()->values());

        $todosPacoteIds = $pacoteIdsPorItem->flatten()->unique()->values();
        $destinacoesPorPacote = $todosPacoteIds->isEmpty()
            ? collect()
            : DestinacaoPlanejadaMaterial::whereIn('item_suprimento_id', $todosPacoteIds)
                ->get(['item_suprimento_id', 'material_id'])
                ->groupBy('item_suprimento_id');

        return $itens->mapWithKeys(function (ItemTakeOff $item) use (
            $idsComMovimentacao, $idsComPedidoEmitido, $pacoteIdsPorItem, $destinacoesPorPacote
        ) {
            if ($item->material_id === null) {
                return [$item->id => true];
            }

            if ($idsComMovimentacao->has($item->id) || $idsComPedidoEmitido->has($item->id)) {
                return [$item->id => false];
            }

            $pacoteIds = $pacoteIdsPorItem->get($item->id, collect());
            $possuiDestinacao = $pacoteIds->contains(function (string $pacoteId) use ($item, $destinacoesPorPacote) {
                return $destinacoesPorPacote->get($pacoteId, collect())
                    ->contains(fn ($d) => $d->material_id === $item->material_id);
            });

            return [$item->id => ! $possuiDestinacao];
        });
    }
}
