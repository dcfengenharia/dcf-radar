<?php

use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Enums\SerieAvanco;
use App\Enums\StatusItemSuprimento;
use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRequisicaoCompra;
use App\Exports\SuprimentosExport;
use App\Models\Atividade;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapa;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\PacoteEngenharia;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use App\Support\SincronizarRestricaoSuprimento;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
  use ExecutaComTransacaoSegura;

  public Work $obra;

  // ---- Filtros ----
  public string $search = '';
  public ?string $fluxoIdFiltro = null;
  public ?string $fornecedorIdFiltro = null;
  public ?string $statusFiltro = null;
  public ?string $disciplinaIdFiltro = null;
  public ?string $frenteTrabalhoIdFiltro = null;
  public ?string $faturamentoDiretoFiltro = null; // '' = todos | '1' = sim | '0' = não
  public ?string $entregavelIdFiltro = null;
  public ?string $equipeResponsavelIdFiltro = null;
  public ?string $personalizado1IdFiltro = null;
  public ?string $personalizado2IdFiltro = null;
  public ?string $personalizado3IdFiltro = null;
  public ?string $personalizado4IdFiltro = null;
  public ?string $personalizado5IdFiltro = null;
  public string $necessidadeDe = '';
  public string $necessidadeAte = '';

  // ---- Modal detalhe (ver etapas / lançar Realizado) ----
  public ?string $itemDetalheId = null;
  public array $realizadoForm = [];
  public string $comentarioNovo = '';

  // ---- Modal atividades vinculadas ----
  public ?string $atividadesModalItemId = null;

  // ---- Modal criar/editar item ----
  public bool $modalItemAberto = false;
  public ?string $editandoItemId = null;
  public array $atividadesIdsNovo = [];
  public string $buscaAtividadeNovo = '';
  public ?string $fluxoIdNovo = null;
  public ?string $fornecedorIdNovo = null;
  public array $documentosIdsNovo = [];
  public ?string $pacoteEngenhariaFiltroNovo = null;
  public string $buscaDocumentoNovo = '';
  public string $nomeNovo = '';
  public string $codigoNovo = '';
  public string $observacoesNovo = '';

  // ---- Ciclo 19, Etapa 19.4 — modal Requisições de Compra (RC) ----
  public ?string $rcPacoteId = null;
  public ?string $rcDetalheId = null;
  public ?string $rcFluxoIdNovo = null;
  public string $rcObservacaoNovo = '';
  public ?string $rcItemAlocacaoIdNovo = null;
  public string $rcItemQuantidadeNovo = '';

  // ---- Ciclo 19, Etapa 19.5 — Pedidos/Ordens de Compra (dentro do detalhe da RC) ----
  public ?string $pedidoDetalheId = null;
  public ?string $pedidoFornecedorIdNovo = null;
  public string $pedidoDataPrevistaEntregaNovo = '';
  public string $pedidoNumeroContratoNovo = '';
  public string $pedidoDataContratoNovo = '';
  public string $pedidoObservacaoNovo = '';
  public ?string $pedidoItemRcItemIdNovo = null;
  public string $pedidoItemQuantidadeNovo = '';

  // ---- Ciclo 19, Etapa 19.6 — Recebimento Físico (dentro do item do Pedido) ----
  public ?string $recebimentoItemAbertoId = null;
  public string $recebimentoQuantidadeNova = '';
  public string $recebimentoDataNova = '';
  public string $recebimentoLocalNova = '';
  public string $recebimentoObservacaoNova = '';

  public function mount(Work $obra): void
  {
    $this->obra = $obra;
  }

  private function garantirPermissao(string $acao): void
  {
    abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'suprimentos.mapa', $acao), 403);
  }

  // =========================================================================
  // Ciclo 19, Etapa 19.4.CORREÇÃO, item 8 — resolvers obra-scoped (mesmo
  // padrão de resolverGrdDaObraAtual()/resolverDestinatarioDaObraAtual()
  // já usado em ⚡grds.blade.php): NENHUM id vindo de propriedade pública
  // Livewire ($rcPacoteId/$rcDetalheId/etc.) é confiável — a página é
  // /app/suprimentos/{obra}, e todo recurso manipulado por ela precisa
  // pertencer à MESMA obra, sempre reconfirmado server-side, nunca só
  // pela permissão (que checa $this->obra->id, não o recurso em si).
  // =========================================================================

  private function resolverPacoteDaObraAtual(string $id): ItemSuprimento
  {
    return ItemSuprimento::where('obra_id', $this->obra->id)->findOrFail($id);
  }

  private function resolverRcDaObraAtual(string $id): \App\Models\RequisicaoCompra
  {
    return \App\Models\RequisicaoCompra::where('obra_id', $this->obra->id)->findOrFail($id);
  }

  private function resolverAlocacaoDaObraAtual(string $id): \App\Models\AlocacaoRequisicaoPacote
  {
    return \App\Models\AlocacaoRequisicaoPacote::whereHas('pacote', fn ($q) => $q->where('obra_id', $this->obra->id))
      ->findOrFail($id);
  }

  private function resolverItemRcDaObraAtual(string $id): \App\Models\RequisicaoCompraItem
  {
    return \App\Models\RequisicaoCompraItem::whereHas('requisicaoCompra', fn ($q) => $q->where('obra_id', $this->obra->id))
      ->findOrFail($id);
  }

  private function resolverEtapaRcDaObraAtual(string $id): \App\Models\RequisicaoCompraEtapa
  {
    return \App\Models\RequisicaoCompraEtapa::whereHas('requisicaoCompra', fn ($q) => $q->where('obra_id', $this->obra->id))
      ->findOrFail($id);
  }

  // ---- Ciclo 19, Etapa 19.5 — mesma disciplina de resolvers obra-scoped ----

  private function resolverRcItemDaObraAtual(string $id): \App\Models\RequisicaoCompraItem
  {
    return \App\Models\RequisicaoCompraItem::whereHas('requisicaoCompra', fn ($q) => $q->where('obra_id', $this->obra->id))
      ->findOrFail($id);
  }

  private function resolverFornecedorDaObraAtual(string $id): Fornecedor
  {
    return Fornecedor::where('obra_id', $this->obra->id)->findOrFail($id);
  }

  private function resolverPedidoDaObraAtual(string $id): \App\Models\PedidoCompra
  {
    return \App\Models\PedidoCompra::where('obra_id', $this->obra->id)->findOrFail($id);
  }

  private function resolverItemPedidoDaObraAtual(string $id): \App\Models\PedidoCompraItem
  {
    return \App\Models\PedidoCompraItem::whereHas('pedidoCompra', fn ($q) => $q->where('obra_id', $this->obra->id))
      ->findOrFail($id);
  }

  // =========================================================================
  // COMPUTED — DADOS DE REFERÊNCIA
  // =========================================================================

  #[Computed]
  public function fluxos(): \Illuminate\Support\Collection
  {
    return FluxoSuprimento::where('ativo', true)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function fornecedores(): \Illuminate\Support\Collection
  {
    return Fornecedor::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function disciplinas(): \Illuminate\Support\Collection
  {
    return Disciplina::orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function frentesTrabalho(): \Illuminate\Support\Collection
  {
    return FrenteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function entregaveis(): \Illuminate\Support\Collection
  {
    return Entregavel::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function equipesResponsaveis(): \Illuminate\Support\Collection
  {
    return EquipeResponsavel::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados1(): \Illuminate\Support\Collection
  {
    return Personalizado1::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados2(): \Illuminate\Support\Collection
  {
    return Personalizado2::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados3(): \Illuminate\Support\Collection
  {
    return Personalizado3::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados4(): \Illuminate\Support\Collection
  {
    return Personalizado4::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados5(): \Illuminate\Support\Collection
  {
    return Personalizado5::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function pacotesEngenharia(): \Illuminate\Support\Collection
  {
    return PacoteEngenharia::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  // =========================================================================
  // ÁRVORE PRINCIPAL: Pacote → Atividade → Item de Suprimento
  // =========================================================================

  public function temFiltrosAtivos(): bool
  {
    return (bool) ($this->search ||
      $this->fluxoIdFiltro ||
      $this->fornecedorIdFiltro ||
      $this->statusFiltro ||
      $this->disciplinaIdFiltro ||
      $this->frenteTrabalhoIdFiltro ||
      ($this->faturamentoDiretoFiltro !== null && $this->faturamentoDiretoFiltro !== '') ||
      $this->entregavelIdFiltro ||
      $this->equipeResponsavelIdFiltro ||
      $this->personalizado1IdFiltro ||
      $this->personalizado2IdFiltro ||
      $this->personalizado3IdFiltro ||
      $this->personalizado4IdFiltro ||
      $this->personalizado5IdFiltro ||
      $this->necessidadeDe ||
      $this->necessidadeAte);
  }

  /**
   * Lista plana de itens de suprimento (pacotes de compra) que batem com
   * os filtros ativos — sem hierarquia de cronograma/EAP, só o que já foi
   * cadastrado aqui, ordenado pelo mais urgente primeiro (necessidade
   * mais próxima; sem necessidade definida vai pro final).
   */
  #[Computed]
  public function itensFiltrados(): \Illuminate\Support\Collection
  {
    $itens = ItemSuprimento::where('obra_id', $this->obra->id)
      ->with([
        'fluxo:id,nome',
        'fornecedor:id,nome',
        'atividades:id,nome,disciplina_id,frente_trabalho_id,inicio_planejado,data_termino,faturamento_direto,entregavel_id,equipe_responsavel_id,personalizado_1_id,personalizado_2_id,personalizado_3_id,personalizado_4_id,personalizado_5_id',
        'etapas.datas',
      ])
      ->withCount('comentarios')
      ->when($this->fluxoIdFiltro, fn($q) => $q->where('fluxo_suprimento_id', $this->fluxoIdFiltro))
      ->when($this->fornecedorIdFiltro, fn($q) => $q->where('fornecedor_id', $this->fornecedorIdFiltro))
      ->when($this->statusFiltro, fn($q) => $q->where('status', $this->statusFiltro))
      ->when(
        $this->search,
        fn($q) => $q->where(
          fn($qq) => $qq->where('nome', 'like', "%{$this->search}%")->orWhere('codigo', 'like', "%{$this->search}%")
        )
      )
      ->get();

    return $itens
      ->filter(function (ItemSuprimento $item) {
        if ($this->disciplinaIdFiltro && !$item->atividades->contains('disciplina_id', $this->disciplinaIdFiltro)) {
          return false;
        }
        if ($this->frenteTrabalhoIdFiltro && !$item->atividades->contains('frente_trabalho_id', $this->frenteTrabalhoIdFiltro)) {
          return false;
        }
        if ($this->faturamentoDiretoFiltro !== null && $this->faturamentoDiretoFiltro !== ''
            && !$item->atividades->contains('faturamento_direto', $this->faturamentoDiretoFiltro === '1')) {
          return false;
        }
        if ($this->entregavelIdFiltro && !$item->atividades->contains('entregavel_id', $this->entregavelIdFiltro)) {
          return false;
        }
        if ($this->equipeResponsavelIdFiltro && !$item->atividades->contains('equipe_responsavel_id', $this->equipeResponsavelIdFiltro)) {
          return false;
        }
        if ($this->personalizado1IdFiltro && !$item->atividades->contains('personalizado_1_id', $this->personalizado1IdFiltro)) {
          return false;
        }
        if ($this->personalizado2IdFiltro && !$item->atividades->contains('personalizado_2_id', $this->personalizado2IdFiltro)) {
          return false;
        }
        if ($this->personalizado3IdFiltro && !$item->atividades->contains('personalizado_3_id', $this->personalizado3IdFiltro)) {
          return false;
        }
        if ($this->personalizado4IdFiltro && !$item->atividades->contains('personalizado_4_id', $this->personalizado4IdFiltro)) {
          return false;
        }
        if ($this->personalizado5IdFiltro && !$item->atividades->contains('personalizado_5_id', $this->personalizado5IdFiltro)) {
          return false;
        }

        $necessidade = $item->necessidade();
        if ($this->necessidadeDe && (!$necessidade || $necessidade->lt(Carbon::parse($this->necessidadeDe)))) {
          return false;
        }
        if ($this->necessidadeAte && (!$necessidade || $necessidade->gt(Carbon::parse($this->necessidadeAte)))) {
          return false;
        }

        return true;
      })
      ->sortBy(fn(ItemSuprimento $item) => $item->necessidade()?->timestamp ?? PHP_INT_MAX)
      ->values();
  }

  /**
   * Ciclo 19, Etapa 19.3 — leitura SOMENTE (seção 27 do pedido): quantas
   * RPs/RPItens estão alocados a cada Pacote da página atual. 1 query em
   * lote (join + GROUP BY) pra toda a listagem, nunca 1 por Pacote —
   * o Mapa nunca emite/edita alocação, só lê (link pro Pacote::detalhe,
   * que sim permite alocar/desalocar).
   */
  #[Computed]
  public function alocacoesPorPacote(): \Illuminate\Support\Collection
  {
    $ids = $this->itensFiltrados->pluck('id')->all();
    if (empty($ids)) {
      return collect();
    }

    return \App\Models\AlocacaoRequisicaoPacote::query()
      ->whereIn('alocacoes_requisicao_pacote.item_suprimento_id', $ids)
      ->join('requisicao_planejamento_itens', 'requisicao_planejamento_itens.id', '=', 'alocacoes_requisicao_pacote.requisicao_planejamento_item_id')
      ->selectRaw('alocacoes_requisicao_pacote.item_suprimento_id, COUNT(DISTINCT requisicao_planejamento_itens.requisicao_planejamento_id) as total_rps, COUNT(*) as total_rp_itens')
      ->groupBy('alocacoes_requisicao_pacote.item_suprimento_id')
      ->get()
      ->keyBy('item_suprimento_id');
  }

  /**
   * Seção "Demanda do Planejamento" do modal de detalhe (seção 28) — só
   * leitura, escopada ao ÚNICO Pacote aberto (nunca N+1 real: 1 query
   * por abertura de modal, não por linha da listagem).
   */
  #[Computed]
  public function alocacoesDoPacoteAberto(): \Illuminate\Support\Collection
  {
    if (! $this->itemDetalheId) {
      return collect();
    }

    return \App\Models\AlocacaoRequisicaoPacote::query()
      ->where('item_suprimento_id', $this->itemDetalheId)
      ->with([
        'requisicaoItem.requisicao',
        'requisicaoItem.itemTakeOff.unidadeMedida',
        'requisicaoItem.itemTakeOff.lista.revisao.documento',
      ])
      ->get();
  }

  /**
   * Ciclo 19, Etapa 19.6, seção 31 — fecha o D pendente da 19.5: leitura
   * agregada de atendimento/recebimento físico do Pacote com o detalhe
   * aberto. Delega 100% pra `ConciliacaoRecebimento::porPacote()` (zero
   * regra nova aqui) — nunca soma unidades incompatíveis, só contagem de
   * itens.
   */
  #[Computed]
  public function atendimentoFisicoPacoteAberto(): ?array
  {
    if (! $this->itemDetalheId) {
      return null;
    }

    return \App\Support\Suprimentos\ConciliacaoRecebimento::porPacote($this->itemDetalhe);
  }

  /**
   * Ciclo 19, Etapa 19.7, seção 35 — quantas Restrições automáticas
   * ABERTAS a cadeia formal já criou pra este Pacote (origem
   * `origem_cadeia_suprimento_id`, nunca `origem_suprimento_item_id`
   * do mecanismo legado). Puramente leitura — nunca cria/resolve nada
   * aqui, isso é exclusividade de SincronizarRestricaoCadeiaSuprimento.
   */
  #[Computed]
  public function restricoesAutomaticasAbertasPacoteAberto(): int
  {
    if (! $this->itemDetalheId) {
      return 0;
    }

    return \App\Models\Restricao::where('origem_cadeia_suprimento_id', $this->itemDetalheId)
      ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros'])
      ->count();
  }

  /**
   * Ciclo 19, Etapa 19.4 — Requisições de Compra do Pacote com o modal
   * de RC aberto. Domínio novo e paralelo ao mecanismo legado (etapas()/
   * status via SuprimentoScheduler, seção acima) — nunca sincroniza.
   */
  #[Computed]
  public function requisicoesCompraDoPacoteAberto(): \Illuminate\Support\Collection
  {
    if (! $this->rcPacoteId) {
      return collect();
    }

    // Ciclo 19, Etapa 19.4.CORREÇÃO, item 8 — reescopado por obra_id (o
    // Pacote da RC pode não pertencer a esta obra se rcPacoteId tiver
    // sido manipulado); leitura nunca vaza dado de outra obra.
    return \App\Models\RequisicaoCompra::where('obra_id', $this->obra->id)
      ->where('item_suprimento_id', $this->rcPacoteId)
      ->with('etapas')
      ->orderByDesc('created_at')
      ->get();
  }

  #[Computed]
  public function rcAberta(): ?\App\Models\RequisicaoCompra
  {
    if (! $this->rcDetalheId) {
      return null;
    }

    return \App\Models\RequisicaoCompra::where('obra_id', $this->obra->id)
      ->where('id', $this->rcDetalheId)
      ->with(['itens.alocacao.requisicaoItem.itemTakeOff.unidadeMedida', 'etapas.realizadaPor', 'fluxo'])
      ->first();
  }

  /**
   * Ciclo 19, Etapa 19.4.CORREÇÃO — saldo OFICIAL (só RC Emitida/
   * Concluida, nunca Rascunho — mesma fonte única de
   * AlocacaoRequisicaoPacote::quantidadeConsumidaOficialPorRc(), aqui
   * em versão em LOTE pra evitar N+1 por alocação). Reescopado por
   * obra_id (item 8) — o Pacote nunca é lido de outra obra.
   */
  #[Computed]
  public function alocacoesComSaldoParaRc(): \Illuminate\Support\Collection
  {
    if (! $this->rcPacoteId) {
      return collect();
    }

    $alocacoes = \App\Models\AlocacaoRequisicaoPacote::whereHas('pacote', fn ($q) => $q->where('obra_id', $this->obra->id))
      ->where('item_suprimento_id', $this->rcPacoteId)
      ->with('requisicaoItem.itemTakeOff.unidadeMedida')
      ->get();

    $consumidoOficial = \App\Models\RequisicaoCompraItem::whereIn('alocacao_requisicao_pacote_id', $alocacoes->pluck('id'))
      ->whereHas('requisicaoCompra', fn ($q) => $q->whereIn('status', [
        StatusRequisicaoCompra::Emitida->value,
        StatusRequisicaoCompra::Concluida->value,
      ]))
      ->groupBy('alocacao_requisicao_pacote_id')
      ->selectRaw('alocacao_requisicao_pacote_id, SUM(quantidade) as total')
      ->pluck('total', 'alocacao_requisicao_pacote_id');

    return $alocacoes->map(function ($alocacao) use ($consumidoOficial) {
      $alocacao->saldo_para_rc = round((float) $alocacao->quantidade_alocada - (float) ($consumidoOficial[$alocacao->id] ?? 0), 3);
      return $alocacao;
    })->filter(fn ($a) => $a->saldo_para_rc > 0)->values();
  }

  // =========================================================================
  // Ciclo 19, Etapa 19.5 — Pedidos/Ordens de Compra (dentro do detalhe da RC)
  // =========================================================================

  /**
   * Ciclo 19, Etapa 19.6, seção 34 — leitura resumida do recebimento
   * (previsto/recebido/saldo/situação) por Pedido, direto na lista da RC
   * — Planejamento nunca muta recebimento aqui, só lê (mesmo mecanismo de
   * `ConciliacaoRecebimento::porPedido()`, cardinalidade tipicamente
   * pequena — poucos Pedidos por RC — mesma classe de custo já aceita
   * pra listas de detalhe deste tamanho no projeto).
   */
  #[Computed]
  public function pedidosDaRcAberta(): \Illuminate\Support\Collection
  {
    if (! $this->rcDetalheId) {
      return collect();
    }

    return \App\Models\PedidoCompra::where('obra_id', $this->obra->id)
      ->where('requisicao_compra_id', $this->rcDetalheId)
      ->with(['fornecedor', 'itens'])
      ->orderByDesc('created_at')
      ->get()
      ->map(function (\App\Models\PedidoCompra $pedido) {
        $pedido->resumo_entrega = $pedido->status->value === 'emitido'
          ? \App\Support\Suprimentos\ConciliacaoRecebimento::porPedido($pedido)
          : null;
        return $pedido;
      });
  }

  #[Computed]
  public function pedidoAberto(): ?\App\Models\PedidoCompra
  {
    if (! $this->pedidoDetalheId) {
      return null;
    }

    return \App\Models\PedidoCompra::where('obra_id', $this->obra->id)
      ->where('id', $this->pedidoDetalheId)
      ->with(['itens.requisicaoCompraItem', 'itens.recebimentos.registradoPor', 'fornecedor', 'requisicaoCompra'])
      ->first();
  }

  /**
   * Itens da RC com saldo OFICIAL > 0 pra Pedido (mesma filosofia de
   * `alocacoesComSaldoParaRc()`, uma camada acima) — nunca N+1: 1 query
   * em lote por abertura do detalhe da RC.
   */
  #[Computed]
  public function rcItensComSaldoParaPedido(): \Illuminate\Support\Collection
  {
    if (! $this->rcDetalheId) {
      return collect();
    }

    $rcItens = \App\Models\RequisicaoCompraItem::whereHas('requisicaoCompra', fn ($q) => $q->where('obra_id', $this->obra->id))
      ->where('requisicao_compra_id', $this->rcDetalheId)
      ->get();

    $consumidoOficial = \App\Models\PedidoCompraItem::whereIn('requisicao_compra_item_id', $rcItens->pluck('id'))
      ->whereHas('pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido->value))
      ->groupBy('requisicao_compra_item_id')
      ->selectRaw('requisicao_compra_item_id, SUM(quantidade_pedida) as total')
      ->pluck('total', 'requisicao_compra_item_id');

    return $rcItens->map(function ($rcItem) use ($consumidoOficial) {
      $rcItem->saldo_para_pedido = round((float) $rcItem->quantidade - (float) ($consumidoOficial[$rcItem->id] ?? 0), 3);
      return $rcItem;
    })->filter(fn ($i) => $i->saldo_para_pedido > 0)->values();
  }

  #[Computed]
  public function totais(): array
  {
    $unicos = $this->itensFiltrados->unique('id');

    return [
      'total' => $unicos->count(),
      'em_risco' => $unicos->where('status', StatusItemSuprimento::EmRisco)->count(),
      'atrasado' => $unicos->where('status', StatusItemSuprimento::Atrasado)->count(),
      'concluido' => $unicos->where('status', StatusItemSuprimento::Concluido)->count(),
    ];
  }

  public function badgeStatus(StatusItemSuprimento $status): string
  {
    return match ($status) {
      StatusItemSuprimento::NoInicio => 'bg-label-secondary',
      StatusItemSuprimento::EmAndamento => 'bg-label-info',
      StatusItemSuprimento::EmRisco => 'bg-label-warning',
      StatusItemSuprimento::Atrasado => 'bg-label-danger',
      StatusItemSuprimento::Concluido => 'bg-label-success',
    };
  }

  /**
   * Badge simplificada da lista — só 3 estados (o Farol + a cor da
   * Tendência já carregam o detalhe de "em risco"; a badge é só uma
   * leitura rápida de "tá bem, atrasou ou já acabou").
   */
  public function statusSimplificadoLabel(StatusItemSuprimento $status): string
  {
    return match ($status) {
      StatusItemSuprimento::Atrasado => 'Atrasado',
      StatusItemSuprimento::Concluido => 'Concluído',
      default => 'Em dia',
    };
  }

  public function statusSimplificadoClasse(StatusItemSuprimento $status): string
  {
    return match ($status) {
      StatusItemSuprimento::Atrasado => 'bg-label-danger',
      StatusItemSuprimento::Concluido => 'bg-label-success',
      default => 'bg-label-info',
    };
  }

  /**
   * Cor da barra de progresso da lista — mesma lógica de cor do
   * status do item (não das etapas individuais), pra bater com a
   * badge ao lado.
   */
  public function corBarraProgresso(StatusItemSuprimento $status): string
  {
    return match ($status) {
      StatusItemSuprimento::Atrasado => 'bg-danger',
      StatusItemSuprimento::EmRisco => 'bg-warning',
      StatusItemSuprimento::Concluido => 'bg-success',
      default => 'bg-primary',
    };
  }

  public function desvioDias(ItemSuprimento $item): ?int
  {
    $ultimaEtapa = $item->etapas->last();
    $necessidade = $item->necessidade();
    $tendenciaFinal = $ultimaEtapa?->tendencia()?->data;

    if (!$tendenciaFinal || !$necessidade) {
      return null;
    }

    return (int) round(($tendenciaFinal->copy()->startOfDay()->timestamp - $necessidade->copy()->startOfDay()->timestamp) / 86400);
  }

  /**
   * Farol tipo "carinha", igual a planilha do usuário — leitura rápida
   * do andamento da compra em relação à necessidade da obra.
   */
  public function farolEmoji(StatusItemSuprimento $status): string
  {
    return match ($status) {
      StatusItemSuprimento::NoInicio => '⏳',
      StatusItemSuprimento::EmAndamento => '🙂',
      StatusItemSuprimento::EmRisco => '😐',
      StatusItemSuprimento::Atrasado => '🙁',
      StatusItemSuprimento::Concluido => '✅',
    };
  }

  /**
   * Estado visual da etapa (cor de fundo da célula "Data" na tabela
   * larga — igual ao esquema de cores da planilha): concluído
   * (Realizado preenchido), atrasado (Tendência já no passado, sem
   * Realizado), em risco (Tendência cai dentro dos próximos 5 dias
   * úteis — folga curta, chamar atenção antes de virar atraso de
   * verdade), pendente (dentro do prazo, folga confortável), ou não
   * aplicável (etapa marcada como pulada pra este item).
   */
  public function estadoEtapa(?ItemSuprimentoEtapa $etapa): string
  {
    if (!$etapa || $etapa->nao_aplicavel) {
      return 'na';
    }
    if ($etapa->realizado()) {
      return 'concluido';
    }

    $tendencia = $etapa->tendencia()?->data;
    if (!$tendencia) {
      return 'pendente';
    }

    $hoje = Carbon::today();
    if ($tendencia->lt($hoje)) {
      return 'atrasado';
    }

    $calc = \App\Support\DiasUteisCalculator::paraObra($this->obra);
    if ($tendencia->lte($calc->somar($hoje, 5))) {
      return 'risco';
    }

    return 'pendente';
  }

  /**
   * Classes Bootstrap de cor de fundo/texto por estado de etapa —
   * célula colorida de verdade (não só um ícone), igual à planilha.
   */
  public function corEstadoEtapa(string $estado): string
  {
    return match ($estado) {
      'concluido' => 'bg-success-subtle',
      'atrasado' => 'bg-danger text-white fw-semibold',
      'risco' => 'bg-warning-subtle',
      'na' => 'bg-light text-muted',
      default => '',
    };
  }

  /**
   * Monta uma linha por etapa pra timeline vertical do modal de detalhe:
   * decide qual é a "próxima etapa" em destaque (a primeira ainda
   * pendente, sem nada de alarmante) e resolve a data/autor exibidos —
   * Realizado + quem deu baixa quando concluída, senão a Tendência.
   */
  public function linhasTimelineEtapas(ItemSuprimento $item): array
  {
    $jaDestacouProxima = false;
    $linhas = [];

    foreach ($item->etapas as $etapa) {
      $estado = $this->estadoEtapa($etapa);
      $destacada = false;

      if ($estado === 'pendente' && !$jaDestacouProxima) {
        $destacada = true;
        $jaDestacouProxima = true;
      }

      $linhas[] = [
        'etapa' => $etapa,
        'estado' => $estado,
        'destacada' => $destacada,
        'data' => $estado === 'concluido' ? $etapa->realizado()?->data : $etapa->tendencia()?->data,
        'autor' => $estado === 'concluido' ? $etapa->realizado()?->autor : null,
      ];
    }

    return $linhas;
  }

  /**
   * Cor de fundo do círculo da timeline — a etapa pendente "em destaque"
   * (a próxima da fila) ganha a cor de "atual", igual ao pino azul da
   * referência visual do usuário.
   */
  public function corCirculoEtapa(string $estado, bool $destacada = false): string
  {
    if ($estado === 'pendente' && $destacada) {
      return 'bg-primary text-white';
    }

    return match ($estado) {
      'concluido' => 'bg-success text-white',
      'atrasado' => 'bg-danger text-white',
      'risco' => 'bg-warning',
      'na' => 'bg-light text-muted border border-secondary-subtle',
      default => 'bg-light text-muted border',
    };
  }

  public function corTextoEtapa(string $estado, bool $destacada = false): string
  {
    if ($estado === 'pendente' && $destacada) {
      return 'text-primary';
    }

    return match ($estado) {
      'concluido' => 'text-success',
      'atrasado' => 'text-danger',
      'risco' => 'text-warning',
      default => 'text-muted',
    };
  }

  /**
   * Ícone do círculo da timeline — null significa "sem ícone" (etapa
   * futura, ainda sem nada de especial a sinalizar).
   */
  public function iconeCirculoEtapa(string $estado, bool $destacada = false): ?string
  {
    if ($estado === 'pendente' && $destacada) {
      return 'bx bxs-map-pin';
    }

    return match ($estado) {
      'concluido' => 'bx bx-check',
      'atrasado' => 'bx bx-x',
      'risco' => 'bx bx-error',
      'na' => 'bx bx-minus',
      default => null,
    };
  }

  /**
   * Texto em destaque (negrito) de cada linha da timeline — quem deu
   * baixa quando a etapa foi concluída (igual ao nome de responsável na
   * referência visual), senão uma descrição curta do estado atual.
   */
  public function tituloLinhaTimeline(array $linha): string
  {
    if ($linha['estado'] === 'concluido') {
      $autor = $linha['autor'];
      return $autor ? trim("{$autor->first_name} {$autor->last_name}") : 'Concluído';
    }

    return match (true) {
      $linha['estado'] === 'atrasado' => 'Atrasado',
      $linha['estado'] === 'risco' => 'Em risco',
      $linha['estado'] === 'na' => 'Não aplicável',
      $linha['destacada'] => 'Próxima etapa',
      default => 'Aguardando',
    };
  }

  public function limparFiltros(): void
  {
    $this->search = '';
    $this->fluxoIdFiltro = null;
    $this->fornecedorIdFiltro = null;
    $this->statusFiltro = null;
    $this->disciplinaIdFiltro = null;
    $this->frenteTrabalhoIdFiltro = null;
    $this->faturamentoDiretoFiltro = null;
    $this->entregavelIdFiltro = null;
    $this->equipeResponsavelIdFiltro = null;
    $this->personalizado1IdFiltro = null;
    $this->personalizado2IdFiltro = null;
    $this->personalizado3IdFiltro = null;
    $this->personalizado4IdFiltro = null;
    $this->personalizado5IdFiltro = null;
    $this->necessidadeDe = '';
    $this->necessidadeAte = '';
  }

  // =========================================================================
  // MODAL ATIVIDADES VINCULADAS
  // =========================================================================

  #[Computed]
  public function itemParaModalAtividades(): ?ItemSuprimento
  {
    if (!$this->atividadesModalItemId) {
      return null;
    }

    return ItemSuprimento::with('atividades')->find($this->atividadesModalItemId);
  }

  public function abrirAtividadesVinculadas(string $itemId): void
  {
    $this->atividadesModalItemId = $itemId;
  }

  public function fecharAtividadesVinculadas(): void
  {
    $this->atividadesModalItemId = null;
  }

  // =========================================================================
  // MODAL DETALHE (etapas P/T/R)
  // =========================================================================

  #[Computed]
  public function itemDetalhe(): ?ItemSuprimento
  {
    if (!$this->itemDetalheId) {
      return null;
    }

    return ItemSuprimento::with(['etapas.datas.autor', 'fluxo', 'fornecedor', 'atividades', 'documentosEngenharia.statusDocumento', 'comentarios.autor'])->find($this->itemDetalheId);
  }

  /**
   * Progresso do "histórico" de etapas pro visual em stepper do modal de
   * detalhe: conta só etapas aplicáveis (ignora as marcadas N/A pra este
   * item) — o avanço reflete quantas dessas já têm Realizado preenchido.
   */
  public function progressoEtapas(ItemSuprimento $item): array
  {
    $aplicaveis = $item->etapas->reject(fn(ItemSuprimentoEtapa $e) => $e->nao_aplicavel);
    $total = $aplicaveis->count();
    $concluidas = $aplicaveis->filter(fn(ItemSuprimentoEtapa $e) => (bool) $e->realizado())->count();

    return [
      'total' => $total,
      'concluidas' => $concluidas,
      'percentual' => $total > 0 ? (int) round(($concluidas / $total) * 100) : 0,
    ];
  }

  public function abrirDetalhe(string $itemId): void
  {
    $this->itemDetalheId = $itemId;
    $this->comentarioNovo = '';
    unset($this->itemDetalhe);

    $item = $this->itemDetalhe;
    $this->realizadoForm = $item
      ? $item->etapas->mapWithKeys(fn($e) => [$e->id => $e->realizado()?->data?->format('Y-m-d') ?? ''])->all()
      : [];
  }

  public function fecharDetalhe(): void
  {
    $this->itemDetalheId = null;
    $this->realizadoForm = [];
    $this->comentarioNovo = '';
  }

  public function adicionarComentario(): void
  {
    $this->garantirPermissao('editar');

    $this->validate(['comentarioNovo' => 'required|string|min:2'], [], ['comentarioNovo' => 'comentário']);

    $item = ItemSuprimento::findOrFail($this->itemDetalheId);

    $this->transacaoSegura(
      fn() => $item->comentarios()->create(['autor_id' => Auth::id(), 'comentario' => $this->comentarioNovo])
    );

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->comentarioNovo = '';
    unset($this->itemDetalhe, $this->itensFiltrados);
  }

  public function salvarRealizado(): void
  {
    $this->garantirPermissao('editar');

    $item = ItemSuprimento::with('etapas.datas')->findOrFail($this->itemDetalheId);

    $this->transacaoSegura(function () use ($item) {
      foreach ($item->etapas as $etapa) {
        $valor = $this->realizadoForm[$etapa->id] ?? '';

        if ($valor === '') {
          ItemSuprimentoEtapaData::where('item_suprimento_etapa_id', $etapa->id)->where('serie', SerieAvanco::Realizado->value)->delete();
          continue;
        }

        ItemSuprimentoEtapaData::updateOrCreate(
          ['item_suprimento_etapa_id' => $etapa->id, 'serie' => SerieAvanco::Realizado->value],
          ['tenant_id' => $item->tenant_id, 'data' => $valor, 'atualizado_por' => Auth::id()]
        );
      }

      SincronizarRestricaoSuprimento::sincronizarItem($item->fresh(['atividades']), Auth::id());
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    unset($this->itensFiltrados, $this->totais, $this->itemDetalhe);
    $this->abrirDetalhe($item->id);
    $this->dispatch('show-toast', message: 'Datas realizadas atualizadas.');
  }

  // =========================================================================
  // MODAL CRIAR / EDITAR ITEM
  // =========================================================================

  #[Computed]
  public function atividadesParaSelecao(): \Illuminate\Support\Collection
  {
    return Atividade::where('obra_id', $this->obra->id)
      ->where('fora_do_cronograma', false)
      ->when($this->buscaAtividadeNovo, fn($q) => $q->where('nome', 'like', "%{$this->buscaAtividadeNovo}%"))
      ->orderBy('nome')
      ->limit(30)
      ->get(['id', 'nome', 'inicio_planejado']);
  }

  #[Computed]
  public function atividadesSelecionadasNovo(): \Illuminate\Support\Collection
  {
    if (empty($this->atividadesIdsNovo)) {
      return collect();
    }
    return Atividade::whereIn('id', $this->atividadesIdsNovo)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function documentosParaSelecao(): \Illuminate\Support\Collection
  {
    return DocumentoEngenharia::whereHas('pacote', fn($q) => $q->where('obra_id', $this->obra->id))
      ->when($this->pacoteEngenhariaFiltroNovo, fn($q) => $q->where('pacote_engenharia_id', $this->pacoteEngenhariaFiltroNovo))
      ->when($this->buscaDocumentoNovo, fn($q) => $q->where('descricao', 'like', "%{$this->buscaDocumentoNovo}%"))
      ->orderBy('descricao')
      ->limit(30)
      ->get(['id', 'descricao', 'pacote_engenharia_id']);
  }

  #[Computed]
  public function documentosSelecionadosNovo(): \Illuminate\Support\Collection
  {
    if (empty($this->documentosIdsNovo)) {
      return collect();
    }
    return DocumentoEngenharia::whereIn('id', $this->documentosIdsNovo)->orderBy('descricao')->get(['id', 'descricao']);
  }

  public function toggleAtividadeSelecionada(string $atividadeId): void
  {
    if (in_array($atividadeId, $this->atividadesIdsNovo, true)) {
      $this->atividadesIdsNovo = array_values(array_diff($this->atividadesIdsNovo, [$atividadeId]));
    } else {
      $this->atividadesIdsNovo[] = $atividadeId;
    }
    unset($this->atividadesSelecionadasNovo);
  }

  public function toggleDocumentoSelecionado(string $documentoId): void
  {
    if (in_array($documentoId, $this->documentosIdsNovo, true)) {
      $this->documentosIdsNovo = array_values(array_diff($this->documentosIdsNovo, [$documentoId]));
    } else {
      $this->documentosIdsNovo[] = $documentoId;
    }
    unset($this->documentosSelecionadosNovo);
  }

  public function abrirModalCriar(?string $atividadeId = null): void
  {
    $this->garantirPermissao('criar');
    $this->resetModalItem();
    if ($atividadeId) {
      $this->atividadesIdsNovo = [$atividadeId];
    }
    $this->modalItemAberto = true;
  }

  public function abrirModalEditar(string $itemId): void
  {
    $this->garantirPermissao('editar');

    $item = ItemSuprimento::with(['atividades', 'documentosEngenharia'])->findOrFail($itemId);

    $this->resetModalItem();
    $this->editandoItemId = $item->id;
    $this->nomeNovo = $item->nome;
    $this->codigoNovo = $item->codigo ?? '';
    $this->fluxoIdNovo = $item->fluxo_suprimento_id;
    $this->fornecedorIdNovo = $item->fornecedor_id;
    $this->observacoesNovo = $item->observacoes ?? '';
    $this->atividadesIdsNovo = $item->atividades->pluck('id')->all();
    $this->documentosIdsNovo = $item->documentosEngenharia->pluck('id')->all();
    $this->modalItemAberto = true;
  }

  public function salvarItem(): void
  {
    $this->validate(
      [
        'atividadesIdsNovo' => 'required|array|min:1',
        'atividadesIdsNovo.*' => 'exists:atividades,id',
        'nomeNovo' => 'required|string|max:150',
        'codigoNovo' => 'nullable|string|max:60',
        'fluxoIdNovo' => $this->editandoItemId ? 'nullable' : 'required|exists:fluxos_suprimento,id',
        'fornecedorIdNovo' => 'nullable|exists:fornecedores,id',
        'documentosIdsNovo' => 'array',
        'documentosIdsNovo.*' => 'exists:documentos_engenharia,id',
      ],
      [],
      ['atividadesIdsNovo' => 'atividade', 'nomeNovo' => 'nome', 'fluxoIdNovo' => 'fluxo de suprimento']
    );

    $this->garantirPermissao($this->editandoItemId ? 'editar' : 'criar');

    $this->transacaoSegura(function () {
      if ($this->editandoItemId) {
        $item = ItemSuprimento::findOrFail($this->editandoItemId);
        $removidas = $item->atividades->pluck('id')->diff($this->atividadesIdsNovo);

        $item->update([
          'nome' => $this->nomeNovo,
          'codigo' => $this->codigoNovo ?: null,
          'fornecedor_id' => $this->fornecedorIdNovo ?: null,
          'observacoes' => $this->observacoesNovo ?: null,
        ]);
        $item->atividades()->sync($this->atividadesIdsNovo);
        $item->documentosEngenharia()->sync($this->documentosIdsNovo);

        foreach ($removidas as $atividadeId) {
          $atividadeRemovida = Atividade::find($atividadeId);
          if ($atividadeRemovida) {
            SincronizarRestricaoSuprimento::desvincularAtividade($item, $atividadeRemovida, Auth::id());
          }
        }
      } else {
        $item = ItemSuprimento::create([
          'obra_id' => $this->obra->id,
          'fluxo_suprimento_id' => $this->fluxoIdNovo,
          'fornecedor_id' => $this->fornecedorIdNovo ?: null,
          'created_by_id' => Auth::id(),
          'nome' => $this->nomeNovo,
          'codigo' => $this->codigoNovo ?: null,
          'observacoes' => $this->observacoesNovo ?: null,
        ]);
        $item->atividades()->sync($this->atividadesIdsNovo);
        $item->documentosEngenharia()->sync($this->documentosIdsNovo);

        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item->fresh(['atividades']));
      }

      SincronizarRestricaoSuprimento::sincronizarItem($item->fresh(['atividades']), Auth::id());
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $msg = $this->editandoItemId ? 'Item de suprimento atualizado.' : 'Item de suprimento criado.';
    $this->modalItemAberto = false;
    $this->resetModalItem();
    unset($this->itensFiltrados, $this->totais);
    $this->dispatch('show-toast', message: $msg);
  }

  public function excluirItem(string $id): void
  {
    $this->garantirPermissao('excluir');

    // Ciclo 19, Etapa 19.3.CORREÇÃO — trava o Pacote (ItemSuprimento) ANTES
    // de checar/excluir, dentro da MESMA transação, pra disputar o mesmo
    // lock que AlocarRequisicaoAoPacote::alocar()/alterarQuantidade()/
    // remover() adquirem (ordem única: RequisicaoPlanejamentoItem primeiro,
    // ItemSuprimento segundo — aqui só o segundo é relevante, já que
    // excluir Pacote nunca mexe em RPItem). Sem isso, uma alocação
    // concorrente podia ser criada apontando pra um Pacote que acabara de
    // ser soft-deletado (achado C da auditoria adversarial da 19.3).
    try {
      \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
        $item = ItemSuprimento::whereKey($id)->lockForUpdate()->firstOrFail();
        SincronizarRestricaoSuprimento::resolverTudo($item, Auth::id());
        $item->delete();
      });
    } catch (\App\Exceptions\AlocacaoRequisicaoInvalidaException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    unset($this->itensFiltrados, $this->totais);
    $this->dispatch('show-toast', message: 'Item de suprimento removido.');
  }

  // =========================================================================
  // Ciclo 19, Etapa 19.4 — Requisições de Compra (RC)
  // =========================================================================

  public function abrirModalRc(string $pacoteId): void
  {
    // Ciclo 19, Etapa 19.4.CORREÇÃO, item 8/9 — resolve (e descarta) via
    // resolver obra-scoped antes de aceitar o id: um pacoteId de outra
    // obra nunca chega a ficar em $this->rcPacoteId.
    $pacote = $this->resolverPacoteDaObraAtual($pacoteId);

    $this->rcPacoteId = $pacote->id;
    $this->rcDetalheId = null;
    $this->resetFormularioRcNovo();
    unset($this->requisicoesCompraDoPacoteAberto, $this->alocacoesComSaldoParaRc);
  }

  public function fecharModalRc(): void
  {
    $this->rcPacoteId = null;
    $this->rcDetalheId = null;
    $this->resetFormularioRcNovo();
    $this->fecharPedido();
  }

  public function abrirRcDetalhe(string $rcId): void
  {
    $rc = $this->resolverRcDaObraAtual($rcId);

    $this->rcDetalheId = $rc->id;
    $this->resetFormularioRcNovo();
    $this->fecharPedido();
    unset($this->rcAberta, $this->alocacoesComSaldoParaRc);
  }

  public function voltarListaRc(): void
  {
    $this->rcDetalheId = null;
    $this->fecharPedido();
    unset($this->requisicoesCompraDoPacoteAberto);
  }

  private function resetFormularioRcNovo(): void
  {
    $this->rcFluxoIdNovo = null;
    $this->rcObservacaoNovo = '';
    $this->rcItemAlocacaoIdNovo = null;
    $this->rcItemQuantidadeNovo = '';
  }

  public function criarRcRascunho(): void
  {
    $this->garantirPermissao('criar');

    $pacote = $this->resolverPacoteDaObraAtual($this->rcPacoteId);
    $fluxo = $this->rcFluxoIdNovo ? FluxoSuprimento::find($this->rcFluxoIdNovo) : null;

    $rc = (new \App\Actions\Suprimentos\CriarRequisicaoCompra())->execute(
      $pacote,
      $fluxo,
      $this->rcObservacaoNovo ?: null,
      Auth::user(),
    );

    $this->rcDetalheId = $rc->id;
    $this->resetFormularioRcNovo();
    unset($this->requisicoesCompraDoPacoteAberto);
    $this->dispatch('show-toast', message: 'Rascunho de Requisição de Compra criado.');
  }

  public function adicionarItemRc(): void
  {
    $this->garantirPermissao('editar');

    if (! $this->rcItemAlocacaoIdNovo || $this->rcItemQuantidadeNovo === '') {
      $this->dispatch('show-toast', message: 'Selecione uma alocação e informe a quantidade.', type: 'error');
      return;
    }

    try {
      $rc = $this->resolverRcDaObraAtual($this->rcDetalheId);
      $alocacao = $this->resolverAlocacaoDaObraAtual($this->rcItemAlocacaoIdNovo);

      (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, (float) str_replace(',', '.', $this->rcItemQuantidadeNovo));
    } catch (\App\Exceptions\SaldoAlocacaoInsuficienteException|\App\Exceptions\RequisicaoCompraImutavelException|\InvalidArgumentException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    $this->rcItemAlocacaoIdNovo = null;
    $this->rcItemQuantidadeNovo = '';
    unset($this->rcAberta, $this->alocacoesComSaldoParaRc);
    $this->dispatch('show-toast', message: 'Item adicionado à Requisição de Compra.');
  }

  public function removerItemRc(string $itemId): void
  {
    $this->garantirPermissao('editar');

    try {
      $item = $this->resolverItemRcDaObraAtual($itemId);
      (new AtualizarRascunhoRequisicaoCompra())->removerItem($item);
    } catch (\App\Exceptions\RequisicaoCompraImutavelException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    unset($this->rcAberta, $this->alocacoesComSaldoParaRc);
    $this->dispatch('show-toast', message: 'Item removido.');
  }

  public function emitirRc(): void
  {
    $this->garantirPermissao('editar');

    try {
      $rc = $this->resolverRcDaObraAtual($this->rcDetalheId);
      (new \App\Actions\Suprimentos\EmitirRequisicaoCompra())->execute($rc, Auth::user());
    } catch (\App\Exceptions\RequisicaoCompraEmissaoInvalidaException|\App\Exceptions\RequisicaoCompraImutavelException|\App\Exceptions\SaldoAlocacaoInsuficienteException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    unset($this->rcAberta, $this->requisicoesCompraDoPacoteAberto);
    $this->dispatch('show-toast', message: 'Requisição de Compra emitida.');
  }

  public function concluirEtapaRc(string $etapaId): void
  {
    $this->garantirPermissao('editar');

    $etapa = $this->resolverEtapaRcDaObraAtual($etapaId);

    try {
      (new \App\Actions\Suprimentos\RegistrarConclusaoEtapaRequisicaoCompra())->execute($etapa, Auth::user());
    } catch (\App\Exceptions\EtapaRequisicaoCompraJaConcluidaException|\App\Exceptions\RequisicaoCompraImutavelException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    unset($this->rcAberta, $this->requisicoesCompraDoPacoteAberto);
    $this->dispatch('show-toast', message: 'Etapa registrada como concluída.');
  }

  /**
   * Ciclo 19, Etapa 19.4.CORREÇÃO, itens 4/5/6/8 — fecha, de uma vez, os
   * 3 achados C da auditoria: (1) trava a linha ANTES de checar status
   * (nunca confia num objeto Livewire previamente carregado — o `$rcId`
   * é só um id, resolvido do zero aqui dentro da transação); (2) lê o
   * status FRESH sob lock, então uma emissão concorrente que já
   * commitou é sempre vista; (3) reescopa por obra_id antes mesmo de
   * travar, então um id de outra obra nunca chega a ser travado/excluído.
   * Mesma disciplina de 19.2.CORREÇÃO (ItemTakeOff)/19.3.CORREÇÃO
   * (ItemSuprimento) — o Observer continua sendo só a barreira
   * semântica; quem garante ausência de corrida é este lock aqui.
   *
   * **Item 6 — filhos do rascunho excluído**: `RequisicaoCompraItem`
   * NUNCA conta como saldo oficial de qualquer forma (item 1/3 —
   * `quantidadeConsumidaOficialPorRc()` só soma RC Emitida/Concluída),
   * então o soft-delete do header já é suficiente pra "saldo volta a
   * 100%". Mas sem limpar os itens, eles ficam órfãos apontando pra uma
   * alocação — e como essa FK é `restrictOnDelete()`, isso bloquearia
   * PERMANENTEMENTE uma futura remoção física da alocação por causa de
   * um rascunho que o usuário já abandonou. Por isso os itens (nunca
   * etapas — Rascunho jamais tem etapa, só nasce na emissão) são
   * apagados de verdade (`RequisicaoCompraItem` não tem SoftDeletes
   * própria, mesmo padrão de `RequisicaoPlanejamentoItem`) na MESMA
   * transação, LOGO DEPOIS do soft-delete do header (que é quem
   * dispara o guard do Observer — nunca risco de apagar item de uma RC
   * que acaba não sendo Rascunho) — nunca depende de FK cascade (que
   * não dispara em soft-delete, lição já conhecida do projeto).
   */
  public function excluirRcRascunho(string $rcId): void
  {
    $this->garantirPermissao('excluir');

    try {
      \Illuminate\Support\Facades\DB::transaction(function () use ($rcId) {
        $rc = \App\Models\RequisicaoCompra::where('obra_id', $this->obra->id)
          ->whereKey($rcId)
          ->lockForUpdate()
          ->firstOrFail();

        // Ordem importa: delete() do header PRIMEIRO — é ele quem dispara
        // o guard do Observer (RequisicaoCompraImutavelException se não
        // for Rascunho). Só chegamos a apagar os itens DEPOIS de o guard
        // já ter deixado passar — nunca risco de apagar item de uma RC
        // que acaba sendo Emitida/Concluída.
        $rc->delete();
        $rc->itens()->delete();
      });
    } catch (\App\Exceptions\RequisicaoCompraImutavelException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    $this->rcDetalheId = null;
    unset($this->requisicoesCompraDoPacoteAberto);
    $this->dispatch('show-toast', message: 'Rascunho de Requisição de Compra excluído.');
  }

  // =========================================================================
  // Ciclo 19, Etapa 19.5 — Pedidos/Ordens de Compra
  // =========================================================================

  private function resetFormularioPedidoNovo(): void
  {
    $this->pedidoFornecedorIdNovo = null;
    $this->pedidoDataPrevistaEntregaNovo = '';
    $this->pedidoNumeroContratoNovo = '';
    $this->pedidoDataContratoNovo = '';
    $this->pedidoObservacaoNovo = '';
    $this->pedidoItemRcItemIdNovo = null;
    $this->pedidoItemQuantidadeNovo = '';
  }

  public function abrirPedidoDetalhe(string $pedidoId): void
  {
    $pedido = $this->resolverPedidoDaObraAtual($pedidoId);

    $this->pedidoDetalheId = $pedido->id;
    $this->resetFormularioPedidoNovo();
    unset($this->pedidoAberto, $this->rcItensComSaldoParaPedido);
  }

  public function voltarListaPedidos(): void
  {
    $this->pedidoDetalheId = null;
    unset($this->pedidosDaRcAberta);
  }

  public function fecharPedido(): void
  {
    $this->pedidoDetalheId = null;
    $this->resetFormularioPedidoNovo();
  }

  public function criarPedidoRascunho(): void
  {
    $this->garantirPermissao('criar');

    if (! $this->pedidoFornecedorIdNovo) {
      $this->dispatch('show-toast', message: 'Selecione um fornecedor.', type: 'error');
      return;
    }

    try {
      $rc = $this->resolverRcDaObraAtual($this->rcDetalheId);
      $fornecedor = $this->resolverFornecedorDaObraAtual($this->pedidoFornecedorIdNovo);

      $pedido = (new \App\Actions\Suprimentos\CriarPedidoCompra())->execute(
        $rc,
        $fornecedor,
        $this->pedidoDataPrevistaEntregaNovo ?: null,
        $this->pedidoNumeroContratoNovo ?: null,
        $this->pedidoDataContratoNovo ?: null,
        $this->pedidoObservacaoNovo ?: null,
        Auth::user(),
      );
    } catch (\App\Exceptions\PedidoCompraEmissaoInvalidaException|\InvalidArgumentException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    $this->pedidoDetalheId = $pedido->id;
    $this->resetFormularioPedidoNovo();
    unset($this->pedidosDaRcAberta);
    $this->dispatch('show-toast', message: 'Rascunho de Pedido/Ordem de Compra criado.');
  }

  public function adicionarItemPedido(): void
  {
    $this->garantirPermissao('editar');

    if (! $this->pedidoItemRcItemIdNovo || $this->pedidoItemQuantidadeNovo === '') {
      $this->dispatch('show-toast', message: 'Selecione um item e informe a quantidade.', type: 'error');
      return;
    }

    try {
      $pedido = $this->resolverPedidoDaObraAtual($this->pedidoDetalheId);
      $rcItem = $this->resolverRcItemDaObraAtual($this->pedidoItemRcItemIdNovo);

      (new \App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra())->adicionarItem(
        $pedido,
        $rcItem,
        (float) str_replace(',', '.', $this->pedidoItemQuantidadeNovo),
      );
    } catch (\App\Exceptions\SaldoRequisicaoCompraInsuficienteException|\App\Exceptions\PedidoCompraImutavelException|\InvalidArgumentException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    $this->pedidoItemRcItemIdNovo = null;
    $this->pedidoItemQuantidadeNovo = '';
    unset($this->pedidoAberto, $this->rcItensComSaldoParaPedido);
    $this->dispatch('show-toast', message: 'Item adicionado ao Pedido.');
  }

  public function removerItemPedido(string $itemId): void
  {
    $this->garantirPermissao('editar');

    try {
      $item = $this->resolverItemPedidoDaObraAtual($itemId);
      (new \App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra())->removerItem($item);
    } catch (\App\Exceptions\PedidoCompraImutavelException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    unset($this->pedidoAberto, $this->rcItensComSaldoParaPedido);
    $this->dispatch('show-toast', message: 'Item removido.');
  }

  public function emitirPedido(): void
  {
    $this->garantirPermissao('editar');

    try {
      $pedido = $this->resolverPedidoDaObraAtual($this->pedidoDetalheId);
      (new \App\Actions\Suprimentos\EmitirPedidoCompra())->execute($pedido, Auth::user());
    } catch (\App\Exceptions\PedidoCompraEmissaoInvalidaException|\App\Exceptions\PedidoCompraImutavelException|\App\Exceptions\SaldoRequisicaoCompraInsuficienteException|\App\Exceptions\FornecedorPedidoInvalidoException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    unset($this->pedidoAberto, $this->pedidosDaRcAberta);
    $this->dispatch('show-toast', message: 'Pedido/Ordem de Compra emitido.');
  }

  /**
   * Ciclo 19, Etapa 19.5 — mesma disciplina de 19.4.CORREÇÃO (RC): lock
   * ANTES de checar status (nunca reaproveita objeto Livewire
   * previamente carregado), status fresh sob lock, reescopado por
   * obra_id. Header primeiro (dispara o guard do Observer) — só depois,
   * com o guard já tendo deixado passar, limpa os itens do rascunho.
   */
  public function excluirPedidoRascunho(string $pedidoId): void
  {
    $this->garantirPermissao('excluir');

    try {
      \Illuminate\Support\Facades\DB::transaction(function () use ($pedidoId) {
        $pedido = \App\Models\PedidoCompra::where('obra_id', $this->obra->id)
          ->whereKey($pedidoId)
          ->lockForUpdate()
          ->firstOrFail();

        $pedido->delete();
        $pedido->itens()->delete();
      });
    } catch (\App\Exceptions\PedidoCompraImutavelException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    $this->pedidoDetalheId = null;
    unset($this->pedidosDaRcAberta);
    $this->dispatch('show-toast', message: 'Rascunho de Pedido/Ordem de Compra excluído.');
  }

  // =========================================================================
  // Ciclo 19, Etapa 19.6 — Recebimento Físico (dentro do item do Pedido)
  // =========================================================================

  private function resetFormularioRecebimentoNovo(): void
  {
    $this->recebimentoQuantidadeNova = '';
    $this->recebimentoDataNova = '';
    $this->recebimentoLocalNova = '';
    $this->recebimentoObservacaoNova = '';
  }

  public function abrirRecebimentoItem(string $itemId): void
  {
    $this->recebimentoItemAbertoId = $itemId;
    $this->resetFormularioRecebimentoNovo();
  }

  public function fecharRecebimentoItem(): void
  {
    $this->recebimentoItemAbertoId = null;
    $this->resetFormularioRecebimentoNovo();
  }

  public function registrarRecebimento(string $itemId): void
  {
    $this->garantirPermissao('editar');

    if ($this->recebimentoQuantidadeNova === '' || ! $this->recebimentoDataNova) {
      $this->dispatch('show-toast', message: 'Informe a quantidade e a data do recebimento.', type: 'error');
      return;
    }

    try {
      $item = $this->resolverItemPedidoDaObraAtual($itemId);

      (new \App\Actions\Suprimentos\RegistrarRecebimentoPedido())->execute(
        $item,
        (float) str_replace(',', '.', $this->recebimentoQuantidadeNova),
        \Carbon\Carbon::parse($this->recebimentoDataNova),
        Auth::user(),
        $this->recebimentoObservacaoNova ?: null,
        $this->recebimentoLocalNova ?: null,
      );
    } catch (\App\Exceptions\SaldoPedidoInsuficienteException|\App\Exceptions\RecebimentoPedidoInvalidoException $e) {
      $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
      return;
    }

    $this->recebimentoItemAbertoId = null;
    $this->resetFormularioRecebimentoNovo();
    unset($this->pedidoAberto, $this->pedidosDaRcAberta);
    $this->dispatch('show-toast', message: 'Recebimento registrado.');
  }

  public function resetModalItem(): void
  {
    $this->editandoItemId = null;
    $this->atividadesIdsNovo = [];
    $this->buscaAtividadeNovo = '';
    $this->fluxoIdNovo = null;
    $this->fornecedorIdNovo = null;
    $this->documentosIdsNovo = [];
    $this->pacoteEngenhariaFiltroNovo = null;
    $this->buscaDocumentoNovo = '';
    $this->nomeNovo = '';
    $this->codigoNovo = '';
    $this->observacoesNovo = '';
    $this->resetErrorBag();
  }

  // =========================================================================
  // EXPORTAÇÕES — sempre itens ÚNICOS (não linhas da árvore, que repetem
  // o mesmo item por atividade vinculada)
  // =========================================================================

  private function linhasParaExportar(): \Illuminate\Support\Collection
  {
    return $this->itensFiltrados->unique('id')->map(
      fn(ItemSuprimento $item) => [
        'item' => $item,
        'necessidade' => $item->necessidade(),
        'previstoFinal' => $item->etapas->last()?->previsto()?->data,
        'tendenciaFinal' => $item->etapas->last()?->tendencia()?->data,
        'desvioDias' => $this->desvioDias($item),
      ]
    );
  }

  public function exportarExcel()
  {
    return Excel::download(new SuprimentosExport($this->linhasParaExportar()), "suprimentos-{$this->obra->id}.xlsx");
  }

  public function exportarPdf()
  {
    $pdf = Pdf::loadView('exports.suprimentos-pdf', [
      'obra' => $this->obra,
      'linhas' => $this->linhasParaExportar(),
    ])->setPaper('a4', 'landscape');

    return response()->streamDownload(fn() => print $pdf->output(), "suprimentos-{$this->obra->id}.pdf");
  }
};
?>

<div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Header + cards de totais --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div>
            <h5 class="mb-1 mt-2">📦 Mapa de Suprimentos</h5>
            <small class="text-muted mb-0">Planejamento de compras alinhado ao cronograma — cada material é solicitado com antecedência suficiente pra não parar a obra.</small>
        </div>
        @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'criar'))
        <button class="btn btn-primary" wire:click="abrirModalCriar">
            <i class="bx bx-plus me-1"></i>Novo Item de Suprimento
        </button>
        @endif
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold">{{ $this->totais['total'] }}</div>
                <small class="text-muted">Itens no filtro</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-warning"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-warning">{{ $this->totais['em_risco'] }}</div>
                <small class="text-muted">Em risco</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-danger"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-danger">{{ $this->totais['atrasado'] }}</div>
                <small class="text-muted">Atrasados</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-success"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-success">{{ $this->totais['concluido'] }}</div>
                <small class="text-muted">Concluídos</small>
            </div></div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Lista de pacotes de compra (sem hierarquia de cronograma) --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card">
        <div class="card-body p-0">
            @if($this->itensFiltrados->isEmpty())
            <div class="text-center py-5">
                <i class="bx bx-package fs-1 text-muted d-block mb-2"></i>
                <p class="text-muted mb-1">
                    @if($this->temFiltrosAtivos())
                    Nenhum item encontrado com os filtros ativos.
                    @else
                    Nenhum item de suprimento cadastrado ainda nesta obra.
                    @endif
                </p>
                @if(!$this->temFiltrosAtivos() && Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'criar'))
                <button class="btn btn-sm btn-primary mt-2" wire:click="abrirModalCriar">
                    <i class="bx bx-plus me-1"></i>Cadastrar o primeiro item
                </button>
                @endif
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Pacote de Compra</th>
                            <th class="text-center" style="width:80px">Atividades</th>
                            <th class="text-center" style="width:90px">Demanda</th>
                            <th class="text-center" style="width:60px">Farol</th>
                            <th class="text-center" style="width:100px">Status</th>
                            <th class="text-center" style="width:100px">Progresso</th>
                            <th class="text-center" style="width:100px">Necessidade</th>
                            <th class="text-center" style="width:110px">Tendência</th>
                            <th class="text-center" style="width:90px">Desvio</th>
                            <th style="width:120px">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->itensFiltrados as $item)
                        @php
                            $desvio = $this->desvioDias($item);
                            $estadoFinal = $this->estadoEtapa($item->etapas->last());
                            $progresso = $this->progressoEtapas($item);
                        @endphp
                        <tr wire:key="item-{{ $item->id }}">
                            <td>
                                <i class="bx bx-package text-muted me-1"></i>
                                {{ $item->nome }}
                                @if($item->codigo)
                                <span class="text-muted small">({{ $item->codigo }})</span>
                                @endif
                                @if($item->comentarios_count > 0)
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" style="line-height:1"
                                        wire:click="abrirDetalhe('{{ $item->id }}')"
                                        title="{{ $item->comentarios_count }} comentário(s)">
                                    <i class="bx bx-comment-detail text-info"></i><small class="text-muted">{{ $item->comentarios_count }}</small>
                                </button>
                                @endif
                                <br><span class="text-muted small ms-4">{{ $item->fluxo?->nome ?? '—' }}</span>
                            </td>
                            <td class="text-center">
                                <button type="button" class="badge bg-label-primary border-0" style="cursor:pointer"
                                        wire:click="abrirAtividadesVinculadas('{{ $item->id }}')"
                                        title="Ver atividades vinculadas">
                                    <i class="bx bx-link me-1"></i>{{ $item->atividades->count() }}
                                </button>
                            </td>
                            <td class="text-center">
                                @php
                                    $demanda = $this->alocacoesPorPacote->get($item->id);
                                @endphp
                                @if ($demanda)
                                <span class="badge bg-label-info" title="{{ $demanda->total_rps }} Requisição(ões) do Planejamento, {{ $demanda->total_rp_itens }} item(ns)">
                                    <i class="bx bx-clipboard"></i> {{ $demanda->total_rps }} RP{{ $demanda->total_rps > 1 ? 's' : '' }}
                                </span>
                                @else
                                <span class="text-muted small" title="Sem demanda formal do Planejamento alocada ainda">—</span>
                                @endif
                            </td>
                            <td class="text-center" title="{{ $item->status->label() }}">
                                <span class="fs-5" role="img" aria-label="{{ $item->status->label() }}">{{ $this->farolEmoji($item->status) }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge {{ $this->statusSimplificadoClasse($item->status) }}">{{ $this->statusSimplificadoLabel($item->status) }}</span>
                            </td>
                            <td class="text-center" style="min-width:90px">
                                <div class="progress" style="height:6px">
                                    <div class="progress-bar {{ $this->corBarraProgresso($item->status) }}" role="progressbar" style="width: {{ $progresso['percentual'] }}%"></div>
                                </div>
                                <small class="text-muted" style="font-size:.68rem">{{ $progresso['percentual'] }}%</small>
                            </td>
                            <td class="text-center small text-muted">{{ $item->necessidade()?->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-center small fw-semibold {{ $this->corEstadoEtapa($estadoFinal) }}">{{ $item->etapas->last()?->tendencia()?->data?->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-center">
                                @if($desvio === null)
                                <span class="text-muted">—</span>
                                @elseif($desvio > 0)
                                <span class="badge bg-danger">+{{ $desvio }}d</span>
                                @elseif($desvio < 0)
                                <span class="badge bg-success">{{ $desvio }}d</span>
                                @else
                                <span class="badge bg-label-secondary">0d</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-xs btn-outline-secondary py-0 px-1" title="Ver etapas"
                                        wire:click="abrirDetalhe('{{ $item->id }}')">
                                    <i class="bx bx-list-ul"></i>
                                </button>
                                @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                                <button class="btn btn-xs btn-outline-secondary py-0 px-1" title="Editar"
                                        wire:click="abrirModalEditar('{{ $item->id }}')">
                                    <i class="bx bx-pencil"></i>
                                </button>
                                @endif
                                @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'excluir'))
                                <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" title="Excluir"
                                        onclick="confirmarAcao(this, {
                                            mensagem: 'Remover o item \'{{ $item->nome }}\'? As restrições vinculadas a ele serão resolvidas automaticamente.',
                                            metodo: 'excluirItem',
                                            args: ['{{ $item->id }}'],
                                            icone: 'bx-trash',
                                        })">
                                    <i class="bx bx-trash"></i>
                                </button>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
    @if($this->itensFiltrados->isNotEmpty())
    <div class="d-flex flex-wrap align-items-center gap-3 mt-2 small text-muted">
        <span><i class="bx bx-info-circle me-1"></i>Tendência = Realizado (se já aconteceu) ou melhor previsão atual da última etapa.</span>
        <span class="d-inline-flex align-items-center gap-1"><span class="badge bg-success-subtle border">&nbsp;</span> No prazo/concluído</span>
        <span class="d-inline-flex align-items-center gap-1"><span class="badge bg-warning-subtle border">&nbsp;</span> Em risco (≤5 dias úteis)</span>
        <span class="d-inline-flex align-items-center gap-1"><span class="badge bg-danger">&nbsp;</span> Atrasado</span>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Atividades vinculadas ao pacote de compra --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($atividadesModalItemId && $this->itemParaModalAtividades)
    @php $itemAtividades = $this->itemParaModalAtividades; @endphp
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-link me-2"></i>Atividades vinculadas</h5>
                    <button type="button" class="btn-close" wire:click="fecharAtividadesVinculadas"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Pacote de compra: <strong>{{ $itemAtividades->nome }}</strong></p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Atividade</th>
                                    <th class="text-center" style="width:110px">Início</th>
                                    <th class="text-center" style="width:110px">Término</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($itemAtividades->atividades as $at)
                                <tr>
                                    <td>{{ $at->nome }}</td>
                                    <td class="text-center small text-muted">{{ $at->inicio_planejado?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="text-center small text-muted">{{ $at->data_termino?->format('d/m/Y') ?? '—' }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">Nenhuma atividade vinculada.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="fecharAtividadesVinculadas">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Detalhe do item (etapas P/T/R) --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($itemDetalheId && $this->itemDetalhe)
    @php $item = $this->itemDetalhe; @endphp
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="fs-4 me-1" role="img" aria-label="{{ $item->status->label() }}">{{ $this->farolEmoji($item->status) }}</span>
                        {{ $item->nome }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="fecharDetalhe"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-3 mb-3 small text-muted">
                        <span><strong>Fluxo:</strong> {{ $item->fluxo?->nome ?? '—' }}</span>
                        <span><strong>Fornecedor:</strong> {{ $item->fornecedor?->nome ?? '—' }}</span>
                        <span><strong>Necessidade:</strong> {{ $item->necessidade()?->format('d/m/Y') ?? '—' }}</span>
                        <span><strong>Status:</strong> <span class="badge {{ $this->badgeStatus($item->status) }}">{{ $item->status->label() }}</span></span>
                    </div>

                    {{-- Ciclo 19, Etapa 19.6, seção 31 — Atendimento e Recebimento Físico (fecha o D pendente da 19.5). --}}
                    @php $atendimentoFisico = $this->atendimentoFisicoPacoteAberto; @endphp
                    @if($atendimentoFisico)
                    <div class="mb-4">
                        <h6 class="mb-2 fw-bold text-uppercase" style="font-size:.78rem; letter-spacing:.03em">Atendimento e Recebimento Físico</h6>
                        <div class="row g-2 small">
                            <div class="col-6 col-md-3">
                                <div class="text-muted">Necessidade</div>
                                <div class="fw-semibold">{{ $atendimentoFisico['necessidade']?->format('d/m/Y') ?? '—' }}</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-muted">Atendimento projetado</div>
                                <div class="fw-semibold">{{ $atendimentoFisico['atendimento_projetado']?->format('d/m/Y') ?? '—' }}</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-muted">Folga projetada</div>
                                <div class="fw-semibold">
                                    @if($atendimentoFisico['folga'] === null)
                                        <span class="text-muted">—</span>
                                    @else
                                        {{ $atendimentoFisico['folga'] }}d
                                        <span class="badge {{ $atendimentoFisico['risco'] === 'em_risco' ? 'bg-danger' : 'bg-success' }} ms-1">
                                            {{ $atendimentoFisico['risco'] === 'em_risco' ? 'Em risco' : 'Dentro do prazo' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-muted">Pedidos emitidos</div>
                                <div class="fw-semibold">{{ $atendimentoFisico['total_pedidos'] }}</div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-3 mt-2 small">
                            <span><span class="badge bg-label-secondary">{{ $atendimentoFisico['itens_nao_recebidos'] }}</span> não recebidos</span>
                            <span><span class="badge bg-label-warning">{{ $atendimentoFisico['itens_parciais'] }}</span> parciais</span>
                            <span><span class="badge bg-label-success">{{ $atendimentoFisico['itens_completos'] }}</span> completos</span>
                            @if($atendimentoFisico['algum_pedido_atrasado'])
                            <span class="badge bg-danger"><i class="bx bx-error me-1"></i>Há Pedido com entrega atrasada</span>
                            @endif
                            @if($this->restricoesAutomaticasAbertasPacoteAberto > 0)
                            <span class="badge bg-danger"><i class="bx bx-block me-1"></i>{{ $this->restricoesAutomaticasAbertasPacoteAberto }} atividade(s) com Restrição automática ativa</span>
                            @endif
                        </div>
                    </div>
                    @endif

                    {{-- Ciclo 19, Etapa 19.3, seção 28 — Demanda do Planejamento (somente leitura). --}}
                    <div class="mb-4">
                        <h6 class="mb-2 fw-bold text-uppercase" style="font-size:.78rem; letter-spacing:.03em">Demanda do Planejamento</h6>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>RP</th>
                                        <th>Item</th>
                                        <th>LM/LI</th>
                                        <th>Documento/Revisão</th>
                                        <th class="text-end">Qtd. requisitada</th>
                                        <th class="text-end">Qtd. alocada</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($this->alocacoesDoPacoteAberto as $alocacao)
                                        @php
                                            $rpItem = $alocacao->requisicaoItem;
                                            $ito = $rpItem?->itemTakeOff;
                                        @endphp
                                        <tr wire:key="demanda-{{ $alocacao->id }}">
                                            <td>{{ $rpItem?->requisicao?->numero ? '#'.str_pad($rpItem->requisicao->numero, 4, '0', STR_PAD_LEFT) : '—' }}</td>
                                            <td>{{ $ito?->descricao ?? $rpItem?->descricao_snapshot ?? '—' }}</td>
                                            <td>{{ $ito?->lista?->codigo ?? $rpItem?->lista_codigo_snapshot ?? '—' }}</td>
                                            <td>{{ $ito?->lista?->revisao?->documento?->codigo ?? $rpItem?->documento_codigo_snapshot ?? '—' }} / {{ $ito?->lista?->revisao?->revisao ?? $rpItem?->revisao_snapshot ?? '—' }}</td>
                                            <td class="text-end">{{ number_format((float) ($rpItem?->quantidade_requisitada ?? 0), 3, ',', '.') }} {{ $ito?->unidadeMedida?->codigo ?? $rpItem?->unidade_snapshot }}</td>
                                            <td class="text-end">{{ number_format((float) $alocacao->quantidade_alocada, 3, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-center text-muted py-3">Sem demanda formal do Planejamento alocada a este Pacote ainda.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Ciclo 19, Etapa 19.4 — Requisições de Compra (domínio novo e paralelo
                         ao mecanismo legado abaixo; nunca sincronizam). --}}
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 fw-bold text-uppercase" style="font-size:.78rem; letter-spacing:.03em">Requisições de Compra</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirModalRc('{{ $item->id }}')">
                                <i class="bx bx-cart me-1"></i>Gerenciar Requisições de Compra
                            </button>
                        </div>
                        <p class="text-muted small mb-0">
                            Processo formal de compra, independente do fluxo legado abaixo — este Pacote pode ter várias
                            Requisições de Compra, cada uma com seu próprio progresso.
                        </p>
                    </div>

                    {{-- Timeline vertical: avanço proporcional à quantidade de etapas --}}
                    @php $progresso = $this->progressoEtapas($item); @endphp
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 fw-bold text-uppercase" style="font-size:.78rem; letter-spacing:.03em">Processo de Compra</h6>
                            <span class="fw-bold">{{ $progresso['percentual'] }}%</span>
                        </div>
                        <div class="progress mb-3" style="height:6px">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $progresso['percentual'] }}%"></div>
                        </div>
                        <div class="suprimento-timeline">
                            @foreach($this->linhasTimelineEtapas($item) as $linha)
                            @php
                                $etapa = $linha['etapa'];
                                $estado = $linha['estado'];
                                $destacada = $linha['destacada'];
                                $icone = $this->iconeCirculoEtapa($estado, $destacada);
                            @endphp
                            <div class="suprimento-timeline-item">
                                <div class="suprimento-timeline-marcador">
                                    <div class="suprimento-timeline-circulo {{ $this->corCirculoEtapa($estado, $destacada) }}">
                                        @if($icone)
                                        <i class="{{ $icone }}"></i>
                                        @endif
                                    </div>
                                    @if(!$loop->last)
                                    <div class="suprimento-timeline-linha"></div>
                                    @endif
                                </div>
                                <div class="suprimento-timeline-conteudo {{ $etapa->nao_aplicavel ? 'opacity-50' : '' }}">
                                    <small class="d-block fw-bold text-uppercase {{ $this->corTextoEtapa($estado, $destacada) }}" style="font-size:.68rem; letter-spacing:.03em">{{ $etapa->nome }}</small>
                                    <span class="d-block fw-semibold">{{ $this->tituloLinhaTimeline($linha) }}</span>
                                    <small class="text-muted">{{ $linha['data']?->format('d/m/Y') ?? '—' }}</small>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Etapa</th>
                                    <th class="text-center" style="width:130px">Previsto</th>
                                    <th class="text-center" style="width:130px">Tendência</th>
                                    <th class="text-center" style="width:160px">Realizado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($item->etapas as $etapa)
                                <tr>
                                    <td>
                                        {{ $etapa->nome }}
                                        @if($etapa->nao_aplicavel)
                                        <span class="badge bg-label-secondary ms-1">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-center small text-muted" title="Congelado — não muda depois de criado">
                                        {{ $etapa->previsto()?->data?->format('d/m/Y') ?? '—' }}
                                    </td>
                                    <td class="text-center small text-muted">
                                        {{ $etapa->tendencia()?->data?->format('d/m/Y') ?? '—' }}
                                    </td>
                                    <td class="text-center">
                                        @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                                        <input type="date" class="form-control form-control-sm"
                                               wire:model="realizadoForm.{{ $etapa->id }}">
                                        @else
                                        {{ $etapa->realizado()?->data?->format('d/m/Y') ?? '—' }}
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($item->documentosEngenharia->isNotEmpty())
                    <div class="mt-2">
                        <small class="text-muted d-block mb-1">
                            <strong>Documentos de engenharia vinculados:</strong>
                            {{ number_format($item->percentualEngenhariaConcluida(), 0) }}% concluído
                            @if($item->proximaDataLimiteEngenharia())
                            · próxima data limite: {{ $item->proximaDataLimiteEngenharia()->format('d/m/Y') }}
                            @endif
                        </small>
                        @foreach($item->documentosEngenharia as $doc)
                        <span class="badge bg-label-info me-1">{{ $doc->descricao }}</span>
                        @endforeach
                    </div>
                    @endif

                    <div class="border-top mt-4 pt-3">
                        <h6 class="fw-bold mb-3">
                            <i class="bx bx-comment-detail me-2 text-info"></i>Comentários
                            <span class="badge bg-secondary">{{ $item->comentarios->count() }}</span>
                        </h6>

                        @if($item->comentarios->isEmpty())
                        <p class="text-muted small mb-3">Nenhum comentário ainda.</p>
                        @else
                        <div class="mb-3" style="max-height:220px; overflow-y:auto">
                            @foreach($item->comentarios as $com)
                            <div class="d-flex gap-2 mb-2">
                                <i class="bx bx-chevron-right text-muted flex-shrink-0"></i>
                                <div>
                                    <small>{{ $com->comentario }}</small>
                                    <small class="text-muted d-block">
                                        {{ $com->autor?->first_name }} {{ $com->autor?->last_name }}
                                        — {{ $com->created_at->format('d/m/Y H:i') }}
                                    </small>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif

                        @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                        <div class="d-flex gap-2">
                            <textarea class="form-control form-control-sm @error('comentarioNovo') is-invalid @enderror"
                                      rows="2" wire:model="comentarioNovo"
                                      placeholder="Escreva uma observação sobre este pacote de compra..."></textarea>
                            <button class="btn btn-sm btn-primary flex-shrink-0" style="height:fit-content"
                                    wire:click="adicionarComentario" wire:loading.attr="disabled">
                                <i class="bx bx-send"></i>
                            </button>
                        </div>
                        @error('comentarioNovo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="fecharDetalhe">Fechar</button>
                    @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                    <button class="btn btn-primary" wire:click="salvarRealizado" wire:loading.attr="disabled">
                        <i class="bx bx-check me-1"></i>Salvar Realizado
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Ciclo 19, Etapa 19.4 — Modal: Requisições de Compra (RC) --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($rcPacoteId)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                @if(! $rcDetalheId)
                {{-- Lista de RCs do Pacote + criação de novo rascunho --}}
                <div class="modal-header">
                    <h5 class="modal-title">Requisições de Compra</h5>
                    <button type="button" class="btn-close" wire:click="fecharModalRc"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Nº</th>
                                    <th>Status</th>
                                    <th>Fluxo</th>
                                    <th>Fim previsto</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->requisicoesCompraDoPacoteAberto as $rc)
                                <tr wire:key="rc-{{ $rc->id }}">
                                    <td>{{ $rc->numero ? '#'.str_pad($rc->numero, 4, '0', STR_PAD_LEFT) : 'Rascunho' }}</td>
                                    <td><span class="badge {{ $rc->status->value === 'concluida' ? 'bg-label-success' : ($rc->status->value === 'emitida' ? 'bg-label-info' : 'bg-label-secondary') }}">{{ $rc->status->label() }}</span></td>
                                    <td>{{ $rc->fluxo_nome_snapshot ?? $rc->fluxo?->nome ?? '—' }}</td>
                                    <td>{{ $rc->fimPrevisto()?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="text-end">
                                        <button class="btn btn-xs btn-outline-secondary py-0 px-1" wire:click="abrirRcDetalhe('{{ $rc->id }}')">
                                            <i class="bx bx-show"></i>
                                        </button>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">Nenhuma Requisição de Compra ainda.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'criar'))
                    <h6 class="fw-bold text-uppercase mb-2" style="font-size:.78rem; letter-spacing:.03em">Nova Requisição de Compra</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">Fluxo de Suprimento</label>
                            <select class="form-select form-select-sm" wire:model="rcFluxoIdNovo">
                                <option value="">Selecione (obrigatório para emitir)</option>
                                @foreach($this->fluxos as $fluxo)
                                <option value="{{ $fluxo->id }}">{{ $fluxo->nome }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Observação</label>
                            <input type="text" class="form-control form-control-sm" wire:model="rcObservacaoNovo">
                        </div>
                    </div>
                    <button class="btn btn-sm btn-primary mt-3" wire:click="criarRcRascunho">
                        <i class="bx bx-plus me-1"></i>Criar Rascunho
                    </button>
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="fecharModalRc">Fechar</button>
                </div>
                @elseif ($this->rcAberta)
                @php $rc = $this->rcAberta; @endphp
                {{-- Detalhe de uma RC — itens (rascunho) / etapas (emitida/concluída) --}}
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ $rc->numero ? 'Requisição de Compra #'.str_pad($rc->numero, 4, '0', STR_PAD_LEFT) : 'Rascunho de Requisição de Compra' }}
                        <span class="badge {{ $rc->status->value === 'concluida' ? 'bg-label-success' : ($rc->status->value === 'emitida' ? 'bg-label-info' : 'bg-label-secondary') }} ms-1">{{ $rc->status->label() }}</span>
                    </h5>
                    <button type="button" class="btn-close" wire:click="fecharModalRc"></button>
                </div>
                <div class="modal-body">
                    <button type="button" class="btn btn-link btn-sm p-0 mb-3" wire:click="voltarListaRc">
                        <i class="bx bx-arrow-back me-1"></i>Voltar para a lista
                    </button>

                    @if($rc->status->value === 'rascunho')
                    {{-- Itens (rascunho, editável) --}}
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end">Quantidade</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rc->itens as $rcItem)
                                @php $ito = $rcItem->alocacao?->requisicaoItem?->itemTakeOff; @endphp
                                <tr wire:key="rcitem-{{ $rcItem->id }}">
                                    <td>{{ $ito?->descricao ?? '—' }}</td>
                                    <td class="text-end">{{ number_format((float) $rcItem->quantidade, 3, ',', '.') }} {{ $ito?->unidadeMedida?->codigo }}</td>
                                    <td class="text-end">
                                        <button class="btn btn-xs btn-outline-danger py-0 px-1" wire:click="removerItemRc('{{ $rcItem->id }}')">
                                            <i class="bx bx-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">Nenhum item adicionado ainda.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-7">
                            <label class="form-label small">Alocação (saldo disponível)</label>
                            <select class="form-select form-select-sm" wire:model="rcItemAlocacaoIdNovo">
                                <option value="">Selecione</option>
                                @foreach($this->alocacoesComSaldoParaRc as $alocacao)
                                <option value="{{ $alocacao->id }}">
                                    {{ $alocacao->requisicaoItem?->itemTakeOff?->descricao }} — saldo {{ number_format($alocacao->saldo_para_rc, 3, ',', '.') }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Quantidade</label>
                            <input type="text" class="form-control form-control-sm" wire:model="rcItemQuantidadeNovo">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary w-100" wire:click="adicionarItemRc">Adicionar</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-success" wire:click="emitirRc">
                        <i class="bx bx-send me-1"></i>Emitir Requisição de Compra
                    </button>
                    @endif
                    @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'excluir'))
                    <button type="button" class="btn btn-outline-danger ms-2"
                            onclick="confirmarAcao(this, { mensagem: 'Excluir este rascunho de Requisição de Compra?', metodo: 'excluirRcRascunho', args: ['{{ $rc->id }}'], icone: 'bx-trash' })">
                        Excluir Rascunho
                    </button>
                    @endif
                    @else
                    {{-- Emitida/Concluída: etapas (progresso) --}}
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Etapa</th>
                                    <th class="text-center">Prevista</th>
                                    <th class="text-center">Realizada</th>
                                    <th class="text-center">Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rc->etapas as $etapa)
                                <tr wire:key="rcetapa-{{ $etapa->id }}">
                                    <td>{{ $etapa->nome_snapshot }}</td>
                                    <td class="text-center small text-muted">{{ $etapa->data_prevista->format('d/m/Y') }}</td>
                                    <td class="text-center small">{{ $etapa->data_realizada?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="text-center">
                                        @php $statusEtapa = $etapa->status(); @endphp
                                        <span class="badge {{ $statusEtapa->value === 'concluida' ? 'bg-label-success' : ($statusEtapa->value === 'atrasada' ? 'bg-label-danger' : 'bg-label-secondary') }}">{{ $statusEtapa->label() }}</span>
                                    </td>
                                    <td class="text-end">
                                        @if(! $etapa->data_realizada && $rc->status->value === 'emitida' && Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                                        <button class="btn btn-xs btn-outline-success py-0 px-1" wire:click="concluirEtapaRc('{{ $etapa->id }}')">
                                            <i class="bx bx-check"></i>
                                        </button>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Ciclo 19, Etapa 19.5 — Pedidos/Ordens de Compra desta RC --}}
                    <hr class="my-4">
                    @if(! $pedidoDetalheId)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 fw-bold text-uppercase" style="font-size:.78rem; letter-spacing:.03em">Pedidos / Ordens de Compra</h6>
                    </div>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Nº</th>
                                    <th>Fornecedor</th>
                                    <th>Status</th>
                                    <th>Previsão de entrega</th>
                                    <th>Situação de entrega</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->pedidosDaRcAberta as $pedido)
                                <tr wire:key="pedido-{{ $pedido->id }}">
                                    <td>{{ $pedido->numero ? '#'.str_pad($pedido->numero, 4, '0', STR_PAD_LEFT) : 'Rascunho' }}</td>
                                    <td>{{ $pedido->fornecedor_nome_snapshot ?? $pedido->fornecedor?->nome ?? '—' }}</td>
                                    <td><span class="badge {{ $pedido->status->value === 'emitido' ? 'bg-label-info' : 'bg-label-secondary' }}">{{ $pedido->status->label() }}</span></td>
                                    <td>{{ $pedido->data_prevista_entrega?->format('d/m/Y') ?? '—' }}</td>
                                    <td>
                                        @if(! $pedido->resumo_entrega)
                                        <span class="text-muted">—</span>
                                        @else
                                        <span class="badge {{ match($pedido->resumo_entrega['situacao']->value) { 'completa' => 'bg-label-success', 'parcial' => 'bg-label-warning', default => 'bg-label-secondary' } }}">
                                            {{ $pedido->resumo_entrega['situacao']->label() }}
                                        </span>
                                        <span class="text-muted small ms-1">({{ $pedido->resumo_entrega['itens_completos'] }}/{{ $pedido->resumo_entrega['total_itens'] }} itens)</span>
                                        @if($pedido->resumo_entrega['dias_atraso_atual'])
                                        <span class="badge bg-danger ms-1">+{{ $pedido->resumo_entrega['dias_atraso_atual'] }}d atraso</span>
                                        @endif
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-xs btn-outline-secondary py-0 px-1" wire:click="abrirPedidoDetalhe('{{ $pedido->id }}')">
                                            <i class="bx bx-show"></i>
                                        </button>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">Nenhum Pedido/Ordem de Compra ainda.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'criar'))
                    <h6 class="fw-bold text-uppercase mb-2" style="font-size:.78rem; letter-spacing:.03em">Novo Pedido/Ordem de Compra</h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small">Fornecedor</label>
                            <select class="form-select form-select-sm" wire:model="pedidoFornecedorIdNovo">
                                <option value="">Selecione</option>
                                @foreach($this->fornecedores as $fornecedor)
                                <option value="{{ $fornecedor->id }}">{{ $fornecedor->nome }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Previsão de entrega</label>
                            <input type="date" class="form-control form-control-sm" wire:model="pedidoDataPrevistaEntregaNovo">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Nº Contrato</label>
                            <input type="text" class="form-control form-control-sm" wire:model="pedidoNumeroContratoNovo">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Data do Contrato</label>
                            <input type="date" class="form-control form-control-sm" wire:model="pedidoDataContratoNovo">
                        </div>
                    </div>
                    <button class="btn btn-sm btn-primary mt-3" wire:click="criarPedidoRascunho">
                        <i class="bx bx-plus me-1"></i>Criar Rascunho de Pedido
                    </button>
                    @endif
                    @else
                    @php $pedido = $this->pedidoAberto; @endphp
                    @if($pedido)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 fw-bold text-uppercase" style="font-size:.78rem; letter-spacing:.03em">
                            {{ $pedido->numero ? 'Pedido #'.str_pad($pedido->numero, 4, '0', STR_PAD_LEFT) : 'Rascunho de Pedido' }}
                            <span class="badge {{ $pedido->status->value === 'emitido' ? 'bg-label-info' : 'bg-label-secondary' }} ms-1">{{ $pedido->status->label() }}</span>
                        </h6>
                        <button type="button" class="btn btn-link btn-sm p-0" wire:click="voltarListaPedidos">
                            <i class="bx bx-arrow-back me-1"></i>Voltar
                        </button>
                    </div>
                    <div class="d-flex flex-wrap gap-3 mb-3 small text-muted">
                        <span><strong>Fornecedor:</strong> {{ $pedido->fornecedor_nome_snapshot ?? $pedido->fornecedor?->nome ?? '—' }}</span>
                        <span><strong>Previsão de entrega:</strong> {{ $pedido->data_prevista_entrega?->format('d/m/Y') ?? '—' }}</span>
                        @if($pedido->numero_contrato)
                        <span><strong>Contrato:</strong> {{ $pedido->numero_contrato }} @if($pedido->data_contrato) ({{ $pedido->data_contrato->format('d/m/Y') }}) @endif</span>
                        @endif
                    </div>

                    {{-- Ciclo 19, Etapa 19.6, seção 23/32 — resumo de entrega física. --}}
                    @if($pedido->status->value === 'emitido')
                    @php
                        $situacaoEntrega = $pedido->situacaoEntrega();
                        $diasAtrasoAtual = $pedido->diasAtrasoAtual();
                        $diasAtrasoFinal = $pedido->diasAtrasoFinal();
                        $dataEntregaCompleta = $pedido->dataEntregaCompleta();
                    @endphp
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <span class="badge {{ match($situacaoEntrega->value) { 'completa' => 'bg-label-success', 'parcial' => 'bg-label-warning', default => 'bg-label-secondary' } }}">
                            {{ $situacaoEntrega->label() }}
                        </span>
                        @if($dataEntregaCompleta)
                        <span class="small text-muted">Entrega completa em {{ $dataEntregaCompleta->format('d/m/Y') }}</span>
                        @endif
                        @if($diasAtrasoAtual)
                        <span class="badge bg-danger">Atrasado há {{ $diasAtrasoAtual }} dia(s)</span>
                        @endif
                        @if($diasAtrasoFinal)
                        <span class="badge bg-warning text-dark">Concluído com {{ $diasAtrasoFinal }} dia(s) de atraso</span>
                        @endif
                    </div>
                    @endif

                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end">Pedido</th>
                                    @if($pedido->status->value === 'rascunho')
                                    <th></th>
                                    @else
                                    <th class="text-end">Recebido</th>
                                    <th class="text-end">Saldo</th>
                                    <th>Situação</th>
                                    <th></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($pedido->itens as $pedidoItem)
                                @php $statusRecebimento = $pedido->status->value === 'emitido' ? $pedidoItem->statusRecebimento() : null; @endphp
                                <tr wire:key="pedidoitem-{{ $pedidoItem->id }}">
                                    <td>{{ $pedidoItem->descricao_snapshot ?? $pedidoItem->requisicaoCompraItem?->descricao_snapshot ?? '—' }}</td>
                                    <td class="text-end">{{ number_format((float) $pedidoItem->quantidade_pedida, 3, ',', '.') }} {{ $pedidoItem->unidade_snapshot ?? $pedidoItem->requisicaoCompraItem?->unidade_snapshot }}</td>
                                    @if($pedido->status->value === 'rascunho')
                                    <td class="text-end">
                                        <button class="btn btn-xs btn-outline-danger py-0 px-1" wire:click="removerItemPedido('{{ $pedidoItem->id }}')">
                                            <i class="bx bx-trash"></i>
                                        </button>
                                    </td>
                                    @else
                                    <td class="text-end">{{ number_format($pedidoItem->quantidadeRecebida(), 3, ',', '.') }}</td>
                                    <td class="text-end">{{ number_format($pedidoItem->saldoAReceber(), 3, ',', '.') }}</td>
                                    <td>
                                        <span class="badge {{ match($statusRecebimento->value) { 'recebido' => 'bg-label-success', 'parcialmente_recebido' => 'bg-label-warning', default => 'bg-label-secondary' } }}">
                                            {{ $statusRecebimento->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                                        <button class="btn btn-xs btn-outline-primary py-0 px-1" wire:click="abrirRecebimentoItem('{{ $pedidoItem->id }}')" title="Registrar recebimento">
                                            <i class="bx bx-truck"></i>
                                        </button>
                                        @endif
                                    </td>
                                    @endif
                                </tr>
                                @if($pedido->status->value === 'emitido' && $recebimentoItemAbertoId === $pedidoItem->id)
                                <tr wire:key="pedidoitem-recebimento-{{ $pedidoItem->id }}">
                                    <td colspan="5">
                                        <div class="border rounded p-2 bg-light">
                                            @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                                            <div class="row g-2 align-items-end mb-2">
                                                <div class="col-md-3">
                                                    <label class="form-label small">Quantidade recebida</label>
                                                    <input type="text" class="form-control form-control-sm" wire:model="recebimentoQuantidadeNova">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small">Data do recebimento</label>
                                                    <input type="date" class="form-control form-control-sm" wire:model="recebimentoDataNova" max="{{ now()->toDateString() }}">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small">Local (opcional)</label>
                                                    <input type="text" class="form-control form-control-sm" wire:model="recebimentoLocalNova">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small">Observação (opcional)</label>
                                                    <input type="text" class="form-control form-control-sm" wire:model="recebimentoObservacaoNova">
                                                </div>
                                            </div>
                                            <button class="btn btn-sm btn-primary" wire:click="registrarRecebimento('{{ $pedidoItem->id }}')">Registrar recebimento</button>
                                            <button type="button" class="btn btn-sm btn-link" wire:click="fecharRecebimentoItem">Cancelar</button>
                                            @endif

                                            {{-- Ciclo 19, Etapa 19.6, seção 33 — histórico completo, nunca só o estado final. --}}
                                            @if($pedidoItem->recebimentos->isNotEmpty())
                                            <table class="table table-sm mb-0 mt-2">
                                                <thead>
                                                    <tr>
                                                        <th>Data</th>
                                                        <th class="text-end">Quantidade</th>
                                                        <th>Registrado por</th>
                                                        <th>Observação</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($pedidoItem->recebimentos as $evento)
                                                    <tr wire:key="recebimento-{{ $evento->id }}">
                                                        <td class="small">{{ $evento->recebido_em->format('d/m/Y') }}</td>
                                                        <td class="text-end small">{{ number_format((float) $evento->quantidade_recebida, 3, ',', '.') }}</td>
                                                        <td class="small">{{ $evento->registradoPor ? "{$evento->registradoPor->first_name} {$evento->registradoPor->last_name}" : 'Usuário removido' }}</td>
                                                        <td class="small text-muted">{{ $evento->observacao ?? '—' }}</td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            @else
                                            <p class="text-muted small mb-0 mt-2">Nenhum recebimento registrado ainda para este item.</p>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endif
                                @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">Nenhum item adicionado ainda.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($pedido->status->value === 'rascunho')
                        @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'editar'))
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-7">
                                <label class="form-label small">Item da RC (saldo disponível)</label>
                                <select class="form-select form-select-sm" wire:model="pedidoItemRcItemIdNovo">
                                    <option value="">Selecione</option>
                                    @foreach($this->rcItensComSaldoParaPedido as $rcItem)
                                    <option value="{{ $rcItem->id }}">
                                        {{ $rcItem->descricao_snapshot }} — saldo {{ number_format($rcItem->saldo_para_pedido, 3, ',', '.') }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Quantidade</label>
                                <input type="text" class="form-control form-control-sm" wire:model="pedidoItemQuantidadeNovo">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-sm btn-primary w-100" wire:click="adicionarItemPedido">Adicionar</button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-success" wire:click="emitirPedido">
                            <i class="bx bx-send me-1"></i>Emitir Pedido/Ordem de Compra
                        </button>
                        @endif
                        @if(Auth::user()->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'excluir'))
                        <button type="button" class="btn btn-outline-danger ms-2"
                                onclick="confirmarAcao(this, { mensagem: 'Excluir este rascunho de Pedido/Ordem de Compra?', metodo: 'excluirPedidoRascunho', args: ['{{ $pedido->id }}'], icone: 'bx-trash' })">
                            Excluir Rascunho
                        </button>
                        @endif
                    @endif
                    @endif
                    @endif

                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="fecharModalRc">Fechar</button>
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Criar/Editar item de suprimento --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($modalItemAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx {{ $editandoItemId ? 'bx-pencil' : 'bx-plus' }} me-2"></i>
                        {{ $editandoItemId ? 'Editar Item de Suprimento' : 'Novo Item de Suprimento' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalItemAberto', false)"></button>
                </div>
                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('nomeNovo') is-invalid @enderror" wire:model="nomeNovo">
                        @error('nomeNovo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Código <span class="text-muted">(opcional)</span></label>
                            <input type="text" class="form-control" wire:model="codigoNovo">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fluxo de Suprimento <span class="text-danger">*</span></label>
                            @if($editandoItemId)
                            <div class="form-control bg-light text-muted">
                                {{ $this->fluxos->firstWhere('id', $fluxoIdNovo)?->nome ?? '—' }}
                            </div>
                            <small class="text-muted">O fluxo não pode ser trocado depois de criado.</small>
                            @else
                            <select class="form-select @error('fluxoIdNovo') is-invalid @enderror" wire:model="fluxoIdNovo">
                                <option value="">— Selecione —</option>
                                @foreach($this->fluxos as $fx)
                                <option value="{{ $fx->id }}">{{ $fx->nome }}</option>
                                @endforeach
                            </select>
                            @error('fluxoIdNovo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @endif
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Fornecedor <span class="text-muted">(opcional)</span></label>
                        <select class="form-select" wire:model="fornecedorIdNovo">
                            <option value="">— Sem fornecedor —</option>
                            @foreach($this->fornecedores as $f)
                            <option value="{{ $f->id }}">{{ $f->nome }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Atividades vinculadas --}}
                    <div class="mb-3">
                        <label class="form-label">
                            Atividade(s) do cronograma <span class="text-danger">*</span>
                            <small class="text-muted">— a necessidade do item é a mais cedo entre as selecionadas</small>
                        </label>
                        <div class="border rounded p-2 @error('atividadesIdsNovo') is-invalid border-danger @enderror">
                            @if($this->atividadesSelecionadasNovo->isNotEmpty())
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                @foreach($this->atividadesSelecionadasNovo as $sel)
                                <span class="badge bg-label-primary d-flex align-items-center gap-1">
                                    {{ $sel->nome }}
                                    <i class="bx bx-x" style="cursor:pointer" wire:click="toggleAtividadeSelecionada('{{ $sel->id }}')"></i>
                                </span>
                                @endforeach
                            </div>
                            @endif
                            <input type="text" class="form-control form-control-sm mb-2"
                                   wire:model.live.debounce.300ms="buscaAtividadeNovo"
                                   placeholder="Buscar atividade pelo nome...">
                            <div style="max-height:160px; overflow-y:auto">
                                @forelse($this->atividadesParaSelecao as $at)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="satv_{{ $at->id }}"
                                           @checked(in_array($at->id, $atividadesIdsNovo))
                                           wire:click="toggleAtividadeSelecionada('{{ $at->id }}')">
                                    <label class="form-check-label" for="satv_{{ $at->id }}">
                                        {{ $at->nome }}
                                        @if($at->inicio_planejado)
                                        <small class="text-muted">({{ $at->inicio_planejado->format('d/m/Y') }})</small>
                                        @endif
                                    </label>
                                </div>
                                @empty
                                <small class="text-muted">Nenhuma atividade encontrada.</small>
                                @endforelse
                            </div>
                        </div>
                        @error('atividadesIdsNovo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    {{-- Documentos de engenharia vinculados --}}
                    <div class="mb-3">
                        <label class="form-label">Documentos de Engenharia <span class="text-muted">(opcional)</span></label>
                        <div class="border rounded p-2">
                            @if($this->documentosSelecionadosNovo->isNotEmpty())
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                @foreach($this->documentosSelecionadosNovo as $sel)
                                <span class="badge bg-label-info d-flex align-items-center gap-1">
                                    {{ $sel->descricao }}
                                    <i class="bx bx-x" style="cursor:pointer" wire:click="toggleDocumentoSelecionado('{{ $sel->id }}')"></i>
                                </span>
                                @endforeach
                            </div>
                            @endif
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <select class="form-select form-select-sm" wire:model.live="pacoteEngenhariaFiltroNovo">
                                        <option value="">Todos os pacotes de engenharia</option>
                                        @foreach($this->pacotesEngenharia as $pac)
                                        <option value="{{ $pac->id }}">{{ $pac->nome }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm"
                                           wire:model.live.debounce.300ms="buscaDocumentoNovo"
                                           placeholder="Buscar documento...">
                                </div>
                            </div>
                            <div style="max-height:140px; overflow-y:auto">
                                @forelse($this->documentosParaSelecao as $doc)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sdoc_{{ $doc->id }}"
                                           @checked(in_array($doc->id, $documentosIdsNovo))
                                           wire:click="toggleDocumentoSelecionado('{{ $doc->id }}')">
                                    <label class="form-check-label" for="sdoc_{{ $doc->id }}">{{ $doc->descricao }}</label>
                                </div>
                                @empty
                                <small class="text-muted">Nenhum documento encontrado.</small>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Observações <span class="text-muted">(opcional)</span></label>
                        <textarea class="form-control" rows="2" wire:model="observacoesNovo"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="$set('modalItemAberto', false)">Cancelar</button>
                    <button class="btn btn-primary" wire:click="salvarItem" wire:loading.attr="disabled">
                        <i class="bx bx-check me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Offcanvas de filtros — mesmo mecanismo do Lookahead/Restrições --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="canva-filtros-suprimentos" :class="filtrosAbertos ? 'canva-filtros-suprimentos-aberto' : ''" x-data="{ filtrosAbertos: false }">
        <button type="button" class="canva-filtros-suprimentos-aba" @click="filtrosAbertos = true" title="Filtros">
            <i class="bx bx-filter-alt"></i>
        </button>

        <div class="canva-filtros-suprimentos-header d-flex align-items-center justify-content-between border-bottom px-4 py-3">
            <h6 class="mb-0 fw-semibold"><i class="bx bx-filter-alt me-1"></i>Filtros</h6>
            <a href="javascript:void(0)" class="text-body" @click="filtrosAbertos = false">
                <i class="bx bx-x fs-4"></i>
            </a>
        </div>

        <div class="canva-filtros-suprimentos-body px-4 py-3">
            <div class="row g-2">
                <div class="col-12">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                        <input type="text" class="form-control" placeholder="Buscar item..." wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="fluxoIdFiltro">
                        <option value="">Todos os fluxos</option>
                        @foreach($this->fluxos as $fx)
                        <option value="{{ $fx->id }}">{{ $fx->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="fornecedorIdFiltro">
                        <option value="">Todos os fornecedores</option>
                        @foreach($this->fornecedores as $f)
                        <option value="{{ $f->id }}">{{ $f->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="statusFiltro">
                        <option value="">Todos os status</option>
                        @foreach(\App\Enums\StatusItemSuprimento::cases() as $st)
                        <option value="{{ $st->value }}">{{ $st->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="disciplinaIdFiltro">
                        <option value="">Todas as disciplinas</option>
                        @foreach($this->disciplinas as $d)
                        <option value="{{ $d->id }}">{{ $d->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="frenteTrabalhoIdFiltro">
                        <option value="">Todas as frentes de trabalho</option>
                        @foreach($this->frentesTrabalho as $ft)
                        <option value="{{ $ft->id }}">{{ $ft->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="faturamentoDiretoFiltro">
                        <option value="">Faturamento Direto: Todos</option>
                        <option value="1">Faturamento Direto: Sim</option>
                        <option value="0">Faturamento Direto: Não</option>
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="entregavelIdFiltro">
                        <option value="">Todos os entregáveis</option>
                        @foreach($this->entregaveis as $en)
                        <option value="{{ $en->id }}">{{ $en->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="equipeResponsavelIdFiltro">
                        <option value="">Todas as equipes/responsáveis</option>
                        @foreach($this->equipesResponsaveis as $eq)
                        <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado1IdFiltro">
                        <option value="">Personalizado 1: Todos</option>
                        @foreach($this->personalizados1 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado2IdFiltro">
                        <option value="">Personalizado 2: Todos</option>
                        @foreach($this->personalizados2 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado3IdFiltro">
                        <option value="">Personalizado 3: Todos</option>
                        @foreach($this->personalizados3 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado4IdFiltro">
                        <option value="">Personalizado 4: Todos</option>
                        @foreach($this->personalizados4 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado5IdFiltro">
                        <option value="">Personalizado 5: Todos</option>
                        @foreach($this->personalizados5 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <small class="text-muted d-block mb-1">Janela de necessidade:</small>
                    <div class="row g-1">
                        <div class="col-6">
                            <input type="date" class="form-control form-control-sm" wire:model.live="necessidadeDe">
                        </div>
                        <div class="col-6">
                            <input type="date" class="form-control form-control-sm" wire:model.live="necessidadeAte">
                        </div>
                    </div>
                </div>
                <div class="col-12"><hr class="my-1"></div>
                @if($this->itensFiltrados->isNotEmpty())
                <div class="col-12">
                    <button class="btn btn-outline-danger btn-sm w-100" wire:click="exportarPdf">
                        <i class="bx bxs-file-pdf me-1"></i>PDF
                    </button>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-success btn-sm w-100" wire:click="exportarExcel">
                        <i class="bx bxs-file-export me-1"></i>Excel
                    </button>
                </div>
                @endif
                <div class="col-12">
                    <button class="btn btn-outline-secondary btn-sm w-100" wire:click="limparFiltros">
                        <i class="bx bx-x me-1"></i>Limpar filtros
                    </button>
                </div>
            </div>
        </div>

        <style>
        /* Bug de teste manual, 2026-09-02 — `top: 0` fazia o canva cobrir a
           faixa da navbar fixa (0 a 3.875rem, = $navbar-height do tema);
           como o z-index do canva é maior, um clique no sino/perfil/
           app-grid nessa faixa era engolido pelo canva aberto (mesmo bug
           nos 8 arquivos que usam este padrão — ver ⚡restricoes.blade.php
           pro diagnóstico completo). Corrigido começando o canva abaixo
           da navbar. */
        .canva-filtros-suprimentos {
            position: fixed;
            top: 3.875rem;
            right: -360px;
            height: calc(100% - 3.875rem);
            z-index: 1080;
            display: flex;
            flex-direction: column;
            width: 360px;
            max-width: 90vw;
            background: var(--bs-body-bg, #fff);
            box-shadow: 0 0 20px 0 rgba(0, 0, 0, .2);
            transition: right .25s ease-in-out;
        }
        .canva-filtros-suprimentos.canva-filtros-suprimentos-aberto { right: 0; }
        .canva-filtros-suprimentos-body { flex: 1 1 auto; overflow-y: auto; }
        .canva-filtros-suprimentos-aba {
            position: absolute;
            top: 140px;
            left: -42px;
            width: 42px;
            height: 42px;
            border: 0;
            border-top-left-radius: .375rem;
            border-bottom-left-radius: .375rem;
            background: var(--bs-primary);
            color: #fff;
            font-size: 1.1rem;
            box-shadow: -2px 0 8px rgba(0, 0, 0, .15);
            transition: opacity .15s linear;
        }
        .canva-filtros-suprimentos.canva-filtros-suprimentos-aberto .canva-filtros-suprimentos-aba {
            opacity: 0;
            pointer-events: none;
        }
        @media (max-width: 575.98px) {
            .canva-filtros-suprimentos { width: 300px; right: -300px; }
            .canva-filtros-suprimentos.canva-filtros-suprimentos-aberto { right: 0; }
        }
        .suprimento-timeline-item {
            display: flex;
            align-items: stretch;
            gap: .85rem;
        }
        .suprimento-timeline-marcador {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
        }
        .suprimento-timeline-circulo {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            flex-shrink: 0;
        }
        .suprimento-timeline-linha {
            width: 2px;
            flex: 1 1 auto;
            min-height: 16px;
            background: var(--bs-border-color, #dee2e6);
            margin: 2px 0;
        }
        .suprimento-timeline-conteudo {
            padding-bottom: 1.1rem;
        }
        </style>
    </div>

</div>

@script
<script>
    $wire.on('show-toast', ({ message, type = 'success' }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            (toastr[type] || toastr.success)(message);
        }
    });
</script>
@endscript
