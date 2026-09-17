<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Exceptions\AdjudicacaoConsumidaPorPedidoException;
use App\Exceptions\RequisicaoCompraAdjudicacaoInvalidaException;
use App\Exceptions\SaldoAdjudicacaoInsuficienteException;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraAdjudicacao;
use App\Models\RequisicaoCompraAdjudicacaoItem;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoCompraItemParcela;
use App\Models\User;
use App\Support\Perfis\GarantirAutoridadeNaObra;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 2 — único ponto de escrita da composição quantitativa de uma
 * `RequisicaoCompraAdjudicacao` (itens) e de sua transição Ativa ->
 * Cancelada.
 *
 * **Ordem de lock — ESTENDE o total order já estabelecido no Ciclo 19**
 * (`RequisicaoCompraItem → RequisicaoCompra → RequisicaoCompraItemParcela`,
 * mesma ordem de `AtualizarDistribuicaoParcelaRequisicaoCompra`/
 * `AtualizarDistribuicaoParcelaPedidoCompra`): `RequisicaoCompraAdjudicacao`
 * entra como o recurso NOVO, travado por ÚLTIMO — nunca antes de
 * RCItem/RC/Parcela, mesmo princípio já documentado em todo o Ciclo 19
 * ("recurso novo, nunca disputado antes, trava-se por último pra nunca
 * inverter a ordem já estabelecida"). `cancelar()` nunca trava a RC
 * (seu status não é disputado por esta operação) — só RCItem(ns)/
 * Parcela(s) (na mesma ordem relativa) antes do cabeçalho da própria
 * Adjudicação, então não há ciclo possível entre `cancelar()` e
 * `adicionarItem()`/`alterarQuantidadeItem()`.
 *
 * **Granularidade — decisão explícita (ver migration da tabela de
 * itens)**: item COM parcela(s) de Etapa 1 exige adjudicação por
 * parcela; item SEM nenhuma exige adjudicação direta no item
 * (`requisicao_compra_item_parcela_id = null`) — nunca mistura os dois
 * pro mesmo item, elimina ambiguidade na guarda de saldo.
 *
 * **Consumo por Pedido — Etapa 2.CORREÇÃO, ponte explícita**: a versão
 * original desta classe usava a soma AGREGADA por fornecedor
 * (`RequisicaoCompraItem::quantidadeConsumidaOficialPorPedidoDoFornecedor()`/
 * `RequisicaoCompraItemParcela::...`) — suficiente pra fechar saldo, mas
 * incapaz de provar proveniência histórica quando o MESMO fornecedor tem
 * 2+ adjudicações (ativas ou canceladas) sobre o MESMO alvo (gap
 * confirmado pela auditoria adversarial do Fechamento da Etapa 2).
 * Corrigido para usar `RequisicaoCompraAdjudicacaoItem::
 * quantidadeConsumidaViaBridge()` — soma PRECISA via a ponte explícita
 * `PedidoCompraItemAdjudicacao`, nunca mais inferida por fornecedor.
 *
 * Fase 2E.CORREÇÃO — `adicionarItem()`/`alterarQuantidadeItem()`/
 * `removerItem()` reavaliadas: compor os itens de uma
 * `RequisicaoCompraAdjudicacao` (que nasce `Ativa`, nunca `Rascunho` —
 * `StatusAdjudicacaoRequisicaoCompra` só tem Ativa/Cancelada) É a própria
 * decisão comercial em curso, não uma preparação de rascunho anterior a
 * uma decisão formal — a mesma gravidade de `cancelar()`/
 * `CriarAdjudicacaoRequisicaoCompra`, já hardened. "Não recebiam `User`"
 * deixou de ser aceito como justificativa (Fase 2E.CORREÇÃO, item 7): as
 * 3 assinaturas ganharam `User $usuario` (parâmetro novo, sempre por
 * último — nenhuma reordenação dos parâmetros já existentes) e passaram
 * a reafirmar, dentro da própria Action, a mesma `suprimentos.mapa|editar`
 * que o caller já checa — obra sempre derivada do RECURSO
 * (`$adjudicacao->requisicaoCompra()->value('obra_id')`), nunca da
 * sessão. Único caller de produção
 * (`⚡suprimentos.blade.php::adicionarItemAdjudicacao()`/
 * `removerItemAdjudicacao()`) já resolve `$adjudicacao`/`$item`
 * obra-scoped antes de chamar — `alterarQuantidadeItem()` não tem
 * nenhum caller de produção hoje (confirmado por grep), mas ganhou a
 * mesma defesa por pertencer à mesma família de operação.
 */
class AtualizarAdjudicacaoRequisicaoCompra
{
    public function adicionarItem(
        RequisicaoCompraAdjudicacao $adjudicacao,
        RequisicaoCompraItem $rcItem,
        ?RequisicaoCompraItemParcela $parcela,
        float $quantidade,
        User $usuario,
    ): RequisicaoCompraAdjudicacaoItem {
        GarantirAutoridadeNaObra::checar(
            $usuario,
            (string) $adjudicacao->requisicaoCompra()->value('obra_id'),
            'suprimentos.mapa',
            'editar',
            'Você não tem autoridade para compor esta Adjudicação.'
        );

        return DB::transaction(function () use ($adjudicacao, $rcItem, $parcela, $quantidade) {
            $rcItem = RequisicaoCompraItem::whereKey($rcItem->id)->lockForUpdate()->firstOrFail();
            RequisicaoCompra::whereKey($rcItem->requisicao_compra_id)->lockForUpdate()->firstOrFail();

            $parcelaTravada = $parcela
                ? RequisicaoCompraItemParcela::whereKey($parcela->id)->lockForUpdate()->firstOrFail()
                : null;

            $adjudicacao = RequisicaoCompraAdjudicacao::whereKey($adjudicacao->id)->lockForUpdate()->firstOrFail();

            $this->garantirAdjudicacaoAtiva($adjudicacao);
            $this->garantirMesmaRc($adjudicacao, $rcItem);
            if ($parcelaTravada) {
                $this->garantirParcelaDoItem($rcItem, $parcelaTravada);
            }
            $this->garantirGranularidadeCoerente($rcItem, $parcelaTravada);
            $this->garantirQuantidadePositiva($quantidade);
            $this->garantirLinhaInexistente($adjudicacao, $rcItem, $parcelaTravada);
            $this->validarSaldoAlvo($rcItem, $parcelaTravada, $quantidade, excluirId: null);

            return RequisicaoCompraAdjudicacaoItem::create([
                'requisicao_compra_adjudicacao_id' => $adjudicacao->id,
                'requisicao_compra_item_id' => $rcItem->id,
                'requisicao_compra_item_parcela_id' => $parcelaTravada?->id,
                'quantidade' => $quantidade,
            ]);
        });
    }

    public function alterarQuantidadeItem(RequisicaoCompraAdjudicacaoItem $item, float $novaQuantidade, User $usuario): void
    {
        GarantirAutoridadeNaObra::checar(
            $usuario,
            (string) $this->obraDaAdjudicacaoItem($item),
            'suprimentos.mapa',
            'editar',
            'Você não tem autoridade para alterar esta Adjudicação.'
        );

        DB::transaction(function () use ($item, $novaQuantidade) {
            $rcItem = RequisicaoCompraItem::whereKey($item->requisicao_compra_item_id)->lockForUpdate()->firstOrFail();
            RequisicaoCompra::whereKey($rcItem->requisicao_compra_id)->lockForUpdate()->firstOrFail();

            $parcelaTravada = $item->requisicao_compra_item_parcela_id
                ? RequisicaoCompraItemParcela::whereKey($item->requisicao_compra_item_parcela_id)->lockForUpdate()->firstOrFail()
                : null;

            $adjudicacao = RequisicaoCompraAdjudicacao::whereKey($item->requisicao_compra_adjudicacao_id)->lockForUpdate()->firstOrFail();
            $item = RequisicaoCompraAdjudicacaoItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $this->garantirAdjudicacaoAtiva($adjudicacao);
            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->garantirNaoAbaixoDoConsumidoPorPedido($item, $novaQuantidade);
            $this->validarSaldoAlvo($rcItem, $parcelaTravada, $novaQuantidade, excluirId: $item->id);

            $item->update(['quantidade' => $novaQuantidade]);
        });
    }

    public function removerItem(RequisicaoCompraAdjudicacaoItem $item, User $usuario): void
    {
        GarantirAutoridadeNaObra::checar(
            $usuario,
            (string) $this->obraDaAdjudicacaoItem($item),
            'suprimentos.mapa',
            'editar',
            'Você não tem autoridade para remover este item da Adjudicação.'
        );

        DB::transaction(function () use ($item) {
            $rcItem = RequisicaoCompraItem::whereKey($item->requisicao_compra_item_id)->lockForUpdate()->firstOrFail();
            RequisicaoCompra::whereKey($rcItem->requisicao_compra_id)->lockForUpdate()->firstOrFail();

            $parcelaTravada = $item->requisicao_compra_item_parcela_id
                ? RequisicaoCompraItemParcela::whereKey($item->requisicao_compra_item_parcela_id)->lockForUpdate()->firstOrFail()
                : null;

            $adjudicacao = RequisicaoCompraAdjudicacao::whereKey($item->requisicao_compra_adjudicacao_id)->lockForUpdate()->firstOrFail();
            $item = RequisicaoCompraAdjudicacaoItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $this->garantirSemConsumoPorPedido($item);

            $item->delete();
        });
    }

    /**
     * Cancela a decisão INTEIRA (todos os itens de uma vez) — só quando
     * NENHUM item tem consumo oficial de Pedido. Nunca trava a RC (ver
     * docblock da classe) — só os alvos (RCItem/Parcela) de cada item,
     * na mesma ordem relativa já usada por `adicionarItem()`.
     *
     * Fase 2E — defesa em profundidade: reafirma, DENTRO da Action, a
     * mesma capacidade `suprimentos.mapa|editar` que o caller
     * (`⚡suprimentos.blade.php::cancelarAdjudicacaoRc()`) já checa — obra
     * derivada de `requisicaoCompra()->obra_id` (o recurso, nunca a
     * sessão). Desde a Fase 2E.CORREÇÃO, `adicionarItem()`/
     * `alterarQuantidadeItem()`/`removerItem()` desta mesma classe
     * também recebem `User $usuario` e reafirmam a mesma checagem (ver
     * docblock da classe).
     */
    public function cancelar(RequisicaoCompraAdjudicacao $adjudicacao, User $usuario, ?string $motivo): void
    {
        GarantirAutoridadeNaObra::checar(
            $usuario,
            (string) $adjudicacao->requisicaoCompra()->value('obra_id'),
            'suprimentos.mapa',
            'editar',
            'Você não tem autoridade para cancelar esta Adjudicação.'
        );

        DB::transaction(function () use ($adjudicacao, $usuario, $motivo) {
            $itensBrutos = RequisicaoCompraAdjudicacaoItem::where('requisicao_compra_adjudicacao_id', $adjudicacao->id)->get();

            $rcItemIds = $itensBrutos->pluck('requisicao_compra_item_id')->unique()->sort()->values();
            $parcelaIds = $itensBrutos->pluck('requisicao_compra_item_parcela_id')->filter()->unique()->sort()->values();

            $rcItensTravados = $rcItemIds->isNotEmpty()
                ? RequisicaoCompraItem::whereIn('id', $rcItemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                : collect();

            $parcelasTravadas = $parcelaIds->isNotEmpty()
                ? RequisicaoCompraItemParcela::whereIn('id', $parcelaIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                : collect();

            $adjudicacao = RequisicaoCompraAdjudicacao::whereKey($adjudicacao->id)->lockForUpdate()->firstOrFail();
            $this->garantirAdjudicacaoAtiva($adjudicacao);

            $itensTravados = $itensBrutos->isNotEmpty()
                ? RequisicaoCompraAdjudicacaoItem::whereIn('id', $itensBrutos->pluck('id'))->orderBy('id')->lockForUpdate()->get()
                : collect();

            foreach ($itensTravados as $itemTravado) {
                $this->garantirSemConsumoPorPedido($itemTravado);
            }

            $adjudicacao->forceFill([
                'status' => StatusAdjudicacaoRequisicaoCompra::Cancelada,
                'cancelado_por_id' => $usuario->id,
                'cancelado_em' => now(),
                'motivo_cancelamento' => $motivo,
            ])->save();
        });
    }

    /** Deriva a obra do RECURSO (item → adjudicação → RC), nunca da sessão — 2 queries mínimas, sem eager-load presumido. */
    private function obraDaAdjudicacaoItem(RequisicaoCompraAdjudicacaoItem $item): ?string
    {
        $adjudicacao = RequisicaoCompraAdjudicacao::whereKey($item->requisicao_compra_adjudicacao_id)->firstOrFail();

        return $adjudicacao->requisicaoCompra()->value('obra_id');
    }

    private function garantirAdjudicacaoAtiva(RequisicaoCompraAdjudicacao $adjudicacao): void
    {
        if (! $adjudicacao->estaAtiva()) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'Esta adjudicação foi cancelada — registre uma nova adjudicação para uma decisão diferente.'
            );
        }
    }

    private function garantirMesmaRc(RequisicaoCompraAdjudicacao $adjudicacao, RequisicaoCompraItem $rcItem): void
    {
        if ($rcItem->requisicao_compra_id !== $adjudicacao->requisicao_compra_id) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'Este item não pertence à mesma Requisição de Compra desta adjudicação.'
            );
        }
    }

    private function garantirParcelaDoItem(RequisicaoCompraItem $rcItem, RequisicaoCompraItemParcela $parcela): void
    {
        if ($parcela->requisicao_compra_item_id !== $rcItem->id) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException('Esta parcela não pertence a este item da Requisição de Compra.');
        }
    }

    /**
     * Item já detalhado por Atividade (Etapa 1) SEMPRE exige adjudicação
     * por parcela; item nunca detalhado SEMPRE exige adjudicação direta
     * — nunca mistura os dois pro mesmo item (ver docblock da classe).
     */
    private function garantirGranularidadeCoerente(RequisicaoCompraItem $rcItem, ?RequisicaoCompraItemParcela $parcela): void
    {
        $temParcelas = $rcItem->parcelas()->exists();

        if ($temParcelas && ! $parcela) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'Este item já foi detalhado por Atividade — selecione a Atividade/parcela específica para adjudicar, nunca o item sem detalhamento.'
            );
        }

        if (! $temParcelas && $parcela) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'Este item nunca foi detalhado por Atividade — a adjudicação deve ser feita diretamente sobre o item.'
            );
        }
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException('A quantidade adjudicada precisa ser maior que zero.');
        }
    }

    /**
     * Defesa contra duplicidade dentro da MESMA adjudicação (a `UNIQUE`
     * da migration só protege de verdade o caso com parcela não-nula —
     * múltiplos `NULL` nunca colidem no MySQL — por isso esta checagem
     * explícita cobre os dois casos).
     */
    private function garantirLinhaInexistente(RequisicaoCompraAdjudicacao $adjudicacao, RequisicaoCompraItem $rcItem, ?RequisicaoCompraItemParcela $parcela): void
    {
        $existe = RequisicaoCompraAdjudicacaoItem::where('requisicao_compra_adjudicacao_id', $adjudicacao->id)
            ->where('requisicao_compra_item_id', $rcItem->id)
            ->where('requisicao_compra_item_parcela_id', $parcela?->id)
            ->exists();

        if ($existe) {
            throw new RequisicaoCompraAdjudicacaoInvalidaException(
                'Este item/atividade já está detalhado nesta adjudicação — edite a quantidade existente em vez de adicionar de novo.'
            );
        }
    }

    /** Guarda quantitativa (Seção 7 do pedido) — soma das adjudicações Ativas nunca excede a quantidade do alvo. */
    private function validarSaldoAlvo(RequisicaoCompraItem $rcItem, ?RequisicaoCompraItemParcela $parcela, float $quantidadeDesejada, ?string $excluirId): void
    {
        $saldo = $parcela ? $parcela->saldoAdjudicavel($excluirId) : $rcItem->saldoAdjudicavelSemParcela($excluirId);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            $alvo = $parcela ? 'detalhamento por Atividade' : 'item';

            throw new SaldoAdjudicacaoInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda não adjudicado ({$saldo}) deste {$alvo}.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }

    /**
     * Etapa 2.CORREÇÃO — soma PRECISA via a ponte explícita
     * (`quantidadeConsumidaViaBridge()`), nunca mais a agregação por
     * fornecedor (que não distingue entre 2 adjudicações do mesmo
     * fornecedor sobre o mesmo alvo).
     */
    private function garantirNaoAbaixoDoConsumidoPorPedido(RequisicaoCompraAdjudicacaoItem $item, float $novaQuantidade): void
    {
        $consumido = $item->quantidadeConsumidaViaBridge();

        if ($novaQuantidade < $consumido - 0.0005) {
            throw new AdjudicacaoConsumidaPorPedidoException(
                "Esta linha de adjudicação já tem {$consumido} atribuída explicitamente a Pedido(s) Emitido(s) — não é possível reduzir abaixo desse valor."
            );
        }
    }

    private function garantirSemConsumoPorPedido(RequisicaoCompraAdjudicacaoItem $item): void
    {
        $consumido = $item->quantidadeConsumidaViaBridge();

        if ($consumido > 0.0005) {
            throw new AdjudicacaoConsumidaPorPedidoException(
                "Esta linha de adjudicação já tem {$consumido} atribuída explicitamente a Pedido(s) Emitido(s) — não é possível removê-la/cancelá-la. Crie uma nova adjudicação para uma decisão diferente, se necessário."
            );
        }
    }
}
