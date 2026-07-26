<?php

use App\Enums\SerieAvanco;
use App\Enums\StatusItemSuprimento;
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

  public function mount(Work $obra): void
  {
    $this->obra = $obra;
  }

  private function garantirPermissao(string $acao): void
  {
    abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'suprimentos.mapa', $acao), 403);
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

    $item = ItemSuprimento::findOrFail($id);

    $this->transacaoSegura(function () use ($item) {
      SincronizarRestricaoSuprimento::resolverTudo($item, Auth::id());
      $item->delete();
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    unset($this->itensFiltrados, $this->totais);
    $this->dispatch('show-toast', message: 'Item de suprimento removido.');
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
        .canva-filtros-suprimentos {
            position: fixed;
            top: 0;
            right: -360px;
            height: 100%;
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
