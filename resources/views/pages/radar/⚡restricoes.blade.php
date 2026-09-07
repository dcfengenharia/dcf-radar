<?php

use App\Enums\PilarLean;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Exports\RestricoesExport;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\CategoriaRestricao;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\FrenteTrabalho;
use App\Models\ItemProntidao;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\Restricao;
use App\Models\Work;
use App\Notifications\RestricoesPendentesNotification;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
  use WithPagination, ExecutaComTransacaoSegura;
  protected $paginationTheme = 'bootstrap';

  public Work $obra;

  // ---- Filtros ----
  public string $filtroStatus = '';
  public string $filtroPilar = '';
  public string $filtroCategoriaId = '';
  public bool $apenasBloqueantes = false;
  public string $search = '';
  public string $filtroDataInicioAtiv = '';
  public string $filtroDataFimAtiv = '';
  #[Url(as: 'responsavel')]
  public ?string $filtroResponsavelId = null;
  public ?string $filtroDisciplinaId = null;
  public ?string $filtroFrenteTrabalhoId = null;
  public ?string $filtroFaturamentoDireto = null; // '' = todos | '1' = sim | '0' = não
  public ?string $filtroEntregavelId = null;
  public ?string $filtroEquipeResponsavelId = null;
  public ?string $filtroPersonalizado1Id = null;
  public ?string $filtroPersonalizado2Id = null;
  public ?string $filtroPersonalizado3Id = null;
  public ?string $filtroPersonalizado4Id = null;
  public ?string $filtroPersonalizado5Id = null;

  // ---- Ordenação / paginação ----
  public string $sortField = 'prazo_limite';
  public string $sortDir = 'asc';
  public int $perPage = 15;

  // ---- Vista prontidão por atividade ----
  public bool $agruparPorAtividade = false;
  public ?string $atividadeExpandida = null;

  // ---- Modal criar/editar (campos compartilhados) ----
  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public array $atividadesIdsNova = [];
  public string $buscaAtividadeNova = '';
  public string $descricaoNova = '';
  public bool $blocanteNova = true;
  public ?string $prazolimiteNova = null;
  public ?int $probabilidadeNova = null;
  public ?int $impactoNova = null;
  public ?string $categoriaIdNova = null;
  public ?string $responsavelIdNova = null;
  public string $responsavelExternoNova = '';
  public bool $responsavelExterno = false;

  // ---- Modal detalhe da atividade ----
  public ?string $modalAtividadeId = null;

  // ---- Modal resolução ----
  public ?string $resolvendoId = null;
  public string $acaoTexto = '';

  // ---- Modal comentários ----
  public ?string $comentandoId = null;
  public string $comentarioTexto = '';

  public function mount(Work $obra): void
  {
    $this->obra = $obra;
  }

  // =========================================================================
  // COMPUTED
  // =========================================================================

  private function queryRestricoesFiltrada(): \Illuminate\Database\Eloquent\Builder
  {
    // JOIN direto substitui whereHas (subquery): 3-5× mais rápido com os índices criados.
    // Os filtros de data da atividade também ficam no JOIN, sem subquery adicional.
    return Restricao::with([
      'atividade:id,nome,obra_id,caminho_critico',
      'categoria:id,nome,pilar_lean',
      'responsavel:id,first_name,last_name',
    ])
      ->join('atividades as a', function ($join) {
        $join
          ->on('restricoes.atividade_id', '=', 'a.id')
          ->whereNull('a.deleted_at')
          ->where('a.obra_id', $this->obra->id);
      })
      ->select('restricoes.*')
      ->when($this->filtroStatus, fn($q) => $q->where('restricoes.status', $this->filtroStatus))
      ->when($this->apenasBloqueantes, fn($q) => $q->where('restricoes.bloqueante', true))
      ->when($this->filtroCategoriaId, fn($q) => $q->where('restricoes.categoria_id', $this->filtroCategoriaId))
      ->when(
        $this->filtroPilar,
        fn($q) => $q->whereHas('categoria', fn($c) => $c->where('pilar_lean', $this->filtroPilar))
      )
      ->when($this->search, fn($q) => $q->where('restricoes.descricao', 'like', "%{$this->search}%"))
      ->when($this->filtroDataInicioAtiv, fn($q) => $q->where('a.inicio_planejado', '>=', $this->filtroDataInicioAtiv))
      ->when($this->filtroDataFimAtiv, fn($q) => $q->where('a.data_termino', '<=', $this->filtroDataFimAtiv))
      ->when($this->filtroResponsavelId, fn($q) => $q->where('restricoes.responsavel_id', $this->filtroResponsavelId))
      ->when($this->filtroDisciplinaId, fn($q) => $q->where('a.disciplina_id', $this->filtroDisciplinaId))
      ->when($this->filtroFrenteTrabalhoId, fn($q) => $q->where('a.frente_trabalho_id', $this->filtroFrenteTrabalhoId))
      ->when(
        $this->filtroFaturamentoDireto !== null && $this->filtroFaturamentoDireto !== '',
        fn($q) => $q->where('a.faturamento_direto', $this->filtroFaturamentoDireto === '1')
      )
      ->when($this->filtroEntregavelId, fn($q) => $q->where('a.entregavel_id', $this->filtroEntregavelId))
      ->when(
        $this->filtroEquipeResponsavelId,
        fn($q) => $q->where('a.equipe_responsavel_id', $this->filtroEquipeResponsavelId)
      )
      ->when($this->filtroPersonalizado1Id, fn($q) => $q->where('a.personalizado_1_id', $this->filtroPersonalizado1Id))
      ->when($this->filtroPersonalizado2Id, fn($q) => $q->where('a.personalizado_2_id', $this->filtroPersonalizado2Id))
      ->when($this->filtroPersonalizado3Id, fn($q) => $q->where('a.personalizado_3_id', $this->filtroPersonalizado3Id))
      ->when($this->filtroPersonalizado4Id, fn($q) => $q->where('a.personalizado_4_id', $this->filtroPersonalizado4Id))
      ->when($this->filtroPersonalizado5Id, fn($q) => $q->where('a.personalizado_5_id', $this->filtroPersonalizado5Id));
  }

  private function aplicarOrdenacao(\Illuminate\Database\Eloquent\Builder $q): void
  {
    match ($this->sortField) {
      'risco' => $q->orderByRaw(
        "(COALESCE(restricoes.probabilidade,0)*COALESCE(restricoes.impacto,0)) {$this->sortDir}"
      ),
      'status' => $q->orderByRaw(
        "FIELD(restricoes.status,'aberta','em_tratamento','aguardando_terceiros','resolvida') " .
          ($this->sortDir === 'asc' ? 'ASC' : 'DESC')
      ),
      'prazo_limite' => $q->orderByRaw(
        "CASE WHEN restricoes.prazo_limite IS NULL THEN 1 ELSE 0 END ASC, restricoes.prazo_limite {$this->sortDir}"
      ),
      default => $q->orderBy("restricoes.{$this->sortField}", $this->sortDir),
    };

    if ($this->sortField !== 'prazo_limite') {
      $q->orderByRaw('CASE WHEN restricoes.prazo_limite IS NULL THEN 1 ELSE 0 END ASC, restricoes.prazo_limite ASC');
    }
  }

  #[Computed]
  public function restricoes()
  {
    $q = $this->queryRestricoesFiltrada();
    $this->aplicarOrdenacao($q);

    return $q->paginate($this->perPage);
  }

  public function exportarExcel()
  {
    $q = $this->queryRestricoesFiltrada()->with(['atividade.disciplina', 'atividade.frenteTrabalho']);
    $this->aplicarOrdenacao($q);

    return Excel::download(new RestricoesExport($q->get()), "restricoes-{$this->obra->id}.xlsx");
  }

  #[Computed]
  public function disciplinas()
  {
    return Disciplina::orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function entregaveis()
  {
    return Entregavel::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function equipesResponsaveis()
  {
    return EquipeResponsavel::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados1()
  {
    return Personalizado1::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados2()
  {
    return Personalizado2::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados3()
  {
    return Personalizado3::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados4()
  {
    return Personalizado4::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados5()
  {
    return Personalizado5::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function frentesTrabalho()
  {
    return FrenteTrabalho::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function totais(): array
  {
    // 4 queries → 1 query com SUM condicional
    $row = Restricao::join('atividades as a', function ($join) {
      $join
        ->on('restricoes.atividade_id', '=', 'a.id')
        ->whereNull('a.deleted_at')
        ->where('a.obra_id', $this->obra->id);
    })
      ->selectRaw(
        "
                SUM(restricoes.status = 'aberta') as aberta,
                SUM(restricoes.status IN ('em_tratamento','aguardando_terceiros')) as em_tratamento,
                SUM(restricoes.bloqueante = 1
                    AND restricoes.status IN ('aberta','em_tratamento','aguardando_terceiros')) as bloqueantes,
                SUM(restricoes.status = 'resolvida') as resolvidas
            "
      )
      ->first();

    return [
      'aberta' => (int) ($row->aberta ?? 0),
      'em_tratamento' => (int) ($row->em_tratamento ?? 0),
      'bloqueantes' => (int) ($row->bloqueantes ?? 0),
      'resolvidas' => (int) ($row->resolvidas ?? 0),
    ];
  }

  // Os três computed abaixo são dados "estáticos" (mudam raramente).
  // Cache de 90s evita re-query a cada filtro mudado — maior ganho de responsividade.

  #[Computed]
  public function atividadesParaSelecao()
  {
    return Atividade::where('obra_id', $this->obra->id)
      ->where('fora_do_cronograma', false)
      ->when($this->buscaAtividadeNova, fn($q) => $q->where('nome', 'like', "%{$this->buscaAtividadeNova}%"))
      ->orderBy('nome')
      ->limit(30)
      ->get(['id', 'nome', 'inicio_planejado', 'data_termino']);
  }

  #[Computed]
  public function atividadesSelecionadasNova()
  {
    if (empty($this->atividadesIdsNova)) {
      return collect();
    }

    return Atividade::whereIn('id', $this->atividadesIdsNova)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function categorias()
  {
    return Cache::remember(
      "tenant_{$this->obra->tenant_id}_categorias",
      300,
      fn() => CategoriaRestricao::orderBy('nome')->get(['id', 'nome', 'pilar_lean'])
    );
  }

  #[Computed]
  public function usuariosDaObra()
  {
    return Cache::remember(
      "obra_{$this->obra->id}_usuarios",
      90,
      fn() => $this->obra
        ->users()
        ->orderBy('users.first_name')
        ->get(['users.id', 'users.first_name', 'users.last_name'])
    );
  }

  #[Computed]
  public function itensProntidao()
  {
    return Cache::remember(
      "obra_{$this->obra->id}_itens_prontidao",
      90,
      fn() => ItemProntidao::where('obra_id', $this->obra->id)
        ->orderBy('ordem')
        ->get()
    );
  }

  #[Computed]
  public function atividadesComStatus()
  {
    // withCount substitui with+count: 1 query em vez de 1 por atividade (N+1 eliminado)
    $atividades = Atividade::where('obra_id', $this->obra->id)
      ->where('fora_do_cronograma', false)
      ->withCount([
        'restricoes as restricoes_bloqueantes' => fn($q) => $q
          ->where('bloqueante', true)
          ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros']),
      ])
      ->orderBy('nome')
      ->get(['id', 'nome']);

    $totalItens = $this->itensProntidao->count();

    // 1 query agregada para todos os checklists (substitui N COUNT queries)
    $itensOkMap =
      $totalItens > 0
        ? AtividadeItemProntidao::whereIn('atividade_id', $atividades->pluck('id'))
          ->where('concluido', true)
          ->selectRaw('atividade_id, COUNT(*) as total')
          ->groupBy('atividade_id')
          ->pluck('total', 'atividade_id')
        : collect();

    return $atividades->map(function ($at) use ($totalItens, $itensOkMap) {
      $bloq = $at->restricoes_bloqueantes;
      $itensOk = $totalItens > 0 ? (int) ($itensOkMap->get($at->id) ?? 0) : $totalItens;

      return [
        'id' => $at->id,
        'nome' => $at->nome,
        'restricoesBloq' => $bloq,
        'itensPendentes' => max(0, $totalItens - $itensOk),
        'pronta' => $bloq === 0 && ($totalItens === 0 || $itensOk >= $totalItens),
      ];
    });
  }

  #[Computed]
  public function atividadeDetalhe()
  {
    if (!$this->modalAtividadeId) {
      return null;
    }

    $at = Atividade::with([
      'restricoes' => fn($q) => $q
        ->with([
          'categoria:id,nome',
          'responsavel:id,first_name,last_name',
          'acoes' => fn($a) => $a->with('autor:id,first_name,last_name')->latest(),
        ])
        ->orderByRaw("FIELD(status,'aberta','em_tratamento','aguardando_terceiros','resolvida')")
        ->orderBy('prazo_limite'),
      'pacoteTrabalho:id,nome',
    ])->find($this->modalAtividadeId);

    if (!$at) {
      return null;
    }

    $itens = $this->itensProntidao;
    $registros = $itens->isNotEmpty()
      ? AtividadeItemProntidao::where('atividade_id', $at->id)
        ->with('conclusor:id,first_name,last_name')
        ->get()
        ->keyBy('item_prontidao_id')
      : collect();

    // Linha de Base: sempre a última importação Baseline/Ambos da obra —
    // mesmo "ao vivo" default do Lookahead sem Linha de Base explícita
    // selecionada. As datas em si já vêm prontas em baseline_inicio/termino
    // (gravadas pelo importador junto com a última importação desse tipo).
    $baselineImportacaoAtual = CronogramaImportacao::where('obra_id', $at->obra_id)
      ->whereIn('tipo', [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value])
      ->orderByDesc('importado_em')
      ->orderByDesc('id')
      ->first();

    // Tendência: última importação de Avanço/Ambos da obra (mesmo filtro de
    // CurvaAvanco::resolverImportacaoId() e do seletor do Lookahead) — sem
    // nenhuma, não há de onde vir tendência (N/A na view), nunca cai pros
    // campos ao vivo da Atividade (que refletem só a última Linha de Base).
    $tendenciaImportacaoAtual = CronogramaImportacao::where('obra_id', $at->obra_id)
      ->whereIn('tipo', [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value])
      ->orderByDesc('importado_em')
      ->orderByDesc('id')
      ->first();

    $snapshotTendencia = $tendenciaImportacaoAtual
      ? AtividadeSnapshot::where('cronograma_importacao_id', $tendenciaImportacaoAtual->id)
        ->where('atividade_id', $at->id)
        ->first()
      : null;

    return [
      'atividade' => $at,
      'baselineImportacaoAtual' => $baselineImportacaoAtual,
      'tendenciaImportacaoAtual' => $tendenciaImportacaoAtual,
      'inicioTendencia' => $snapshotTendencia?->inicio_planejado,
      'terminoTendencia' => $snapshotTendencia?->data_termino,
      'checklist' => $itens->map(function ($item) use ($registros) {
        $registro = $registros->get($item->id);
        return [
          'id' => $item->id,
          'nome' => $item->nome,
          'concluido' => (bool) ($registro?->concluido ?? false),
          'concluidoPor' => $registro?->conclusor,
          'concluidoEm' => $registro?->concluido_em,
        ];
      }),
    ];
  }

  public function temFiltrosAtivos(): bool
  {
    return $this->filtroStatus ||
      $this->filtroPilar ||
      $this->filtroCategoriaId ||
      $this->apenasBloqueantes ||
      $this->search ||
      $this->filtroDataInicioAtiv ||
      $this->filtroDataFimAtiv ||
      $this->filtroResponsavelId ||
      $this->filtroDisciplinaId ||
      $this->filtroFrenteTrabalhoId ||
      ($this->filtroFaturamentoDireto !== null && $this->filtroFaturamentoDireto !== '') ||
      $this->filtroEntregavelId ||
      $this->filtroEquipeResponsavelId ||
      $this->filtroPersonalizado1Id ||
      $this->filtroPersonalizado2Id ||
      $this->filtroPersonalizado3Id ||
      $this->filtroPersonalizado4Id ||
      $this->filtroPersonalizado5Id;
  }

  // =========================================================================
  // FILTROS E ORDENAÇÃO
  // =========================================================================

  public function updatedSearch(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroStatus(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroPilar(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroCategoriaId(): void
  {
    $this->resetPage();
  }
  public function updatedApenasBloqueantes(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroDataInicioAtiv(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroDataFimAtiv(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroResponsavelId(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroDisciplinaId(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroFrenteTrabalhoId(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroFaturamentoDireto(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroEntregavelId(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroEquipeResponsavelId(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroPersonalizado1Id(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroPersonalizado2Id(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroPersonalizado3Id(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroPersonalizado4Id(): void
  {
    $this->resetPage();
  }
  public function updatedFiltroPersonalizado5Id(): void
  {
    $this->resetPage();
  }
  public function updatedPerPage(): void
  {
    $this->resetPage();
  }

  public function ordenarPor(string $campo): void
  {
    $this->sortDir = $this->sortField === $campo && $this->sortDir === 'asc' ? 'desc' : 'asc';
    $this->sortField = $campo;
    $this->resetPage();
    unset($this->restricoes);
  }

  public function limparFiltros(): void
  {
    $this->filtroStatus = '';
    $this->filtroPilar = '';
    $this->filtroCategoriaId = '';
    $this->apenasBloqueantes = false;
    $this->search = '';
    $this->filtroDataInicioAtiv = '';
    $this->filtroDataFimAtiv = '';
    $this->filtroResponsavelId = null;
    $this->filtroDisciplinaId = null;
    $this->filtroFrenteTrabalhoId = null;
    $this->filtroFaturamentoDireto = null;
    $this->filtroEntregavelId = null;
    $this->filtroEquipeResponsavelId = null;
    $this->filtroPersonalizado1Id = null;
    $this->filtroPersonalizado2Id = null;
    $this->filtroPersonalizado3Id = null;
    $this->filtroPersonalizado4Id = null;
    $this->filtroPersonalizado5Id = null;
    $this->resetPage();
    unset($this->restricoes);
  }

  // =========================================================================
  // VISTA PRONTIDÃO
  // =========================================================================

  public function expandirAtividade(string $atividadeId): void
  {
    if ($this->atividadeExpandida === $atividadeId) {
      $this->atividadeExpandida = null;
      return;
    }
    $this->atividadeExpandida = $atividadeId;
    foreach ($this->itensProntidao as $item) {
      AtividadeItemProntidao::firstOrCreate(
        ['atividade_id' => $atividadeId, 'item_prontidao_id' => $item->id],
        ['concluido' => false]
      );
    }
  }

  public function marcarItemProntidao(string $atividadeId, string $itemId, bool $valor): void
  {
    $this->transacaoSegura(function () use ($atividadeId, $itemId, $valor) {
      AtividadeItemProntidao::updateOrCreate(
        ['atividade_id' => $atividadeId, 'item_prontidao_id' => $itemId],
        ['concluido' => $valor, 'concluido_por' => $valor ? Auth::id() : null, 'concluido_em' => $valor ? now() : null]
      );
    });
    unset($this->atividadesComStatus);
  }

  public function checklistDaAtividade(string $atividadeId): \Illuminate\Support\Collection
  {
    $registros = AtividadeItemProntidao::where('atividade_id', $atividadeId)
      ->get()
      ->keyBy('item_prontidao_id');
    return $this->itensProntidao->map(
      fn($item) => [
        'id' => $item->id,
        'nome' => $item->nome,
        'concluido' => $registros->get($item->id)?->concluido ?? false,
      ]
    );
  }

  // =========================================================================
  // MODAL DETALHE DA ATIVIDADE
  // =========================================================================

  public function verAtividade(string $atividadeId): void
  {
    $this->modalAtividadeId = $atividadeId;
    unset($this->atividadeDetalhe);
  }

  public function marcarItemNaDetalhe(string $atividadeId, string $itemId, bool $valor): void
  {
    $this->transacaoSegura(function () use ($atividadeId, $itemId, $valor) {
      AtividadeItemProntidao::updateOrCreate(
        ['atividade_id' => $atividadeId, 'item_prontidao_id' => $itemId],
        ['concluido' => $valor, 'concluido_por' => $valor ? Auth::id() : null, 'concluido_em' => $valor ? now() : null]
      );
    });
    unset($this->atividadeDetalhe, $this->atividadesComStatus);
  }

  // =========================================================================
  // MODAL CRIAR / EDITAR
  // =========================================================================

  public function abrirModalNova(?string $atividadeId = null): void
  {
    $this->resetModalNova();
    $this->atividadesIdsNova = $atividadeId ? [$atividadeId] : [];
    $this->modalAberto = true;
  }

  public function abrirModalEdicao(string $id): void
  {
    $r = Restricao::findOrFail($id);
    $this->resetModalNova();
    $this->editandoId = $id;
    $this->atividadesIdsNova = [$r->atividade_id];
    $this->descricaoNova = $r->descricao;
    $this->blocanteNova = $r->bloqueante;
    $this->prazolimiteNova = $r->prazo_limite?->format('Y-m-d');
    $this->probabilidadeNova = $r->probabilidade;
    $this->impactoNova = $r->impacto;
    $this->categoriaIdNova = $r->categoria_id;
    $this->responsavelIdNova = $r->responsavel_id;
    $this->responsavelExternoNova = $r->responsavel_externo ?? '';
    $this->responsavelExterno = filled($r->responsavel_externo);
    $this->modalAberto = true;
  }

  public function toggleAtividadeSelecionada(string $atividadeId): void
  {
    if (in_array($atividadeId, $this->atividadesIdsNova, true)) {
      $this->atividadesIdsNova = array_values(array_diff($this->atividadesIdsNova, [$atividadeId]));
    } else {
      $this->atividadesIdsNova[] = $atividadeId;
    }
    unset($this->atividadesSelecionadasNova);
  }

  public function resetModalNova(): void
  {
    $this->editandoId = null;
    $this->atividadesIdsNova = [];
    $this->buscaAtividadeNova = '';
    $this->descricaoNova = '';
    $this->blocanteNova = true;
    $this->prazolimiteNova = null;
    $this->probabilidadeNova = null;
    $this->impactoNova = null;
    $this->categoriaIdNova = null;
    $this->responsavelIdNova = null;
    $this->responsavelExternoNova = '';
    $this->responsavelExterno = false;
    $this->resetErrorBag();
  }

  public function salvarRestricao(): void
  {
    $this->validate(
      [
        'atividadesIdsNova' => 'required|array|min:1',
        'atividadesIdsNova.*' => 'exists:atividades,id',
        'descricaoNova' => 'required|string|min:5',
        'probabilidadeNova' => 'nullable|integer|min:0|max:10',
        'impactoNova' => 'nullable|integer|min:0|max:10',
        'prazolimiteNova' => 'nullable|date',
        'categoriaIdNova' => 'nullable|exists:categorias_restricao,id',
        'responsavelIdNova' => 'nullable|exists:users,id',
      ],
      [],
      [
        'atividadesIdsNova' => 'atividade',
        'descricaoNova' => 'descrição',
      ]
    );

    $dadosComuns = [
      'descricao' => $this->descricaoNova,
      'bloqueante' => $this->blocanteNova,
      'probabilidade' => $this->probabilidadeNova,
      'impacto' => $this->impactoNova,
      'prazo_limite' => $this->prazolimiteNova,
      'categoria_id' => $this->categoriaIdNova ?: null,
      'responsavel_id' => !$this->responsavelExterno ? ($this->responsavelIdNova ?: null) : null,
      'responsavel_externo' => $this->responsavelExterno ? ($this->responsavelExternoNova ?: null) : null,
    ];

    if ($this->editandoId) {
      $restricao = Restricao::findOrFail($this->editandoId);
      $this->authorize('update', $restricao);
      $msg = 'Restrição atualizada.';

      $this->transacaoSegura(fn() => $restricao->update($dadosComuns));
    } else {
      $this->authorize('create', [Restricao::class, $this->obra->id]);

      $qtd = count($this->atividadesIdsNova);
      $msg = $qtd > 1 ? "{$qtd} restrições registradas (uma por atividade selecionada)." : 'Restrição registrada.';

      $this->transacaoSegura(function () use ($dadosComuns) {
        foreach ($this->atividadesIdsNova as $atividadeId) {
          Restricao::create([
            ...$dadosComuns,
            'atividade_id' => $atividadeId,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
          ]);
        }
      });
    }

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->modalAberto = false;
    $this->resetModalNova();
    $this->dispatch('show-toast', message: $msg);
    unset($this->restricoes, $this->totais, $this->atividadesComStatus);
  }

  public function excluirRestricao(string $id): void
  {
    $restricao = Restricao::findOrFail($id);
    $this->authorize('delete', $restricao);

    $this->transacaoSegura(fn() => $restricao->delete());

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    unset($this->restricoes, $this->totais, $this->atividadesComStatus);
    $this->dispatch('show-toast', message: 'Restrição removida.');
  }

  // =========================================================================
  // AÇÕES DE STATUS
  // =========================================================================

  public function marcarEmTratamento(string $id): void
  {
    $r = Restricao::findOrFail($id);
    $this->authorize('update', $r);

    $this->transacaoSegura(fn() => $r->update(['status' => StatusRestricao::EmTratamento->value]));

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->dispatch('show-toast', message: 'Status: Em Tratamento.');
    unset($this->restricoes, $this->totais);
  }

  public function marcarAguardandoTerceiros(string $id): void
  {
    $r = Restricao::findOrFail($id);
    $this->authorize('update', $r);

    $this->transacaoSegura(fn() => $r->update(['status' => StatusRestricao::AguardandoTerceiros->value]));

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->dispatch('show-toast', message: 'Status: Aguardando Terceiros.');
    unset($this->restricoes, $this->totais);
  }

  public function abrirModalResolucao(string $id): void
  {
    $this->resolvendoId = $id;
    $this->acaoTexto = '';
  }

  public function resolver(): void
  {
    $this->validate(['acaoTexto' => 'required|string|min:5'], [], ['acaoTexto' => 'descrição da ação']);

    $restricao = Restricao::findOrFail($this->resolvendoId);
    $this->authorize('resolver', $restricao);

    $this->transacaoSegura(function () use ($restricao) {
      $restricao->acoes()->create(['autor_id' => Auth::id(), 'descricao' => $this->acaoTexto]);
      $restricao->update(['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => now()]);
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resolvendoId = null;
    $this->acaoTexto = '';
    $this->dispatch('show-toast', message: 'Restrição resolvida!');
    unset($this->restricoes, $this->totais, $this->atividadesComStatus);
  }

  public function reabrirRestricao(string $id): void
  {
    $restricao = Restricao::findOrFail($id);
    $this->authorize('reabrir', $restricao);

    $this->transacaoSegura(function () use ($restricao) {
      $restricao->acoes()->create(['autor_id' => Auth::id(), 'descricao' => 'Restrição reaberta.']);
      $restricao->update(['status' => StatusRestricao::Aberta->value, 'resolvida_em' => null]);
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->dispatch('show-toast', message: 'Restrição reaberta.');
    unset($this->restricoes, $this->totais, $this->atividadesComStatus);
  }

  public function notificarResponsaveis(): void
  {
    $this->authorize('notificar', [Restricao::class, $this->obra->id]);

    $pendentes = Restricao::with(['atividade:id,nome,obra_id', 'responsavel'])
      ->join('atividades as a', function ($join) {
        $join
          ->on('restricoes.atividade_id', '=', 'a.id')
          ->whereNull('a.deleted_at')
          ->where('a.obra_id', $this->obra->id);
      })
      ->select('restricoes.*')
      ->where('restricoes.status', '!=', StatusRestricao::Resolvida->value)
      ->get();

    $comResponsavel = $pendentes->filter(fn(Restricao $r) => $r->responsavel_id !== null);
    $somenteExterno = $pendentes->count() - $comResponsavel->count();

    $notificados = 0;
    foreach ($comResponsavel->groupBy('responsavel_id') as $restricoesDoResponsavel) {
      $responsavel = $restricoesDoResponsavel->first()->responsavel;
      if (!$responsavel) {
        continue; // responsavel_id órfão — defensivo
      }
      $responsavel->notify(new RestricoesPendentesNotification($responsavel, $restricoesDoResponsavel, $this->obra));
      $notificados++;
    }

    $mensagem =
      $notificados > 0
        ? "{$notificados} responsável(is) notificado(s)."
        : 'Nenhum responsável com restrições pendentes para notificar.';
    if ($somenteExterno > 0) {
      $mensagem .= " ({$somenteExterno} restrição(ões) com responsável externo foram ignoradas.)";
    }

    $this->dispatch('show-toast', message: $mensagem);
  }

  // =========================================================================
  // MODAL COMENTÁRIOS
  // =========================================================================

  public function abrirModalComentarios(string $id): void
  {
    $this->comentandoId = $id;
    $this->comentarioTexto = '';
    $this->resetErrorBag();
  }

  #[Computed]
  public function restricaoComentada()
  {
    if (!$this->comentandoId) {
      return null;
    }

    return Restricao::with(['acoes' => fn($q) => $q->with('autor:id,first_name,last_name')->latest()])->find(
      $this->comentandoId
    );
  }

  public function adicionarComentario(): void
  {
    $this->validate(['comentarioTexto' => 'required|string|min:3'], [], ['comentarioTexto' => 'comentário']);

    $restricao = Restricao::findOrFail($this->comentandoId);
    $this->authorize('comentar', $restricao);

    $this->transacaoSegura(
      fn() => $restricao->acoes()->create(['autor_id' => Auth::id(), 'descricao' => $this->comentarioTexto])
    );

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->comentarioTexto = '';
    unset($this->restricaoComentada);
    $this->dispatch('show-toast', message: 'Comentário adicionado.');
  }
};
?>

<div>

{{-- Overlay de loading: aparece instantaneamente em qualquer ação Livewire --}}
<div wire:loading.flex class="position-fixed top-0 start-0 w-100 h-100 align-items-start justify-content-center"
     style="z-index:9999;background:rgba(255,255,255,.4);padding-top:80px">
    <div class="d-flex align-items-center gap-2 bg-white shadow rounded px-4 py-2 border">
        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        <span class="small text-muted">Atualizando...</span>
    </div>
</div>

{{-- =========================================================================
     CARDS DE TOTAIS (clicáveis — aplicam filtro rápido)
     ========================================================================= --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-border-shadow-danger h-100" style="cursor:pointer" wire:click="$set('filtroStatus','aberta')">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2 pb-1">
                <div class="avatar me-2">
                  <span class="avatar-initial rounded bg-label-danger"><i class="bx bx-error"></i></span>
                </div>
                <h4 class="display-6 fw-bold text-danger ms-1 mb-0">{{ $this->totais['aberta'] }}</h4>
              </div>
              <p class="mb-0">Restrições Abertas</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-border-shadow-warning h-100" style="cursor:pointer" wire:click="$set('filtroStatus','em_tratamento')">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2 pb-1">
                <div class="avatar me-2">
                  <span class="avatar-initial rounded bg-label-warning"><i class="bx bx-hourglass"></i></span>
                </div>
                <h4 class="display-6 fw-bold text-warning ms-1 mb-0">{{ $this->totais['em_tratamento'] }}</h4>
              </div>
              <p class="mb-0">Em Tratamento</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-border-shadow-danger h-100" style="cursor:pointer" wire:click="$set('apenasBloqueantes',true)">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2 pb-1">
                <div class="avatar me-2">
                  <span class="avatar-initial rounded bg-label-danger"><i class="bx bx-block"></i></span>
                </div>

                <h4 class="display-6 fw-bold text-danger ms-1 mb-0">{{ $this->totais['bloqueantes'] }}</h4>
              </div>
                <p class="mb-0">Bloqueantes abertas</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-border-shadow-success h-100" style="cursor:pointer" wire:click="$set('filtroStatus','resolvida')">

            <div class="card-body">
              <div class="d-flex align-items-center mb-2 pb-1">
                <div class="avatar me-2">
                  <span class="avatar-initial rounded bg-label-success"><i class="bx bx-check"></i></span>
                </div>

                <h4 class="display-6 fw-bold text-success ms-1 mb-0">{{ $this->totais['resolvidas'] }}</h4>
              </div>
                <p class="mb-0">Resolvidas</p>
            </div>

        </div>
    </div>
</div>


{{-- =========================================================================
     VISTA PRONTIDÃO POR ATIVIDADE
     ========================================================================= --}}
@if($agruparPorAtividade)

@if($this->itensProntidao->isEmpty())
<div class="alert alert-info d-flex gap-2 align-items-center">
    <i class="bx bx-info-circle fs-5"></i>
    <div>
        <strong>Nenhum item de prontidão configurado para esta obra.</strong>
        <a href="{{ route('cadastros.itens-prontidao') }}" class="ms-2">Configurar →</a>
    </div>
</div>
@endif

<div class="row g-2">
    @foreach($this->atividadesComStatus as $at)
    <div class="col-12">
        <div class="card {{ $at['pronta'] ? 'border-success' : 'border-warning' }}">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button class="btn btn-link p-0 text-start fw-semibold text-dark"
                            wire:click="verAtividade('{{ $at['id'] }}')">
                        {{ $at['nome'] }}
                    </button>
                    <div class="d-flex gap-2 align-items-center ms-auto flex-shrink-0 flex-wrap">
                        @if($at['restricoesBloq'] > 0)
                        <span class="badge bg-danger">{{ $at['restricoesBloq'] }} bloqueante(s)</span>
                        @endif
                        @if(!$this->itensProntidao->isEmpty())
                            @if($at['itensPendentes'] > 0)
                            <span class="badge bg-warning text-dark">{{ $at['itensPendentes'] }} item(ns) pendente(s)</span>
                            @else
                            <span class="badge bg-success"><i class="bx bx-check me-1"></i>Checklist OK</span>
                            @endif
                        @endif
                        <span class="badge {{ $at['pronta'] ? 'bg-success' : 'bg-warning text-dark' }}">
                            {{ $at['pronta'] ? '✅ Pronta' : '⚠ Pendente' }}
                        </span>
                        @if(!$this->itensProntidao->isEmpty())
                        <button class="btn btn-sm btn-outline-secondary py-0"
                                wire:click="expandirAtividade('{{ $at['id'] }}')">
                            <i class="bx {{ $atividadeExpandida === $at['id'] ? 'bx-chevron-up' : 'bx-chevron-down' }}"></i>
                        </button>
                        @endif
                        @can('create', [\App\Models\Restricao::class, $obra->id])
                        <button class="btn btn-sm btn-outline-warning py-0"
                                wire:click="abrirModalNova('{{ $at['id'] }}')">
                            <i class="bx bx-plus me-1"></i>Restrição
                        </button>
                        @endcan
                    </div>
                </div>

                @if($atividadeExpandida === $at['id'])
                <div class="mt-3 border-top pt-3">
                    <small class="text-muted fw-semibold d-block mb-2">Itens de Prontidão</small>
                    @foreach($this->checklistDaAtividade($at['id']) as $itemChk)
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox"
                               id="chk_{{ $at['id'] }}_{{ $itemChk['id'] }}"
                               @checked($itemChk['concluido'])
                               wire:click="marcarItemProntidao('{{ $at['id'] }}','{{ $itemChk['id'] }}',{{ $itemChk['concluido'] ? 'false' : 'true' }})">
                        <label class="form-check-label {{ $itemChk['concluido'] ? 'text-decoration-line-through text-muted' : '' }}"
                               for="chk_{{ $at['id'] }}_{{ $itemChk['id'] }}">
                            {{ $itemChk['nome'] }}
                        </label>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- =========================================================================
     VISTA PADRÃO: TABELA
     ========================================================================= --}}
@else

@if($this->restricoes->count() > 0)
<div class="card table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
            <tr>
                <th>
                    <span style="cursor:pointer" wire:click="ordenarPor('created_at')">
                        Atividade
                        <i class="bx {{ $sortField === 'created_at' ? ($sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort' }} ms-1"></i>
                    </span>
                </th>
                <th>Descrição</th>
                <th>Tipo</th>
                <th>Responsável</th>
                <th style="cursor:pointer;white-space:nowrap" wire:click="ordenarPor('prazo_limite')">
                    Prazo
                    <i class="bx {{ $sortField === 'prazo_limite' ? ($sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort' }} ms-1"></i>
                </th>
                <th class="text-center" style="cursor:pointer" wire:click="ordenarPor('risco')">
                    P×I
                    <i class="bx {{ $sortField === 'risco' ? ($sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort' }} ms-1"></i>
                </th>
                <th class="text-center" style="cursor:pointer" wire:click="ordenarPor('status')">
                    Status
                    <i class="bx {{ $sortField === 'status' ? ($sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort' }} ms-1"></i>
                </th>
                <th class="text-center" style="width:120px">Ações</th>
            </tr>
        </thead>
        <tbody>
            @foreach($this->restricoes as $r)
            @php
                $sv      = $r->status instanceof \App\Enums\StatusRestricao ? $r->status->value : $r->status;
                $aberta  = in_array($sv, ['aberta','em_tratamento','aguardando_terceiros']);
                $vencida = $r->prazo_limite && $r->prazo_limite->isPast() && $aberta;
                $risco   = ($r->probabilidade ?? 0) * ($r->impacto ?? 0);
                $rcor    = $risco >= 50 ? 'danger' : ($risco >= 25 ? 'warning' : 'success');
                $scfg    = match($sv) {
                    'aberta'               => ['cor'=>'danger',             'label'=>'Aberta'],
                    'em_tratamento'        => ['cor'=>'warning text-dark',  'label'=>'Em Tratamento'],
                    'aguardando_terceiros' => ['cor'=>'info text-dark',     'label'=>'Ag. Terceiros'],
                    'resolvida'            => ['cor'=>'success',            'label'=>'Resolvida'],
                    default                => ['cor'=>'secondary',          'label'=>$sv],
                };
            @endphp
            <tr class="{{ $r->bloqueante && $aberta ? 'table-danger' : ($vencida ? 'table-warning' : '') }}">

                {{-- Atividade (clicável → popup) --}}
                <td style="max-width:180px">
                    <button class="btn btn-link p-0 text-start fw-semibold text-dark lh-sm"
                            style="font-size:.875rem"
                            wire:click="verAtividade('{{ $r->atividade?->id }}')"
                            title="Ver detalhes da atividade">
                        {{ Str::limit($r->atividade?->nome ?? '—', 38) }}
                    </button>
                    @if($r->bloqueante && $aberta)
                    <span class="badge bg-danger d-block mt-1" style="font-size:.65rem">BLOQUEANTE</span>
                    @endif
                </td>

                {{-- Descrição --}}
                <td style="max-width:240px">
                    <span style="font-size:.875rem" title="{{ $r->descricao }}">
                        {{ Str::limit($r->descricao, 65) }}
                    </span>
                </td>

                {{-- Tipo --}}
                <td>
                    @if($r->categoria)
                    <span class="small fw-semibold">{{ $r->categoria->nome }}</span>
                    @else
                    <span class="text-muted small">—</span>
                    @endif
                </td>

                {{-- Responsável --}}
                <td>
                    @if($r->responsavel)
                    <div class="d-flex">
                      <div class="flex-shrink-0 me-3">
                        <div class="avatar">
                          <img src="{{ $r->responsavel ? $r->responsavel->profile_photo_url : asset('assets/img/avatars/1.png') }}" alt class="rounded-circle">
                        </div>
                      </div>
                      <div class="flex-grow-1">
                        <span class="fw-medium d-block">
                          {{ trim($r->responsavel->first_name . ' ' . $r->responsavel->last_name) }}
                        </span>
                        @php $obraAtualNavbar = \App\Support\ObraContext::current(); @endphp
                        @if ($obraAtualNavbar)
                        <small class="text-muted">
                          {{ $r->responsavel->perfilNaObra($obraAtualNavbar)?->nome }}
                        </small>
                        @endif
                      </div>
                    </div>
                    @elseif($r->responsavel_externo)
                    <small class="text-muted fst-italic">{{ $r->responsavel_externo }}</small>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>

                {{-- Prazo --}}
                <td style="white-space:nowrap">
                    @if($r->prazo_limite)
                    <span class="{{ $vencida ? 'text-danger fw-bold' : '' }}" style="font-size:.875rem">
                        {{ $r->prazo_limite->format('d/m/Y') }}
                        @if($vencida)<i class="bx bx-error-circle ms-1" title="Vencida"></i>@endif
                    </span>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>

                {{-- P×I --}}
                <td class="text-center">
                    @if($r->probabilidade !== null && $r->impacto !== null)
                    <span class="badge bg-{{ $rcor }}" title="P={{ $r->probabilidade }} × I={{ $r->impacto }} = {{ $risco }}">
                        {{ $r->probabilidade }}×{{ $r->impacto }}
                    </span>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>

                {{-- Status --}}
                <td class="text-center">
                    <span class="badge bg-{{ $scfg['cor'] }}">{{ $scfg['label'] }}</span>
                </td>

                {{-- Ações --}}
                <td class="text-center">
                    <div class="d-flex gap-1 justify-content-center flex-wrap">
                        @can('update', $r)
                        <button class="btn btn-xs btn-outline-secondary py-0 px-1"
                                wire:click="abrirModalEdicao('{{ $r->id }}')"
                                title="Editar">
                            <i class="bx bx-pencil"></i>
                        </button>
                        @endcan

                        @if($sv === 'aberta')
                        <button class="btn btn-xs btn-outline-warning py-0 px-1"
                                wire:click="marcarEmTratamento('{{ $r->id }}')"
                                title="Em tratamento">
                            <i class="bx bx-loader-circle"></i>
                        </button>
                        @elseif($sv === 'em_tratamento')
                        <button class="btn btn-xs btn-outline-info py-0 px-1"
                                wire:click="marcarAguardandoTerceiros('{{ $r->id }}')"
                                title="Aguardando terceiros">
                            <i class="bx bx-time-five"></i>
                        </button>
                        @endif

                        @if($aberta)
                        @can('resolver', $r)
                        <button class="btn btn-xs btn-outline-success py-0 px-1"
                                wire:click="abrirModalResolucao('{{ $r->id }}')"
                                title="Resolver">
                            <i class="bx bx-check-circle"></i>
                        </button>
                        @endcan
                        @endif

                        @if($sv === 'resolvida')
                        @can('reabrir', $r)
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1"
                                title="Reabrir"
                                onclick="confirmarAcao(this, {
                                    mensagem: 'Reabrir esta restrição? Ela volta para o status Aberta.',
                                    metodo: 'reabrirRestricao',
                                    args: ['{{ $r->id }}'],
                                    corBotao: 'primary',
                                    icone: 'bx-history',
                                })">
                            <i class="bx bx-history"></i>
                        </button>
                        @endcan
                        @endif

                        @can('comentar', $r)
                        <button class="btn btn-xs btn-outline-secondary py-0 px-1"
                                wire:click="abrirModalComentarios('{{ $r->id }}')"
                                title="Comentários">
                            <i class="bx bx-comment-detail"></i>
                        </button>
                        @endcan

                        @can('delete', $r)
                        <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1"
                                title="Excluir"
                                onclick="confirmarAcao(this, {
                                    mensagem: 'Remover esta restrição? A ação não pode ser desfeita.',
                                    metodo: 'excluirRestricao',
                                    args: ['{{ $r->id }}'],
                                    icone: 'bx-trash',
                                })">
                            <i class="bx bx-trash"></i>
                        </button>
                        @endcan
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <small class="text-muted text-nowrap">{{ $this->restricoes->total() }} restrições encontradas</small>
        <select class="form-select form-select-sm" style="width:auto" wire:model.live="perPage">
            @foreach([5,10,15,20,25,50] as $n)
            <option value="{{ $n }}">{{ $n }} por página</option>
            @endforeach
        </select>
    </div>
    {{ $this->restricoes->links() }}
</div>

@else
<div class="text-center py-5">
    <i class="bx bx-shield-alt-2 display-3 text-muted"></i>
    <h5 class="fw-bold mt-3">Nenhuma restrição encontrada</h5>
    <p class="text-muted">
        @if($this->temFiltrosAtivos())
        <button class="btn btn-sm btn-outline-secondary mt-1" wire:click="limparFiltros">
            Limpar filtros
        </button>
        @else
        Esta obra ainda não possui restrições registradas.
        @endif
    </p>
</div>
@endif

@endif {{-- fim if($agruparPorAtividade) --}}

{{-- =========================================================================
     CANVA LATERAL DE FILTROS — desliza sobre o conteúdo a partir da borda
     direita, no mesmo espírito do Theme Customizer do template (aba presa
     na borda do painel, painel fixo com transform:translateX). Fechado por
     padrão: é uma sobreposição (overlay), não divide espaço com a lista.
     ========================================================================= --}}
<div class="canva-filtros-restricoes" :class="filtrosAbertos ? 'canva-filtros-aberto' : ''" x-data="{ filtrosAbertos: false }">
    <button type="button" class="canva-filtros-aba" @click="filtrosAbertos = true" title="Filtros">
        <i class="bx bx-filter-alt"></i>
        @if($this->temFiltrosAtivos())
        <span class="canva-filtros-aba-badge"></span>
        @endif
    </button>

    <div class="canva-filtros-header d-flex align-items-center justify-content-between border-bottom px-4 py-3">
        <h6 class="mb-0 fw-semibold">
            <i class="bx bx-filter-alt me-1"></i>Filtros
            @if($this->temFiltrosAtivos())
            <span class="badge bg-primary rounded-pill ms-1">ativos</span>
            @endif
        </h6>
        <a href="javascript:void(0)" class="text-body" @click="filtrosAbertos = false">
            <i class="bx bx-x fs-4"></i>
        </a>
    </div>

    <div class="canva-filtros-body px-4 py-3">
        <div class="row g-2">
            <div class="col-12">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bx bx-search"></i></span>
                    <input type="text" class="form-control" placeholder="Buscar descrição..."
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroStatus">
                    <option value="">Todos os status</option>
                    <option value="aberta">Aberta</option>
                    <option value="em_tratamento">Em Tratamento</option>
                    <option value="aguardando_terceiros">Ag. Terceiros</option>
                    <option value="resolvida">Resolvida</option>
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroCategoriaId">
                    <option value="">Todas as categorias</option>
                    @foreach($this->categorias as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroPilar">
                    <option value="">Todos os pilares</option>
                    @foreach(\App\Enums\PilarLean::cases() as $pilar)
                    <option value="{{ $pilar->value }}">{{ str_replace('_',' ',ucfirst($pilar->value)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroResponsavelId">
                    <option value="">Todos os responsáveis</option>
                    @foreach($this->usuariosDaObra as $u)
                    <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroDisciplinaId">
                    <option value="">Todas as disciplinas</option>
                    @foreach($this->disciplinas as $d)
                    <option value="{{ $d->id }}">{{ $d->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroFrenteTrabalhoId">
                    <option value="">Todas as frentes de trabalho</option>
                    @foreach($this->frentesTrabalho as $f)
                    <option value="{{ $f->id }}">{{ $f->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroFaturamentoDireto">
                    <option value="">Faturamento Direto: Todos</option>
                    <option value="1">Faturamento Direto: Sim</option>
                    <option value="0">Faturamento Direto: Não</option>
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroEntregavelId">
                    <option value="">Todos os entregáveis</option>
                    @foreach($this->entregaveis as $en)
                    <option value="{{ $en->id }}">{{ $en->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroEquipeResponsavelId">
                    <option value="">Todas as equipes/responsáveis</option>
                    @foreach($this->equipesResponsaveis as $eq)
                    <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroPersonalizado1Id">
                    <option value="">Personalizado 1: Todos</option>
                    @foreach($this->personalizados1 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroPersonalizado2Id">
                    <option value="">Personalizado 2: Todos</option>
                    @foreach($this->personalizados2 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroPersonalizado3Id">
                    <option value="">Personalizado 3: Todos</option>
                    @foreach($this->personalizados3 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroPersonalizado4Id">
                    <option value="">Personalizado 4: Todos</option>
                    @foreach($this->personalizados4 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="filtroPersonalizado5Id">
                    <option value="">Personalizado 5: Todos</option>
                    @foreach($this->personalizados5 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <small class="text-muted d-block mb-1">Execução:</small>
                <div class="d-flex gap-2 align-items-center">
                    <input type="date" class="form-control form-control-sm"
                           wire:model.live.debounce.400ms="filtroDataInicioAtiv"
                           title="Início da atividade ≥">
                    <span class="text-muted">→</span>
                    <input type="date" class="form-control form-control-sm"
                           wire:model.live.debounce.400ms="filtroDataFimAtiv"
                           title="Término da atividade ≤">
                </div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="togBloq"
                           wire:model.live="apenasBloqueantes">
                    <label class="form-check-label small" for="togBloq">Bloqueantes</label>
                </div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="togPront"
                           wire:model.live="agruparPorAtividade">
                    <label class="form-check-label small" for="togPront">
                        <i class="bx bx-check-square me-1"></i>Vista Prontidão
                    </label>
                </div>
            </div>
            @if($this->temFiltrosAtivos())
            <div class="col-12">
                <button class="btn btn-sm btn-outline-secondary w-100" wire:click="limparFiltros">
                    <i class="bx bx-x me-1"></i>Limpar filtros
                </button>
            </div>
            @endif
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12">
                <button class="btn btn-outline-success btn-sm w-100" wire:click="exportarExcel">
                    <i class="bx bxs-file-export me-1"></i>Excel
                </button>
            </div>
            @can('notificar', [\App\Models\Restricao::class, $obra->id])
            <div class="col-12">
                <button type="button" class="btn btn-outline-warning btn-sm w-100"
                        wire:loading.attr="disabled" wire:target="notificarResponsaveis"
                        onclick="confirmarAcao(this, {
                            mensagem: 'Enviar notificação (e-mail + app) para todos os responsáveis com restrições pendentes nesta obra?',
                            metodo: 'notificarResponsaveis',
                            corBotao: 'warning',
                            icone: 'bx-bell',
                        })">
                    <i class="bx bx-bell me-1"></i>Notificar Responsáveis
                </button>
            </div>
            @endcan
            @can('create', [\App\Models\Restricao::class, $obra->id])
            <div class="col-12">
                <button class="btn btn-primary btn-sm w-100" wire:click="abrirModalNova()">
                    <i class="bx bx-plus me-1"></i>Nova Restrição
                </button>
            </div>
            @endcan
        </div>
    </div>
</div>

{{-- =========================================================================
     MODAL: DETALHE DA ATIVIDADE
     ========================================================================= --}}
@if($modalAtividadeId)
@php $detalhe = $this->atividadeDetalhe; @endphp
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            @if(!$detalhe)
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 text-muted">Carregando...</p>
            </div>
            @else
            @php
                $at        = $detalhe['atividade'];
                $checklist = $detalhe['checklist'];
                $baselineImp  = $detalhe['baselineImportacaoAtual'];
                $tendenciaImp = $detalhe['tendenciaImportacaoAtual'];
                $okChk     = collect($checklist)->where('concluido', true)->count();
                $totalChk  = collect($checklist)->count();
                // Ciclo 18, Etapa 18.4.CORREÇÃO.HARDENING — deixou de
                // reimplementar a regra manualmente (restrições+checklist,
                // sem GED) e passou a delegar 100% à fonte canônica única
                // (Atividade::estaPronta(), que já considera Documento de
                // Engenharia vinculado e não liberado — achado B da
                // auditoria adversarial da 18.4.CORREÇÃO: este popup podia
                // afirmar "pode ser comprometida no Plano Semanal" pra uma
                // atividade que o Plano Semanal já rejeitava corretamente).
                // 1 única query por abertura de popup (uma atividade só,
                // nunca um loop) — mesmo custo já aceito no popup do
                // Lookahead. $okChk/$totalChk continuam existindo só como
                // dado explicativo do checklist (badge/barra de progresso
                // mais abaixo), nunca mais como fonte decisória.
                $atividadePronta = $at->estaPronta();
            @endphp
            <div class="modal-header bg-dark text-white">
                <div class="flex-grow-1">
                    <h5 class="modal-title mb-0">{{ $at->nome }}</h5>
                    <small class="opacity-75">
                        <i class="bx bx-folder me-1"></i>{{ $at->pacoteTrabalho?->nome ?? 'Sem pacote' }}
                        @if($at->caminho_critico)
                        <span class="badge bg-danger ms-2">Caminho Crítico</span>
                        @endif
                        <span class="badge {{ $atividadePronta ? 'bg-success' : 'bg-warning text-dark' }} ms-2">
                            {{ $atividadePronta ? '✅ Pronta' : '⚠ Com pendências' }}
                        </span>
                    </small>
                </div>
                <button type="button" class="btn-close btn-close-white"
                        wire:click="$set('modalAtividadeId', null)"></button>
            </div>

            {{-- Barra de datas: Linha de Base e Tendência, cada uma indicando a importação seguida --}}
            <div class="px-4 py-3 bg-light border-bottom">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="small text-muted fw-semibold mb-2">
                            Linha de Base
                            <span class="fw-normal">
                                — {{ $baselineImp ? $baselineImp->importado_em->format('d/m/Y H:i') : 'sem importação registrada' }}
                            </span>
                        </div>
                        <div class="row g-2 text-center">
                            <div class="col-6">
                                <div class="small text-muted">Início</div>
                                <div class="fw-semibold">{{ $at->baseline_inicio?->format('d/m/Y') ?? '—' }}</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Término</div>
                                <div class="fw-semibold">{{ $at->baseline_termino?->format('d/m/Y') ?? '—' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 border-start">
                        <div class="small text-muted fw-semibold mb-2">
                            Tendência
                            <span class="fw-normal">
                                — {{ $tendenciaImp ? $tendenciaImp->importado_em->format('d/m/Y H:i') : 'sem importação registrada' }}
                            </span>
                        </div>
                        <div class="row g-2 text-center">
                            <div class="col-6">
                                <div class="small text-muted">Início</div>
                                <div class="fw-semibold">{{ $tendenciaImp ? ($detalhe['inicioTendencia']?->format('d/m/Y') ?? '—') : 'N/A' }}</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Término</div>
                                <div class="fw-semibold">{{ $tendenciaImp ? ($detalhe['terminoTendencia']?->format('d/m/Y') ?? '—') : 'N/A' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="row g-0">
                    {{-- Coluna restrições --}}
                    <div class="{{ $checklist->isNotEmpty() ? 'col-md-8 border-end' : 'col-12' }}">
                        <div class="p-4">
                            <h6 class="fw-bold mb-3 d-flex align-items-center justify-content-between">
                                <span><i class="bx bx-block me-2 text-danger"></i>Restrições</span>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-secondary">{{ $at->restricoes->count() }}</span>
                                    @can('create', [\App\Models\Restricao::class, $obra->id])
                                    <button class="btn btn-xs btn-outline-warning py-0 px-2"
                                            wire:click="$set('modalAtividadeId',null)"
                                            x-init
                                            @click.stop="
                                                $nextTick(() => $wire.abrirModalNova('{{ $at->id }}'))
                                            ">
                                        <i class="bx bx-plus me-1"></i>Nova
                                    </button>
                                    @endcan
                                </div>
                            </h6>

                            @if($at->restricoes->isEmpty())
                            <div class="text-center text-muted py-4">
                                <i class="bx bx-check-circle fs-2 text-success d-block mb-2"></i>
                                Nenhuma restrição nesta atividade.
                            </div>
                            @else
                            @foreach($at->restricoes as $r)
                            @php
                                $rsv    = $r->status instanceof \App\Enums\StatusRestricao ? $r->status->value : $r->status;
                                $raberta= in_array($rsv, ['aberta','em_tratamento','aguardando_terceiros']);
                                $rlabel = match($rsv) {
                                    'aberta'               => 'Aberta',
                                    'em_tratamento'        => 'Em Tratamento',
                                    'aguardando_terceiros' => 'Ag. Terceiros',
                                    'resolvida'            => 'Resolvida',
                                    default                => $rsv,
                                };
                                $rcor   = match($rsv) {
                                    'aberta'               => 'danger',
                                    'em_tratamento'        => 'warning',
                                    'aguardando_terceiros' => 'info',
                                    'resolvida'            => 'success',
                                    default                => 'secondary',
                                };
                                $rrisco = ($r->probabilidade ?? 0) * ($r->impacto ?? 0);
                            @endphp
                            <div class="card {{ $r->bloqueante && $raberta ? 'border-danger' : 'border-light' }} mb-3 shadow-none">
                                <div class="card-body py-2 px-3">
                                    <div class="d-flex align-items-start gap-2 mb-1">
                                        @if($r->bloqueante && $raberta)
                                        <i class="bx bx-error-circle text-danger mt-1 flex-shrink-0"></i>
                                        @endif
                                        <div class="flex-grow-1 me-2">
                                            <span style="font-size:.875rem">{{ $r->descricao }}</span>
                                            @if($r->categoria)
                                            <span class="badge bg-label-secondary ms-1" style="font-size:.7rem">
                                                {{ $r->categoria->nome }}
                                            </span>
                                            @endif
                                        </div>
                                        <span class="badge bg-{{ $rcor }} flex-shrink-0">{{ $rlabel }}</span>
                                    </div>
                                    <div class="d-flex gap-3 flex-wrap">
                                        @if($r->prazo_limite)
                                        <small class="text-muted">
                                            <i class="bx bx-calendar me-1"></i>
                                            {{ $r->prazo_limite->format('d/m/Y') }}
                                            @if($r->prazo_limite->isPast() && $raberta)
                                            <span class="text-danger">(vencida)</span>
                                            @endif
                                        </small>
                                        @endif
                                        @if($r->responsavel)
                                        <small class="text-muted">
                                            <i class="bx bx-user me-1"></i>{{ $r->responsavel->first_name }} {{ $r->responsavel->last_name }}
                                        </small>
                                        @elseif($r->responsavel_externo)
                                        <small class="text-muted">
                                            <i class="bx bx-user me-1"></i>{{ $r->responsavel_externo }}
                                        </small>
                                        @endif
                                        @if($r->probabilidade !== null && $r->impacto !== null)
                                        <small class="text-muted">
                                            P×I = <strong class="{{ $rrisco >= 50 ? 'text-danger' : '' }}">{{ $rrisco }}</strong>
                                            @if($rrisco >= 50)<span class="text-danger">(crítico)</span>@endif
                                        </small>
                                        @endif
                                    </div>

                                    {{-- Histórico de ações --}}
                                    @if($r->acoes->isNotEmpty())
                                    <div class="mt-2 pt-2 border-top">
                                        <small class="text-muted fw-semibold">Histórico:</small>
                                        @foreach($r->acoes as $acao)
                                        <div class="d-flex gap-2 mt-1">
                                            <i class="bx bx-chevron-right text-muted flex-shrink-0"></i>
                                            <div>
                                                <small>{{ $acao->descricao }}</small>
                                                <small class="text-muted d-block">
                                                    {{ $acao->autor?->first_name }}
                                                    — {{ $acao->created_at->format('d/m/Y H:i') }}
                                                </small>
                                            </div>
                                        </div>
                                        @endforeach
                                    </div>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                            @endif
                        </div>
                    </div>

                    {{-- Coluna checklist de prontidão --}}
                    @if($checklist->isNotEmpty())
                    <div class="col-md-4">
                        <div class="p-4">
                            <h6 class="fw-bold mb-3">
                                <i class="bx bx-check-square me-2 text-success"></i>Prontidão
                                <span class="badge {{ $okChk === $totalChk ? 'bg-success' : 'bg-warning text-dark' }} ms-1">
                                    {{ $okChk }}/{{ $totalChk }}
                                </span>
                            </h6>
                            <div class="progress mb-3" style="height:6px">
                                <div class="progress-bar {{ $okChk === $totalChk ? 'bg-success' : 'bg-warning' }}"
                                     style="width:{{ $totalChk > 0 ? round($okChk/$totalChk*100) : 0 }}%"></div>
                            </div>

                            @foreach($checklist as $itemChk)
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox"
                                       id="dchk_{{ $at->id }}_{{ $itemChk['id'] }}"
                                       @checked($itemChk['concluido'])
                                       wire:click="marcarItemNaDetalhe('{{ $at->id }}','{{ $itemChk['id'] }}',{{ $itemChk['concluido'] ? 'false' : 'true' }})">
                                <label class="form-check-label {{ $itemChk['concluido'] ? 'text-decoration-line-through text-muted' : '' }}"
                                       for="dchk_{{ $at->id }}_{{ $itemChk['id'] }}">
                                    {{ $itemChk['nome'] }}
                                </label>
                                @if($itemChk['concluido'] && $itemChk['concluidoPor'])
                                <small class="text-muted d-block">
                                    <i class="bx bx-user me-1"></i>{{ $itemChk['concluidoPor']->first_name }} {{ $itemChk['concluidoPor']->last_name }}
                                    @if($itemChk['concluidoEm'])
                                    — {{ $itemChk['concluidoEm']->format('d/m/Y H:i') }}
                                    @endif
                                </small>
                                @endif
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <div class="modal-footer">
                <small class="text-muted me-auto">
                    @if($atividadePronta)
                    ✅ Atividade pronta — pode ser comprometida no Plano Semanal
                    @else
                    ⚠ Pendências impedem o comprometimento no Plano Semanal
                    @endif
                </small>
                <button class="btn btn-secondary" wire:click="$set('modalAtividadeId', null)">Fechar</button>
            </div>
            @endif
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: CRIAR / EDITAR RESTRIÇÃO
     ========================================================================= --}}
@if($modalAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx {{ $editandoId ? 'bx-pencil' : 'bx-plus' }} me-2"></i>
                    {{ $editandoId ? 'Editar Restrição' : 'Nova Restrição' }}
                </h5>
                <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
            </div>
            <div class="modal-body">

                {{-- Atividade(s) --}}
                <div class="mb-3">
                    <label class="form-label">
                        Atividade{{ $editandoId ? '' : '(s)' }} <span class="text-danger">*</span>
                        @if(!$editandoId)
                        <small class="text-muted">— pode selecionar várias, uma restrição é criada para cada uma</small>
                        @endif
                    </label>

                    @if($editandoId)
                    {{-- Em edição não dá pra trocar a atividade vinculada --}}
                    <div class="form-control bg-light text-muted">
                        {{ $this->atividadesSelecionadasNova->first()?->nome ?? '—' }}
                    </div>
                    @else
                    <div class="border rounded p-2 @error('atividadesIdsNova') is-invalid border-danger @enderror">
                        @if($this->atividadesSelecionadasNova->isNotEmpty())
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            @foreach($this->atividadesSelecionadasNova as $sel)
                            <span class="badge bg-label-primary d-flex align-items-center gap-1">
                                {{ $sel->nome }}
                                <i class="bx bx-x" style="cursor:pointer"
                                   wire:click="toggleAtividadeSelecionada('{{ $sel->id }}')"></i>
                            </span>
                            @endforeach
                        </div>
                        @endif

                        <input type="text" class="form-control form-control-sm mb-2"
                               wire:model.live.debounce.300ms="buscaAtividadeNova"
                               placeholder="Buscar atividade pelo nome...">

                        <div style="max-height:180px; overflow-y:auto">
                            @forelse($this->atividadesParaSelecao as $at)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       id="atv_{{ $at->id }}"
                                       @checked(in_array($at->id, $atividadesIdsNova))
                                       wire:click="toggleAtividadeSelecionada('{{ $at->id }}')">
                                <label class="form-check-label" for="atv_{{ $at->id }}">
                                    {{ $at->nome }}
                                    @if($at->inicio_planejado)
                                    <small class="text-muted">
                                        ({{ $at->inicio_planejado->format('d/m') }}–{{ $at->data_termino?->format('d/m') }})
                                    </small>
                                    @endif
                                </label>
                            </div>
                            @empty
                            <small class="text-muted">Nenhuma atividade encontrada.</small>
                            @endforelse
                        </div>
                    </div>
                    @endif
                    @error('atividadesIdsNova')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Descrição --}}
                <div class="mb-3">
                    <label class="form-label">Descrição <span class="text-danger">*</span></label>
                    <textarea class="form-control @error('descricaoNova') is-invalid @enderror"
                              rows="3" wire:model="descricaoNova"
                              placeholder="Descreva o impedimento com clareza..."></textarea>
                    @error('descricaoNova')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Tipo + Prazo --}}
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Tipo de Restrição</label>
                        <select class="form-select" wire:model="categoriaIdNova">
                            <option value="">— Sem tipo —</option>
                            @foreach($this->categorias as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Prazo limite para resolução</label>
                        <input type="date" class="form-control" wire:model="prazolimiteNova">
                    </div>
                </div>

                {{-- Responsável --}}
                <div class="mb-3">
                    <label class="form-label">Responsável por resolver</label>
                    <div class="mb-2">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="respInterno"
                                   wire:model.live="responsavelExterno" value="0">
                            <label class="form-check-label" for="respInterno">Usuário interno</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="respExterno"
                                   wire:model.live="responsavelExterno" value="1">
                            <label class="form-check-label" for="respExterno">Externo</label>
                        </div>
                    </div>
                    @if(!$responsavelExterno)
                    <select class="form-select" wire:model="responsavelIdNova">
                        <option value="">— Sem responsável —</option>
                        @foreach($this->usuariosDaObra as $u)
                        <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                        @endforeach
                    </select>
                    @else
                    <input type="text" class="form-control" wire:model="responsavelExternoNova"
                           placeholder="Nome da empresa ou pessoa externa...">
                    @endif
                </div>

                {{-- P×I com preview de risco --}}
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Probabilidade (0–10)</label>
                        <input type="number" class="form-control" min="0" max="10"
                               wire:model.live="probabilidadeNova">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Impacto (0–10)</label>
                        <input type="number" class="form-control" min="0" max="10"
                               wire:model.live="impactoNova">
                    </div>
                    <div class="col-12">
                        @php
                            $prevRisco = ($probabilidadeNova ?? 0) * ($impactoNova ?? 0);
                            $prevCor   = $prevRisco >= 50 ? 'danger' : ($prevRisco >= 25 ? 'warning' : 'success');
                        @endphp
                        <div class="alert alert-light border py-2 mb-0">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <small class="text-muted">
                                    <strong>Como classificar:</strong>
                                    0 = não ocorre / sem impacto &nbsp;|&nbsp;
                                    5 = possível / atraso moderado &nbsp;|&nbsp;
                                    10 = certo / paralisa a obra.
                                    <strong>P×I ≥ 50 = crítico.</strong>
                                </small>
                                @if($probabilidadeNova !== null && $impactoNova !== null && ($probabilidadeNova || $impactoNova))
                                <span class="badge bg-{{ $prevCor }} flex-shrink-0">
                                    Risco: {{ $prevRisco }}
                                </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bloqueante --}}
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="blocanteNova"
                           wire:model="blocanteNova">
                    <label class="form-check-label" for="blocanteNova">
                        <strong>Restrição bloqueante</strong>
                        <small class="text-muted d-block">
                            Impede que a atividade seja comprometida no Plano Semanal
                        </small>
                    </label>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary"
                        wire:click="$set('modalAberto', false)">Cancelar</button>
                <button type="button" class="btn btn-primary"
                        wire:click="salvarRestricao" wire:loading.attr="disabled">
                    <span wire:loading wire:target="salvarRestricao">
                        <span class="spinner-border spinner-border-sm me-1"></span>
                    </span>
                    {{ $editandoId ? 'Salvar alterações' : 'Registrar Restrição' }}
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: RESOLVER
     ========================================================================= --}}
@if ($resolvendoId)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-check-circle me-2 text-success"></i>Resolver Restrição
                </h5>
                <button type="button" class="btn-close" wire:click="$set('resolvendoId', null)"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Como a restrição foi resolvida? <span class="text-danger">*</span></label>
                <textarea class="form-control @error('acaoTexto') is-invalid @enderror" rows="4"
                          wire:model="acaoTexto"
                          placeholder="Ex: Material entregue pelo fornecedor em 28/06..."></textarea>
                <x-input-error for="acaoTexto" />
            </div>
            <div class="modal-footer flex-wrap">
                @can('create', [\App\Models\LicaoAprendida::class, $obra])
                    <a href="{{ route('gestao.licoes-aprendidas', ['origem_tipo' => 'restricao', 'origem_id' => $resolvendoId]) }}"
                       wire:navigate
                       class="btn btn-outline-warning me-auto">
                        <i class="bx bx-bulb me-1"></i>Registrar como lição aprendida
                    </a>
                @endcan
                <button type="button" class="btn btn-outline-secondary" wire:click="$set('resolvendoId', null)">Cancelar</button>
                <button type="button" class="btn btn-success"
                        wire:click="resolver"
                        wire:loading.attr="disabled">
                    <i class="bx bx-check me-1"></i>Confirmar resolução
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: COMENTÁRIOS
     ========================================================================= --}}
@if ($comentandoId)
@php $rc = $this->restricaoComentada; @endphp
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-comment-detail me-2"></i>Comentários
                </h5>
                <button type="button" class="btn-close" wire:click="$set('comentandoId', null)"></button>
            </div>
            <div class="modal-body">
                @if(!$rc)
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
                @else
                <p class="small text-muted border-bottom pb-2 mb-2">{{ $rc->descricao }}</p>

                @if($rc->acoes->isEmpty())
                <p class="text-muted small">Nenhum comentário ainda.</p>
                @else
                <div class="mb-3" style="max-height:260px; overflow-y:auto">
                    @foreach($rc->acoes as $acao)
                    <div class="d-flex gap-2 mb-2">
                        <i class="bx bx-chevron-right text-muted flex-shrink-0"></i>
                        <div>
                            <small>{{ $acao->descricao }}</small>
                            <small class="text-muted d-block">
                                {{ $acao->autor?->first_name }} {{ $acao->autor?->last_name }}
                                — {{ $acao->created_at->format('d/m/Y H:i') }}
                            </small>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                <div class="mb-0">
                    <label class="form-label">Novo comentário</label>
                    <textarea class="form-control @error('comentarioTexto') is-invalid @enderror" rows="3"
                              wire:model="comentarioTexto" placeholder="Escreva um comentário ou atualização de status..."></textarea>
                    @error('comentarioTexto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$set('comentandoId', null)">Fechar</button>
                <button type="button" class="btn btn-primary" wire:click="adicionarComentario" wire:loading.attr="disabled">
                    <i class="bx bx-send me-1"></i>Adicionar comentário
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- =========================================================================
     CSS do canva lateral de filtros — mesmo mecanismo visual do Theme
     Customizer do template (public/assets/vendor/js/template-customizer.js):
     painel fixo na borda direita, escondido via translateX, com uma aba
     presa na borda esquerda do próprio painel pra abrir. Fechado desliza
     pra fora da tela (não disputa espaço com a lista, é uma sobreposição).
     PRECISA ficar indentado (nunca na coluna 0 da linha) — o compilador
     de single-file component do Livewire (SingleFileParser::extractStylePortion)
     detecta qualquer <style> que comece exatamente no início de uma linha
     e o EXTRAI do HTML pra um mecanismo de "CSS escopado" servido por
     rota separada, silenciosamente removendo a tag daqui. Indentado, ele
     é só HTML normal e renderiza inline como qualquer outra tag.
     ========================================================================= --}}
    <style>
    /* z-index:1080 fica ACIMA da navbar fixa do template (.layout-navbar,
       z-index:1075 — sem isso o botão fechar do canva ficava inacessível,
       coberto pela navbar) e ABAIXO dos modais do Bootstrap (z-index:1090
       — pra um modal aberto a partir do canva, ex. "Nova Restrição",
       continuar aparecendo por cima dele).
       Bug de teste manual, 2026-09-02 — `top: 0` fazia o canva cobrir
       também a FAIXA da navbar (0 a 3.875rem, mesma altura de
       $navbar-height no tema), e como o z-index dele é MAIOR que o da
       navbar, um clique no sino/perfil/app-grid nessa faixa era
       inteiramente engolido pelo canva aberto — confirmado com
       document.elementFromPoint() retornando o header do canva em vez do
       ícone da navbar. Corrigido começando o canva ABAIXO da navbar
       (nunca sobre ela), sem mudar o z-index (ainda precisa ficar acima
       da navbar pro botão fechar do canva/pull-tab nunca ficarem
       cobertos por ela) nem a navbar em si. */
    .canva-filtros-restricoes {
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

    .canva-filtros-restricoes.canva-filtros-aberto {
        right: 0;
    }

    .canva-filtros-body {
        flex: 1 1 auto;
        overflow-y: auto;
    }

    .canva-filtros-aba {
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

    .canva-filtros-restricoes.canva-filtros-aberto .canva-filtros-aba {
        opacity: 0;
        pointer-events: none;
    }

    .canva-filtros-aba-badge {
        position: absolute;
        top: 4px;
        right: 4px;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--bs-danger);
    }

    @media (max-width: 575.98px) {
        .canva-filtros-restricoes {
            width: 300px;
            right: -300px;
        }

        .canva-filtros-restricoes.canva-filtros-aberto {
            right: 0;
        }
    }
</style>

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
