<?php

use App\Imports\DocumentoEngenhariaImporter;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\PacoteEngenharia;
use App\Models\StatusDocumento;
use App\Models\Work;
use App\Services\CurvaEmissoesEngenharia;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
  use ExecutaComTransacaoSegura, WithFileUploads, WithPagination;

  public ?string $obraId = null;

  // ---- Abas ----
  public string $abaAtiva = 'lista';
  public ?string $mesSelecionadoDashboard = null;

  // ---- Filtros ----
  public string $busca = '';
  public ?string $disciplinaIdFiltro = null;
  public ?string $statusIdFiltro = null;
  public ?string $pacoteIdFiltro = null;
  public int $perPage = 15;

  const STATUS_FILTRO_NAO_EMITIDO = '__nao_emitido__';

  // ---- Modal criar/editar documento ----
  public bool $modalDocumentoAberto = false;
  public ?string $editandoDocumentoId = null;
  public string $codigoNovo = '';
  public string $descricaoNovo = '';
  public ?string $disciplinaIdNovo = null;
  public ?string $pacoteIdNovo = null;
  public string $dataPrevistaNovo = '';

  // ---- Modal gerenciar pacotes ----
  public bool $modalPacotesAberto = false;
  public string $novoPacoteNome = '';
  public ?string $pacoteEditandoId = null;
  public string $pacoteEditandoNome = '';

  // ---- Modal revisões ----
  public ?string $documentoRevisoesId = null;
  public string $revisaoNovaTexto = '';
  public string $revisaoNovaData = '';
  public ?string $revisaoNovaStatusId = null;
  public string $revisaoNovaDescricao = '';
  public string $revisaoNovaComentarios = '';
  public $revisaoNovaAnexo = null;

  // ---- Modal importar planilha ----
  public bool $modalImportarAberto = false;
  public $arquivoImportacao = null;
  public ?array $previaImportacao = null;

  private function garantirPermissao(string $acao): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', $acao), 403);
  }

  // =========================================================================
  // COMPUTED — DADOS DE REFERÊNCIA
  // =========================================================================

  #[Computed]
  public function obras(): \Illuminate\Support\Collection
  {
    return Work::orderBy('name')->get(['id', 'name']);
  }

  #[Computed]
  public function disciplinas(): \Illuminate\Support\Collection
  {
    return Disciplina::orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function statusDisponiveis(): \Illuminate\Support\Collection
  {
    if (!$this->obraId) {
      return collect();
    }

    return StatusDocumento::where('obra_id', $this->obraId)
      ->orderBy('ordem')
      ->get();
  }

  #[Computed]
  public function pacotes(): \Illuminate\Support\Collection
  {
    if (!$this->obraId) {
      return collect();
    }

    return PacoteEngenharia::where('obra_id', $this->obraId)
      ->orderBy('nome')
      ->get();
  }

  // =========================================================================
  // LISTA DE DOCUMENTOS
  // =========================================================================

  public function temFiltrosAtivos(): bool
  {
    return (bool) ($this->busca || $this->disciplinaIdFiltro || $this->statusIdFiltro || $this->pacoteIdFiltro);
  }

  private function documentosQuery(): \Illuminate\Database\Eloquent\Builder
  {
    return DocumentoEngenharia::where('obra_id', $this->obraId)
      ->when(
        $this->busca,
        fn($q) => $q->where(
          fn($qq) => $qq->where('codigo', 'like', "%{$this->busca}%")->orWhere('descricao', 'like', "%{$this->busca}%")
        )
      )
      ->when($this->disciplinaIdFiltro, fn($q) => $q->where('disciplina_id', $this->disciplinaIdFiltro))
      ->when($this->pacoteIdFiltro, fn($q) => $q->where('pacote_engenharia_id', $this->pacoteIdFiltro))
      ->when($this->statusIdFiltro === self::STATUS_FILTRO_NAO_EMITIDO, fn($q) => $q->doesntHave('revisoes'))
      ->when(
        $this->statusIdFiltro && $this->statusIdFiltro !== self::STATUS_FILTRO_NAO_EMITIDO,
        fn($q) => $q->whereHas('latestRevisao', fn($q2) => $q2->where('status_documento_id', $this->statusIdFiltro))
      );
  }

  #[Computed]
  public function documentos(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
  {
    if (!$this->obraId) {
      return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
    }

    return $this->documentosQuery()
      ->with(['disciplina:id,nome', 'latestRevisao.statusDocumento:id,nome,cor,conclusivo', 'pacote:id,nome'])
      ->withCount(['revisoes', 'reprogramacoes'])
      ->orderBy('codigo')
      ->paginate($this->perPage);
  }

  #[Computed]
  public function totais(): array
  {
    if (!$this->obraId) {
      return ['total' => 0, 'concluidos' => 0, 'aguardando' => 0, 'atrasados' => 0];
    }

    $hoje = now()->toDateString();

    $total = $this->documentosQuery()->count();

    $concluidos = $this->documentosQuery()
      ->whereHas('latestRevisao', fn($q) => $q->whereHas('statusDocumento', fn($q2) => $q2->where('conclusivo', true)))
      ->count();

    $atrasados = $this->documentosQuery()
      ->doesntHave('revisoes')
      ->whereNotNull('data_planejada')
      ->where('data_planejada', '<', $hoje)
      ->count();

    $aguardando = $this->documentosQuery()
      ->doesntHave('revisoes')
      ->where(fn($q) => $q->whereNull('data_planejada')->orWhere('data_planejada', '>=', $hoje))
      ->count();

    return [
      'total' => $total,
      'concluidos' => $concluidos,
      'aguardando' => $aguardando,
      'atrasados' => $atrasados,
    ];
  }

  public function setAba(string $aba): void
  {
    $this->abaAtiva = $aba;

    if ($aba === 'dashboard') {
      if (!$this->mesSelecionadoDashboard) {
        $meses = $this->curvaEmissoes['mesesDisponiveis'] ?? [];
        $this->mesSelecionadoDashboard = end($meses) ?: null;
      }

      $this->dispatch('dashboard-dados-atualizados', ...$this->dadosGraficosDashboard());
    }
  }

  /**
   * @script no template do componente só executa uma vez, no mount inicial
   * — não roda de novo em requests Livewire seguintes (troca de aba, troca
   * de mês). Por isso os gráficos são (re)montados via evento despachado
   * pro JS (mesmo mecanismo já usado por "show-toast"), não por Blade
   * condicional dentro do bloco @script.
   */
  private function dadosGraficosDashboard(): array
  {
    return [
      'obraId' => $this->obraId,
      'mensal' => $this->curvaEmissoes['mensal'],
      'semanal' => $this->curvaSemanalFiltrada,
      'mesSelecionado' => $this->mesSelecionadoDashboard,
      'disciplina' => $this->graficoDisciplina,
      'status' => $this->graficoStatus,
      'aging' => $this->agingAtraso,
    ];
  }

  // =========================================================================
  // DASHBOARD
  // =========================================================================

  #[Computed]
  public function curvaEmissoes(): array
  {
    if (!$this->obraId) {
      $vazio = ['labels' => [], 'qtdPrevisto' => [], 'qtdRealizado' => [], 'pctPrevistoAcumulado' => [], 'pctRealizadoAcumulado' => []];

      return ['total' => 0, 'mensal' => $vazio, 'semanalCompleta' => $vazio, 'mesesDisponiveis' => []];
    }

    return app(CurvaEmissoesEngenharia::class)->calcular(Work::findOrFail($this->obraId));
  }

  #[Computed]
  public function curvaSemanalFiltrada(): array
  {
    $curva = $this->curvaEmissoes['semanalCompleta'];

    if (!$this->mesSelecionadoDashboard || empty($curva['labels'])) {
      return ['labels' => [], 'qtdPrevisto' => [], 'qtdRealizado' => [], 'pctPrevistoAcumulado' => [], 'pctRealizadoAcumulado' => []];
    }

    $indices = collect($curva['labels'])
      ->map(fn($label, $i) => \Carbon\Carbon::parse($label)->format('Y-m') === $this->mesSelecionadoDashboard ? $i : null)
      ->filter(fn($i) => $i !== null)
      ->values();

    return [
      'labels' => $indices->map(fn($i) => $curva['labels'][$i])->all(),
      'qtdPrevisto' => $indices->map(fn($i) => $curva['qtdPrevisto'][$i])->all(),
      'qtdRealizado' => $indices->map(fn($i) => $curva['qtdRealizado'][$i])->all(),
      'pctPrevistoAcumulado' => $indices->map(fn($i) => $curva['pctPrevistoAcumulado'][$i])->all(),
      'pctRealizadoAcumulado' => $indices->map(fn($i) => $curva['pctRealizadoAcumulado'][$i])->all(),
    ];
  }

  public function updatedMesSelecionadoDashboard(): void
  {
    unset($this->curvaSemanalFiltrada);
    $this->dispatch('dashboard-dados-atualizados', ...$this->dadosGraficosDashboard());
  }

  #[Computed]
  public function graficoDisciplina(): array
  {
    if (!$this->obraId) {
      return ['labels' => [], 'valores' => []];
    }

    $porDisciplina = DocumentoEngenharia::where('obra_id', $this->obraId)
      ->with('disciplina:id,nome')
      ->get()
      ->groupBy(fn(DocumentoEngenharia $d) => $d->disciplina?->nome ?? 'Sem Disciplina')
      ->sortByDesc(fn($docs) => $docs->count());

    return [
      'labels' => $porDisciplina->keys()->all(),
      'valores' => $porDisciplina->map->count()->values()->all(),
    ];
  }

  #[Computed]
  public function graficoStatus(): array
  {
    if (!$this->obraId) {
      return ['labels' => [], 'valores' => [], 'cores' => []];
    }

    $porStatus = DocumentoEngenharia::where('obra_id', $this->obraId)
      ->with('latestRevisao.statusDocumento')
      ->get()
      ->groupBy(fn(DocumentoEngenharia $d) => $d->statusAtual()?->nome ?? 'Não Emitido');

    $labels = [];
    $valores = [];
    $cores = [];

    foreach ($porStatus as $nome => $docs) {
      $labels[] = $nome;
      $valores[] = $docs->count();
      $cores[] = $docs->first()->statusAtual()?->cor ?? '#6c757d';
    }

    return compact('labels', 'valores', 'cores');
  }

  #[Computed]
  public function agingAtraso(): array
  {
    if (!$this->obraId) {
      return ['labels' => [], 'valores' => []];
    }

    $hoje = now();
    $buckets = ['0-7 dias' => 0, '8-15 dias' => 0, '16-30 dias' => 0, '30+ dias' => 0];

    DocumentoEngenharia::where('obra_id', $this->obraId)
      ->doesntHave('revisoes')
      ->whereNotNull('data_planejada')
      ->where('data_planejada', '<', $hoje->toDateString())
      ->get(['data_planejada'])
      ->each(function (DocumentoEngenharia $doc) use (&$buckets, $hoje) {
        $dias = $doc->data_planejada->diffInDays($hoje);
        $chave = match (true) {
          $dias <= 7 => '0-7 dias',
          $dias <= 15 => '8-15 dias',
          $dias <= 30 => '16-30 dias',
          default => '30+ dias',
        };
        $buckets[$chave]++;
      });

    return ['labels' => array_keys($buckets), 'valores' => array_values($buckets)];
  }

  #[Computed]
  public function topReprogramados(): \Illuminate\Support\Collection
  {
    if (!$this->obraId) {
      return collect();
    }

    return DocumentoEngenharia::where('obra_id', $this->obraId)
      ->withCount('reprogramacoes')
      ->having('reprogramacoes_count', '>', 0)
      ->orderByDesc('reprogramacoes_count')
      ->limit(5)
      ->get(['id', 'codigo', 'descricao']);
  }

  public function limparFiltros(): void
  {
    $this->busca = '';
    $this->disciplinaIdFiltro = null;
    $this->statusIdFiltro = null;
    $this->pacoteIdFiltro = null;
    $this->resetPage();
  }

  public function updatedBusca(): void
  {
    $this->resetPage();
  }

  public function updatedDisciplinaIdFiltro(): void
  {
    $this->resetPage();
  }

  public function updatedStatusIdFiltro(): void
  {
    $this->resetPage();
  }

  public function updatedPacoteIdFiltro(): void
  {
    $this->resetPage();
  }

  public function updatedPerPage(): void
  {
    $this->resetPage();
  }

  public function updatedObraId(): void
  {
    $this->limparFiltros();
    $this->mesSelecionadoDashboard = null;
    unset(
      $this->documentos,
      $this->totais,
      $this->statusDisponiveis,
      $this->pacotes,
      $this->curvaEmissoes,
      $this->curvaSemanalFiltrada,
      $this->graficoDisciplina,
      $this->graficoStatus,
      $this->agingAtraso,
      $this->topReprogramados
    );

    if ($this->abaAtiva === 'dashboard') {
      $this->atualizarGraficosDashboard();
    }
  }

  // =========================================================================
  // MODAL CRIAR/EDITAR DOCUMENTO
  // =========================================================================

  public function abrirCriarDocumento(): void
  {
    $this->garantirPermissao('criar');
    $this->resetFormDocumento();
    $this->modalDocumentoAberto = true;
  }

  public function editarDocumento(string $id): void
  {
    $this->garantirPermissao('editar');

    $documento = DocumentoEngenharia::findOrFail($id);
    $this->editandoDocumentoId = $id;
    $this->codigoNovo = $documento->codigo ?? '';
    $this->descricaoNovo = $documento->descricao ?? '';
    $this->disciplinaIdNovo = $documento->disciplina_id;
    $this->pacoteIdNovo = $documento->pacote_engenharia_id;
    $this->dataPrevistaNovo = $documento->data_planejada?->format('Y-m-d') ?? '';
    $this->modalDocumentoAberto = true;
  }

  public function salvarDocumento(): void
  {
    $this->garantirPermissao($this->editandoDocumentoId ? 'editar' : 'criar');

    $this->validate(
      [
        'codigoNovo' => 'required|string|max:60',
        'descricaoNovo' => 'required|string|max:255',
        'disciplinaIdNovo' => 'nullable|exists:disciplinas,id',
        'pacoteIdNovo' => 'nullable|exists:pacotes_engenharia,id',
        'dataPrevistaNovo' => ($this->editandoDocumentoId ? 'nullable' : 'required') . '|date',
      ],
      [],
      ['codigoNovo' => 'código', 'descricaoNovo' => 'descrição', 'dataPrevistaNovo' => 'data de previsão de emissão']
    );

    $dados = [
      'codigo' => $this->codigoNovo,
      'descricao' => $this->descricaoNovo,
      'disciplina_id' => $this->disciplinaIdNovo ?: null,
      'pacote_engenharia_id' => $this->pacoteIdNovo ?: null,
      'data_planejada' => $this->dataPrevistaNovo ?: null,
    ];

    $this->transacaoSegura(function () use ($dados) {
      if ($this->editandoDocumentoId) {
        $documento = DocumentoEngenharia::findOrFail($this->editandoDocumentoId);

        // Toda alteração da data de previsão, depois de criado o documento,
        // vira uma reprogramação registrada — mesmo saindo/indo pra null.
        $dataAntiga = $documento->data_planejada?->toDateString();
        $dataNova = $dados['data_planejada'];

        if ($dataAntiga !== $dataNova) {
          $documento->reprogramacoes()->create([
            'data_anterior' => $dataAntiga,
            'data_nova' => $dataNova,
            'criado_por_id' => Auth::id(),
          ]);
        }

        $documento->update($dados);
      } else {
        DocumentoEngenharia::create($dados + ['obra_id' => $this->obraId]);
      }
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->modalDocumentoAberto = false;
    $this->resetFormDocumento();
    unset($this->documentos, $this->totais);
    $this->dispatch('show-toast', message: 'Documento salvo com sucesso.');
  }

  public function excluirDocumento(string $id): void
  {
    $this->garantirPermissao('excluir');

    DocumentoEngenharia::findOrFail($id)->delete();
    unset($this->documentos, $this->totais);
    $this->dispatch('show-toast', message: 'Documento removido.');
  }

  private function resetFormDocumento(): void
  {
    $this->editandoDocumentoId = null;
    $this->codigoNovo = '';
    $this->descricaoNovo = '';
    $this->disciplinaIdNovo = null;
    $this->pacoteIdNovo = null;
    $this->dataPrevistaNovo = '';
    $this->resetValidation();
  }

  // =========================================================================
  // MODAL GERENCIAR PACOTES (agrupamento opcional, ex.: por frente de trabalho)
  // =========================================================================

  public function abrirGerenciarPacotes(): void
  {
    $this->garantirPermissao('editar');
    $this->novoPacoteNome = '';
    $this->pacoteEditandoId = null;
    $this->modalPacotesAberto = true;
  }

  public function criarPacote(): void
  {
    $this->garantirPermissao('criar');

    $this->validate(['novoPacoteNome' => 'required|string|max:150'], [], ['novoPacoteNome' => 'nome do pacote']);

    PacoteEngenharia::create(['obra_id' => $this->obraId, 'nome' => $this->novoPacoteNome]);
    $this->novoPacoteNome = '';
    unset($this->pacotes);
    $this->dispatch('show-toast', message: 'Pacote criado.');
  }

  public function iniciarEdicaoPacote(string $id): void
  {
    $pacote = PacoteEngenharia::findOrFail($id);
    $this->pacoteEditandoId = $id;
    $this->pacoteEditandoNome = $pacote->nome;
  }

  public function salvarEdicaoPacote(): void
  {
    $this->garantirPermissao('editar');

    $this->validate(
      ['pacoteEditandoNome' => 'required|string|max:150'],
      [],
      ['pacoteEditandoNome' => 'nome do pacote']
    );

    PacoteEngenharia::findOrFail($this->pacoteEditandoId)->update(['nome' => $this->pacoteEditandoNome]);
    $this->pacoteEditandoId = null;
    unset($this->pacotes, $this->documentos);
    $this->dispatch('show-toast', message: 'Pacote atualizado.');
  }

  public function cancelarEdicaoPacote(): void
  {
    $this->pacoteEditandoId = null;
  }

  public function excluirPacote(string $id): void
  {
    $this->garantirPermissao('excluir');

    PacoteEngenharia::findOrFail($id)->delete();
    unset($this->pacotes, $this->documentos);
    $this->dispatch('show-toast', message: 'Pacote removido — documentos vinculados ficam sem pacote.');
  }

  // =========================================================================
  // MODAL REVISÕES
  // =========================================================================

  #[Computed]
  public function documentoRevisoes(): ?DocumentoEngenharia
  {
    if (!$this->documentoRevisoesId) {
      return null;
    }

    return DocumentoEngenharia::with([
      'revisoes.criadoPor:id,first_name,last_name',
      'revisoes.statusDocumento',
      'disciplina',
      'pacote',
      'reprogramacoes.criadoPor:id,first_name,last_name',
    ])->find($this->documentoRevisoesId);
  }

  public function abrirRevisoes(string $documentoId): void
  {
    $this->documentoRevisoesId = $documentoId;
    $this->resetFormRevisao();
  }

  public function fecharRevisoes(): void
  {
    $this->documentoRevisoesId = null;
    $this->resetFormRevisao();
  }

  public function adicionarRevisao(): void
  {
    $this->garantirPermissao('editar');

    $this->validate(
      [
        'revisaoNovaTexto' => [
          'required',
          'string',
          'max:50',
          \Illuminate\Validation\Rule::unique('documento_engenharia_revisoes', 'revisao')->where(
            'documento_engenharia_id',
            $this->documentoRevisoesId
          ),
        ],
        'revisaoNovaData' => 'nullable|date',
        'revisaoNovaStatusId' => 'required|exists:status_documentos_engenharia,id',
        'revisaoNovaDescricao' => 'required|string|max:255',
        'revisaoNovaComentarios' => 'nullable|string|max:2000',
        'revisaoNovaAnexo' => 'nullable|file|mimes:pdf|max:10240',
      ],
      [],
      [
        'revisaoNovaTexto' => 'revisão',
        'revisaoNovaData' => 'data de emissão',
        'revisaoNovaStatusId' => 'status',
        'revisaoNovaDescricao' => 'descrição',
      ]
    );

    $documento = DocumentoEngenharia::findOrFail($this->documentoRevisoesId);

    $this->transacaoSegura(function () use ($documento) {
      $dados = [
        'tenant_id' => $documento->tenant_id,
        'revisao' => $this->revisaoNovaTexto,
        'data_emissao' => $this->revisaoNovaData ?: null,
        'status_documento_id' => $this->revisaoNovaStatusId,
        'descricao' => $this->revisaoNovaDescricao,
        'comentarios' => $this->revisaoNovaComentarios ?: null,
        'criado_por_id' => Auth::id(),
      ];

      if ($this->revisaoNovaAnexo) {
        $dados['anexo_path'] = $this->revisaoNovaAnexo->store(
          "documentos-engenharia/{$this->obraId}/{$documento->id}",
          'public'
        );
        $dados['anexo_nome_original'] = $this->revisaoNovaAnexo->getClientOriginalName();
      }

      $documento->revisoes()->create($dados);
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetFormRevisao();
    unset($this->documentoRevisoes, $this->documentos, $this->totais);
    $this->dispatch('show-toast', message: 'Emissão adicionada.');
  }

  private function resetFormRevisao(): void
  {
    $this->revisaoNovaTexto = '';
    $this->revisaoNovaData = '';
    $this->revisaoNovaStatusId = null;
    $this->revisaoNovaDescricao = '';
    $this->revisaoNovaComentarios = '';
    $this->revisaoNovaAnexo = null;
    $this->resetValidation();
  }

  // =========================================================================
  // MODAL IMPORTAR PLANILHA
  // =========================================================================

  public function abrirImportar(): void
  {
    $this->garantirPermissao('criar');
    $this->arquivoImportacao = null;
    $this->previaImportacao = null;
    $this->resetValidation();
    $this->modalImportarAberto = true;
  }

  public function fecharImportar(): void
  {
    $this->modalImportarAberto = false;
    $this->arquivoImportacao = null;
    $this->previaImportacao = null;
  }

  public function analisarImportacao(): void
  {
    $this->garantirPermissao('criar');

    $this->validate(['arquivoImportacao' => 'required|file|mimes:xlsx,xlsm'], [], ['arquivoImportacao' => 'planilha']);

    $importador = new DocumentoEngenhariaImporter();

    try {
      $linhas = $importador->lerLinhas($this->arquivoImportacao->getRealPath());
    } catch (\RuntimeException $e) {
      $this->addError('arquivoImportacao', $e->getMessage());
      return;
    }

    if (empty($linhas)) {
      $this->addError('arquivoImportacao', 'Nenhuma linha com código encontrada na aba "LD" da planilha.');
      return;
    }

    $this->previaImportacao = $importador->analisar($linhas, $this->obraId);
  }

  public function confirmarImportacao(): void
  {
    $this->garantirPermissao('criar');

    if (!$this->previaImportacao) {
      return;
    }

    $importador = new DocumentoEngenhariaImporter();
    $linhas = $this->previaImportacao['linhas'];
    $obraId = $this->obraId;
    $usuarioId = Auth::id();
    $resultado = null;

    $this->transacaoSegura(function () use ($importador, $linhas, $obraId, $usuarioId, &$resultado) {
      $resultado = $importador->aplicar($linhas, $obraId, $usuarioId);
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->fecharImportar();
    unset($this->documentos, $this->totais, $this->disciplinas);
    $this->dispatch(
      'show-toast',
      message: "Importação concluída: {$resultado['novos']} novos, {$resultado['atualizados']} atualizados, {$resultado['novas_revisoes']} revisões novas."
    );
  }
};
?>

<div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Header --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
        <div>
            <h4 class="mb-1 mt-2">Lista de Documentos</h4>
            <p class="text-muted mb-0">Controle de documentos de engenharia — código, disciplina, status e histórico de emissões.</p>
        </div>
    </div>

    {{-- Seletor de obra --}}
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="row align-items-end g-3">
                <div class="col-md-6">
                    <label class="form-label mb-1">Obra</label>
                    <select class="form-select" wire:model.live="obraId">
                        <option value="">— Selecione uma obra —</option>
                        @foreach($this->obras as $obra)
                        <option value="{{ $obra->id }}">{{ $obra->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if($obraId)
                <div class="col-auto d-flex gap-2">
                    @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'criar'))
                    <button class="btn btn-primary" wire:click="abrirCriarDocumento">
                        <i class="bx bx-plus me-1"></i>Novo Documento
                    </button>
                    @endif
                    <button class="btn btn-outline-secondary" wire:click="abrirGerenciarPacotes">
                        <i class="bx bx-folder me-1"></i>Gerenciar Pacotes
                    </button>
                    @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'criar'))
                    <button class="btn btn-outline-secondary" wire:click="abrirImportar">
                        <i class="bx bx-upload me-1"></i>Importar Planilha
                    </button>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>

    @if(!$obraId)
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bx bx-buildings fs-1 d-block mb-2"></i>
            Selecione uma obra acima para ver a lista de documentos.
        </div>
    </div>
    @else

    {{-- Abas --}}
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <button class="nav-link {{ $abaAtiva === 'lista' ? 'active' : '' }}" wire:click="setAba('lista')" type="button">
                <i class="bx bx-list-ul me-1"></i>Lista de Documentos
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $abaAtiva === 'dashboard' ? 'active' : '' }}" wire:click="setAba('dashboard')" type="button">
                <i class="bx bx-bar-chart-alt-2 me-1"></i>Dashboard
            </button>
        </li>
    </ul>

    @if($abaAtiva === 'lista')

    {{-- Cards de decisão --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold">{{ $this->totais['total'] }}</div>
                <small class="text-muted">Total de Documentos</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-success"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-success">{{ $this->totais['concluidos'] }}</div>
                <small class="text-muted">Concluídos</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-warning"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-warning">{{ $this->totais['aguardando'] }}</div>
                <small class="text-muted">Aguardando 1ª Emissão</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-danger"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-danger">{{ $this->totais['atrasados'] }}</div>
                <small class="text-muted">Emissões Atrasadas</small>
            </div></div>
        </div>
    </div>

    {{-- Filtros --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                        <input type="text" class="form-control" placeholder="Buscar por código ou descrição..." wire:model.live.debounce.300ms="busca">
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="disciplinaIdFiltro">
                        <option value="">Todas as disciplinas</option>
                        @foreach($this->disciplinas as $d)
                        <option value="{{ $d->id }}">{{ $d->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="statusIdFiltro">
                        <option value="">Todos os status</option>
                        {{-- valor precisa bater com STATUS_FILTRO_NAO_EMITIDO na classe do componente --}}
                        <option value="__nao_emitido__">Não Emitido</option>
                        @foreach($this->statusDisponiveis as $s)
                        <option value="{{ $s->id }}">{{ $s->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="pacoteIdFiltro">
                        <option value="">Todos os pacotes</option>
                        @foreach($this->pacotes as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    @if($this->temFiltrosAtivos())
                    <button class="btn btn-outline-secondary btn-sm w-100" wire:click="limparFiltros">
                        <i class="bx bx-x me-1"></i>Limpar filtros
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Tabela --}}
    <div class="card">
        <div class="card-body p-0">
            @if($this->documentos->isEmpty())
            <div class="text-center py-5">
                <i class="bx bx-file fs-1 text-muted d-block mb-2"></i>
                <p class="text-muted mb-1">
                    @if($this->temFiltrosAtivos())
                    Nenhum documento encontrado com os filtros ativos.
                    @else
                    Nenhum documento de engenharia cadastrado ainda nesta obra.
                    @endif
                </p>
                @if(!$this->temFiltrosAtivos() && Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'criar'))
                <button class="btn btn-sm btn-primary mt-2" wire:click="abrirCriarDocumento">
                    <i class="bx bx-plus me-1"></i>Cadastrar o primeiro documento
                </button>
                @endif
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:15%">Código</th>
                            <th>Descrição</th>
                            <th style="width:15%">Disciplina</th>
                            <th style="width:12%">Status</th>
                            <th style="width:12%">Previsão de Emissão</th>
                            <th class="text-center" style="width:8%">Emissões</th>
                            <th style="width:10%">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->documentos as $documento)
                        <tr wire:key="documento-{{ $documento->id }}" wire:click="abrirRevisoes('{{ $documento->id }}')" style="cursor:pointer" title="Ver detalhes do documento">
                            <td class="fw-semibold">{{ $documento->codigo ?? '—' }}</td>
                            <td>
                                {{ $documento->descricao ?? '—' }}
                                @if($documento->pacote)
                                <br><span class="text-muted small"><i class="bx bx-folder me-1"></i>{{ $documento->pacote->nome }}</span>
                                @endif
                            </td>
                            <td class="small">{{ $documento->disciplina?->nome ?? '—' }}</td>
                            <td>
                                @if($documento->latestRevisao?->statusDocumento)
                                <span class="badge" style="background:{{ $documento->latestRevisao->statusDocumento->cor ?? '#6c757d' }}; color:#fff">{{ $documento->latestRevisao->statusDocumento->nome }}</span>
                                @else
                                <span class="badge bg-label-secondary">Não Emitido</span>
                                @endif
                            </td>
                            <td class="small {{ $documento->estaAtrasado() ? 'text-danger fw-semibold' : 'text-muted' }}">
                                {{ $documento->data_planejada?->format('d/m/Y') ?? '—' }}
                                @if($documento->reprogramacoes_count > 0)
                                <span class="badge rounded-pill bg-danger ms-1" title="{{ $documento->reprogramacoes_count }} reprogramação(ões)">
                                    {{ $documento->reprogramacoes_count }}
                                </span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-label-primary" title="Emissões registradas">
                                    <i class="bx bx-history me-1"></i>{{ $documento->revisoes_count }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'editar'))
                                <button class="btn btn-xs btn-outline-secondary py-0 px-1" title="Editar"
                                        wire:click.stop="editarDocumento('{{ $documento->id }}')">
                                    <i class="bx bx-pencil"></i>
                                </button>
                                @endif
                                @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'excluir'))
                                <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" title="Excluir"
                                        onclick="event.stopPropagation(); confirmarAcao(this, {
                                            mensagem: 'Remover o documento \'{{ $documento->codigo }}\'?',
                                            metodo: 'excluirDocumento',
                                            args: ['{{ $documento->id }}'],
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
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-top">
                <div class="d-flex align-items-center gap-2">
                    <small class="text-muted text-nowrap">{{ $this->documentos->total() }} documento(s) encontrado(s)</small>
                    <select class="form-select form-select-sm" style="width:auto" wire:model.live="perPage">
                        @foreach([5,10,15,20,25,50] as $n)
                        <option value="{{ $n }}">{{ $n }} por página</option>
                        @endforeach
                    </select>
                </div>
                {{ $this->documentos->links() }}
            </div>
            @endif
        </div>
    </div>

    @endif {{-- fim if($abaAtiva === 'lista') --}}

    @if($abaAtiva === 'dashboard')
    @include('pages.engenharia._partials.documentos-dashboard')
    @endif

    @endif {{-- fim if(!$obraId) ... else --}}

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Criar/Editar documento --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($modalDocumentoAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editandoDocumentoId ? 'Editar Documento' : 'Novo Documento' }}</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalDocumentoAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label">Código <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('codigoNovo') is-invalid @enderror" wire:model="codigoNovo">
                            @error('codigoNovo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('descricaoNovo') is-invalid @enderror" wire:model="descricaoNovo">
                            @error('descricaoNovo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Disciplina</label>
                            <select class="form-select" wire:model="disciplinaIdNovo">
                                <option value="">— Sem disciplina —</option>
                                @foreach($this->disciplinas as $d)
                                <option value="{{ $d->id }}">{{ $d->nome }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Data de Previsão de Emissão
                                @if(!$editandoDocumentoId)<span class="text-danger">*</span>@endif
                            </label>
                            <input type="date" class="form-control @error('dataPrevistaNovo') is-invalid @enderror" wire:model="dataPrevistaNovo">
                            @error('dataPrevistaNovo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if($editandoDocumentoId)
                            <small class="text-muted">Mudar esta data fica registrado como reprogramação.</small>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Pacote <span class="text-muted">(opcional)</span>
                            </label>
                            <select class="form-select" wire:model="pacoteIdNovo">
                                <option value="">— Sem pacote —</option>
                                @foreach($this->pacotes as $p)
                                <option value="{{ $p->id }}">{{ $p->nome }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @if($editandoDocumentoId)
                    <p class="text-muted small mb-0">
                        <i class="bx bx-info-circle me-1"></i>Status não é editável aqui — é sempre o da emissão mais
                        recente. Registre uma nova emissão pra atualizar o status.
                    </p>
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="$set('modalDocumentoAberto', false)">Cancelar</button>
                    <button class="btn btn-primary" wire:click="salvarDocumento">
                        <i class="bx bx-check me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Gerenciar Pacotes --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($modalPacotesAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-folder me-2"></i>Gerenciar Pacotes</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalPacotesAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Pacotes agrupam documentos por conveniência (ex.: frente de trabalho) — são opcionais.</p>

                    @forelse($this->pacotes as $pacote)
                    <div class="d-flex align-items-center gap-2 mb-2">
                        @if($pacoteEditandoId === $pacote->id)
                        <input type="text" class="form-control form-control-sm @error('pacoteEditandoNome') is-invalid @enderror" wire:model="pacoteEditandoNome">
                        <button class="btn btn-xs btn-outline-primary py-0 px-1" wire:click="salvarEdicaoPacote"><i class="bx bx-check"></i></button>
                        <button class="btn btn-xs btn-outline-secondary py-0 px-1" wire:click="cancelarEdicaoPacote"><i class="bx bx-x"></i></button>
                        @else
                        <span class="flex-grow-1">{{ $pacote->nome }}</span>
                        @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'editar'))
                        <button class="btn btn-xs btn-outline-secondary py-0 px-1" wire:click="iniciarEdicaoPacote('{{ $pacote->id }}')"><i class="bx bx-pencil"></i></button>
                        @endif
                        @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'excluir'))
                        <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1"
                                onclick="confirmarAcao(this, {
                                    mensagem: 'Remover o pacote \'{{ $pacote->nome }}\'? Os documentos vinculados ficam sem pacote.',
                                    metodo: 'excluirPacote',
                                    args: ['{{ $pacote->id }}'],
                                    icone: 'bx-trash',
                                })">
                            <i class="bx bx-trash"></i>
                        </button>
                        @endif
                        @endif
                    </div>
                    @empty
                    <p class="text-muted small">Nenhum pacote cadastrado ainda.</p>
                    @endforelse

                    @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'criar'))
                    <div class="d-flex gap-2 mt-3">
                        <input type="text" class="form-control form-control-sm @error('novoPacoteNome') is-invalid @enderror"
                               wire:model="novoPacoteNome" placeholder="Nome do novo pacote...">
                        <button class="btn btn-sm btn-primary flex-shrink-0" wire:click="criarPacote">
                            <i class="bx bx-plus"></i>
                        </button>
                    </div>
                    @error('novoPacoteNome')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="$set('modalPacotesAberto', false)">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Importar Planilha --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($modalImportarAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-upload me-2"></i>Importar Planilha</h5>
                    <button type="button" class="btn-close" wire:click="fecharImportar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        Lê a aba <strong>"LD"</strong> da planilha (Disciplina / Código / Revisão / Título / Status).
                        Documentos existentes (por código) são atualizados; documentos novos são criados. Uma nova
                        revisão só é registrada quando a Revisão da planilha muda em relação à última já salva.
                    </p>

                    @if(!$previaImportacao)
                    <div class="mb-3">
                        <label class="form-label">Arquivo (.xlsx)</label>
                        <input type="file" class="form-control @error('arquivoImportacao') is-invalid @enderror"
                               wire:model="arquivoImportacao" accept=".xlsx,.xlsm">
                        @error('arquivoImportacao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @else
                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <div class="card h-100"><div class="card-body py-2 text-center">
                                <div class="fs-5 fw-bold">{{ $previaImportacao['novos'] }}</div>
                                <small class="text-muted">Documentos novos</small>
                            </div></div>
                        </div>
                        <div class="col-4">
                            <div class="card h-100"><div class="card-body py-2 text-center">
                                <div class="fs-5 fw-bold">{{ $previaImportacao['atualizados'] }}</div>
                                <small class="text-muted">Atualizados</small>
                            </div></div>
                        </div>
                        <div class="col-4">
                            <div class="card h-100"><div class="card-body py-2 text-center">
                                <div class="fs-5 fw-bold">{{ $previaImportacao['novas_revisoes'] }}</div>
                                <small class="text-muted">Revisões novas</small>
                            </div></div>
                        </div>
                    </div>

                    @if(!empty($previaImportacao['avisos']))
                    <div class="alert alert-warning py-2">
                        <strong class="d-block mb-1"><i class="bx bx-error me-1"></i>{{ count($previaImportacao['avisos']) }} aviso(s):</strong>
                        <ul class="mb-0 small" style="max-height:180px; overflow-y:auto">
                            @foreach($previaImportacao['avisos'] as $aviso)
                            <li>{{ $aviso }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="fecharImportar">Cancelar</button>
                    @if(!$previaImportacao)
                    <button class="btn btn-primary" wire:click="analisarImportacao" wire:loading.attr="disabled">
                        <i class="bx bx-search-alt me-1"></i>Analisar
                    </button>
                    @else
                    <button class="btn btn-primary" wire:click="confirmarImportacao" wire:loading.attr="disabled">
                        <i class="bx bx-check me-1"></i>Confirmar Importação
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Revisões do documento --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($documentoRevisoesId && $this->documentoRevisoes)
    @php $doc = $this->documentoRevisoes; @endphp
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-file me-2"></i>{{ $doc->codigo }}
                        <span class="text-muted small d-block fw-normal">{{ $doc->descricao }}</span>
                    </h5>
                    <button type="button" class="btn-close" wire:click="fecharRevisoes"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-4 mb-4 pb-3 border-bottom">
                        <div>
                            <small class="text-muted d-block">Disciplina</small>
                            <span>{{ $doc->disciplina?->nome ?? '—' }}</span>
                        </div>
                        <div>
                            <small class="text-muted d-block">Status</small>
                            @php $statusAtual = $doc->statusAtual(); @endphp
                            @if($statusAtual)
                            <span class="badge" style="background:{{ $statusAtual->cor ?? '#6c757d' }}; color:#fff">{{ $statusAtual->nome }}</span>
                            @else
                            <span class="badge bg-label-secondary">Não Emitido</span>
                            @endif
                        </div>
                        <div>
                            <small class="text-muted d-block">Pacote</small>
                            <span>{{ $doc->pacote?->nome ?? '—' }}</span>
                        </div>
                        <div>
                            <small class="text-muted d-block">Previsão de Emissão</small>
                            <span class="{{ $doc->estaAtrasado() ? 'text-danger fw-semibold' : '' }}">
                                {{ $doc->data_planejada?->format('d/m/Y') ?? '—' }}
                            </span>
                            @if($doc->reprogramacoes->count() > 0)
                            <span class="badge rounded-pill bg-danger ms-1">{{ $doc->reprogramacoes->count() }}</span>
                            @endif
                        </div>
                        <div>
                            <small class="text-muted d-block">Emissões registradas</small>
                            <span><i class="bx bx-history me-1"></i>{{ $doc->revisoes->count() }}</span>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Histórico de Emissões</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:80px">Revisão</th>
                                    <th style="width:110px">Emissão</th>
                                    <th>Motivo da Emissão</th>
                                    <th>Comentários</th>
                                    <th style="width:70px">Anexo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($doc->revisoes as $rev)
                                <tr>
                                    <td class="fw-semibold">{{ $rev->revisao }}</td>
                                    <td class="small text-muted">{{ $rev->data_emissao?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="small">{{ $rev->descricao }}</td>
                                    <td class="small text-muted">{{ $rev->comentarios ?? '—' }}</td>
                                    <td class="text-center">
                                        @if($rev->anexoUrl())
                                        <a href="{{ $rev->anexoUrl() }}" download="{{ $rev->anexo_nome_original }}" title="Baixar {{ $rev->anexo_nome_original }}">
                                            <i class="bx bxs-file-pdf text-danger fs-5"></i>
                                        </a>
                                        @else
                                        <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">Nenhuma emissão registrada ainda.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($doc->reprogramacoes->isNotEmpty())
                    <h6 class="fw-bold mb-3">Histórico de Reprogramações</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:130px">Data Anterior</th>
                                    <th style="width:130px">Nova Data</th>
                                    <th>Quem</th>
                                    <th style="width:150px">Quando</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($doc->reprogramacoes as $reprog)
                                <tr>
                                    <td class="small text-muted">{{ $reprog->data_anterior?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="small fw-semibold">{{ $reprog->data_nova?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="small">{{ $reprog->criadoPor?->first_name }} {{ $reprog->criadoPor?->last_name }}</td>
                                    <td class="small text-muted">{{ $reprog->created_at->format('d/m/Y H:i') }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif

                    @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'editar'))
                    <div class="border-top pt-3">
                        <h6 class="fw-bold mb-3">+ Nova Emissão</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Revisão <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm @error('revisaoNovaTexto') is-invalid @enderror"
                                       wire:model="revisaoNovaTexto" placeholder="ex: R0">
                                @error('revisaoNovaTexto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data de Emissão</label>
                                <input type="date" class="form-control form-control-sm @error('revisaoNovaData') is-invalid @enderror" wire:model="revisaoNovaData">
                                @error('revisaoNovaData')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Anexo (PDF)</label>
                                <input type="file" class="form-control form-control-sm @error('revisaoNovaAnexo') is-invalid @enderror" wire:model="revisaoNovaAnexo" accept="application/pdf">
                                @error('revisaoNovaAnexo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm @error('revisaoNovaStatusId') is-invalid @enderror" wire:model="revisaoNovaStatusId">
                                    <option value="">— Selecione —</option>
                                    @foreach($this->statusDisponiveis as $s)
                                    <option value="{{ $s->id }}">{{ $s->nome }}</option>
                                    @endforeach
                                </select>
                                @error('revisaoNovaStatusId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Motivo da Emissão <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm @error('revisaoNovaDescricao') is-invalid @enderror"
                                   wire:model="revisaoNovaDescricao" placeholder="ex: Emitido para aprovação do cliente">
                            @error('revisaoNovaDescricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comentários</label>
                            <textarea class="form-control form-control-sm" rows="2" wire:model="revisaoNovaComentarios"></textarea>
                        </div>
                        <button class="btn btn-primary btn-sm" wire:click="adicionarRevisao" wire:loading.attr="disabled">
                            <i class="bx bx-plus me-1"></i>Adicionar Emissão
                        </button>
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="fecharRevisoes">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@script
<script>
    $wire.on('show-toast', ({ message, type = 'success' }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            (toastr[type] || toastr.success)(message);
        }
    });

    {{--
        Montagem dos gráficos Chart.js da aba Dashboard — @script só executa
        UMA VEZ, no mount inicial do componente, nunca de novo em requests
        Livewire seguintes (troca de aba, troca de mês). Por isso os dados
        não vêm de @json($this->xxx) lidos aqui dentro (isso só refletiria o
        estado do primeiro carregamento da página), e sim de um listener
        persistente ($wire.on), registrado uma única vez, que recebe os
        dados via evento despachado pelo PHP (dashboard-dados-atualizados)
        toda vez que a aba Dashboard é aberta ou o mês/obra muda — mesmo
        mecanismo já usado pelo listener 'show-toast' logo acima.
    --}}
    function criarOuSubstituir(canvasId, configFn) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const existente = Chart.getChart(canvas);
        if (existente) existente.destroy();
        configFn(canvas);
    }

    function montarCurva(canvasId, dados) {
        if (!dados || !dados.labels.length) return;

        criarOuSubstituir(canvasId, (canvas) => new Chart(canvas, {
            data: {
                labels: dados.labels,
                datasets: [
                    { type: 'bar', label: 'Previsto (qtd.)', data: dados.qtdPrevisto, backgroundColor: '#a5a8f5', yAxisID: 'yQtd', order: 2 },
                    { type: 'bar', label: 'Realizado (qtd.)', data: dados.qtdRealizado, backgroundColor: '#a8eeb9', yAxisID: 'yQtd', order: 2 },
                    { type: 'line', label: 'Previsto (acum. %)', data: dados.pctPrevistoAcumulado, borderColor: '#696cff', backgroundColor: '#696cff', tension: 0.3, yAxisID: 'yPct', order: 1 },
                    { type: 'line', label: 'Realizado (acum. %)', data: dados.pctRealizadoAcumulado, borderColor: '#71dd37', backgroundColor: '#71dd37', tension: 0.3, yAxisID: 'yPct', order: 1 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    yQtd: {
                        type: 'linear', position: 'left', min: 0,
                        title: { display: true, text: 'Qtd. no período' },
                    },
                    yPct: {
                        type: 'linear', position: 'right', min: 0, max: 100,
                        grid: { drawOnChartArea: false },
                        ticks: { callback: (v) => v + '%' },
                        title: { display: true, text: '% acumulado' },
                    },
                },
                plugins: { legend: { position: 'bottom' } },
            },
        }));
    }

    $wire.on('dashboard-dados-atualizados', (dados) => {
        if (!dados.obraId) return;

        montarCurva('chart-mensal', dados.mensal);
        montarCurva('chart-semanal', dados.semanal);

        if (dados.disciplina && dados.disciplina.labels.length) {
            criarOuSubstituir('chart-disciplina', (canvas) => new Chart(canvas, {
                type: 'bar',
                data: { labels: dados.disciplina.labels, datasets: [{ label: 'Documentos', data: dados.disciplina.valores, backgroundColor: '#696cff' }] },
                options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } },
            }));
        }

        if (dados.status && dados.status.labels.length) {
            criarOuSubstituir('chart-status', (canvas) => new Chart(canvas, {
                type: 'doughnut',
                data: { labels: dados.status.labels, datasets: [{ data: dados.status.valores, backgroundColor: dados.status.cores }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } },
            }));
        }

        if (dados.aging && dados.aging.valores.reduce((a, b) => a + b, 0) > 0) {
            criarOuSubstituir('chart-aging', (canvas) => new Chart(canvas, {
                type: 'bar',
                data: { labels: dados.aging.labels, datasets: [{ label: 'Documentos atrasados', data: dados.aging.valores, backgroundColor: '#ff3e1d' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } },
            }));
        }
    });
</script>
@endscript
