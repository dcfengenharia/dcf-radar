<?php

use App\Imports\DocumentoEngenhariaImporter;
use App\Models\Atividade;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaAtividade;
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
  /** Pseudo-valores de statusIdFiltro usados pelos cards de KPI clicáveis — espelham exatamente as condições já usadas em totais(). */
  const STATUS_FILTRO_AGUARDANDO = '__aguardando__';
  const STATUS_FILTRO_ATRASADO = '__atrasado__';
  const STATUS_FILTRO_CONCLUIDO = '__concluido__';

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

  // ---- Vínculo com Atividades (Ciclo 18, Etapa 18.1) ----
  public string $buscaAtividadeVincular = '';

  // ---- Modal importar planilha ----
  public bool $modalImportarAberto = false;
  public $arquivoImportacao = null;
  public ?array $previaImportacao = null;
  /** 'novos'|'atualizacao' — escolhido no passo inicial do modal, antes do upload. */
  public ?string $tipoImportacao = null;

  private function garantirPermissao(string $acao): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', $acao), 403);
  }

  /**
   * Ciclo 18, Etapa 18.1.CORREÇÃO — três fronteiras nesta página: tenant
   * (BelongsToTenant, automático), OBRA ATUAL do componente ($this->obraId)
   * e permissão do usuário NESSA obra especificamente — nunca "em alguma
   * obra do tenant" (garantirPermissao(), que o resto da página ainda usa
   * de propósito — dívida arquitetural pré-existente, fora do escopo
   * desta correção pontual). Usada só pelos fluxos de vínculo
   * Documento↔Atividade, os únicos endereçados por esta correção.
   */
  private function garantirPermissaoNaObraAtual(string $acao): void
  {
    abort_unless(
      Auth::user()?->temPermissaoNaObra($this->obraId, 'engenharia.pacotes', $acao),
      403
    );
  }

  /**
   * Resolve o Documento SEMPRE escopado à obra atual do componente —
   * nunca tenant-only. Documento de outra obra (mesmo tenant) ou
   * inexistente vira 404, ANTES de qualquer mutação de estado Livewire.
   */
  private function resolverDocumentoDaObraAtual(string $documentoId): DocumentoEngenharia
  {
    return DocumentoEngenharia::where('obra_id', $this->obraId)->findOrFail($documentoId);
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
      ->when($this->statusIdFiltro === self::STATUS_FILTRO_AGUARDANDO, fn($q) => $q->doesntHave('revisoes')
        ->where(fn($q2) => $q2->whereNull('data_planejada')->orWhere('data_planejada', '>=', now()->toDateString())))
      ->when($this->statusIdFiltro === self::STATUS_FILTRO_ATRASADO, fn($q) => $q->doesntHave('revisoes')
        ->whereNotNull('data_planejada')->where('data_planejada', '<', now()->toDateString()))
      ->when($this->statusIdFiltro === self::STATUS_FILTRO_CONCLUIDO, fn($q) => $q->whereHas(
        'latestRevisao',
        fn($q2) => $q2->whereHas('statusDocumento', fn($q3) => $q3->where('conclusivo', true))
      ))
      ->when(
        $this->statusIdFiltro && !in_array($this->statusIdFiltro, [
          self::STATUS_FILTRO_NAO_EMITIDO,
          self::STATUS_FILTRO_AGUARDANDO,
          self::STATUS_FILTRO_ATRASADO,
          self::STATUS_FILTRO_CONCLUIDO,
        ], true),
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
      ->with([
        'disciplina:id,nome',
        'latestRevisao.statusDocumento:id,nome,cor,conclusivo',
        // Ciclo 18, Etapa 18.3 — situação de liberação da revisão vigente,
        // eager-load em massa (nunca N+1 por documento da Lista Mestra).
        'latestRevisao.ultimaLiberacao',
        'pacote:id,nome',
      ])
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

  /**
   * Ciclo 18, Etapa 18.1.CORREÇÃO — defesa em profundidade: este computed
   * roda passivamente a cada render (a Blade lê $this->documentoRevisoes
   * dentro de um @if), então nunca deve LANÇAR em caso de estado
   * incoerente (documentoRevisoesId manipulado direto via Livewire pra
   * um Documento de outra obra) — isso quebraria o render da página
   * inteira. Em vez disso, degrada pra `null` (a Blade já trata esse caso
   * normalmente) tanto pra obra incoerente quanto pra permissão ausente
   * — nenhum metadata do Documento de outra obra chega a ser montado.
   * abrirRevisoes() é quem faz a validação "de verdade" (com abort/404),
   * ANTES de setar documentoRevisoesId — este computed é só a segunda
   * camada, pro caso de alguém pular abrirRevisoes() e mexer no estado
   * diretamente.
   */
  #[Computed]
  public function documentoRevisoes(): ?DocumentoEngenharia
  {
    if (!$this->documentoRevisoesId) {
      return null;
    }

    if (!Auth::user()?->temPermissaoNaObra($this->obraId, 'engenharia.pacotes', 'ver')) {
      return null;
    }

    return DocumentoEngenharia::where('obra_id', $this->obraId)
      ->with([
        'revisoes.criadoPor:id,first_name,last_name',
        'revisoes.statusDocumento',
        // Ciclo 18, Etapa 18.3 — histórico completo de liberação de CADA
        // revisão (não só a vigente) pra exibir no modal.
        'revisoes.historicoLiberacoes.alteradoPor:id,first_name,last_name',
        // Cada linha da tabela de histórico chama estaLiberadaParaConstrucao()
        // (via ultimaLiberacao, relação DIFERENTE de historicoLiberacoes
        // acima) — sem isso, lazy load é disparado e quebra (preventLazyLoading ativo).
        'revisoes.ultimaLiberacao.alteradoPor:id,first_name,last_name',
        // Precisa vir eager-loaded (mesmo objeto de $doc->revisaoVigente())
        // pra nunca disparar lazy-load (Model::preventLazyLoading ativo
        // fora de produção) quando o Blade chama estaLiberadoParaConstrucao().
        'latestRevisao.ultimaLiberacao.alteradoPor:id,first_name,last_name',
        // Etapa 18.3.CORREÇÃO — achado durante a implementação: o
        // cabeçalho do modal chama $doc->statusAtual() (linha 1674, "Status"
        // acima do histórico de emissões), que lê $this->latestRevisao->
        // statusDocumento — sem este eager-load, dispara lazy-load. Mesmo
        // caminho que já era eager-loaded em documentos() (Lista Mestra).
        'latestRevisao.statusDocumento',
        'disciplina',
        'pacote',
        'reprogramacoes.criadoPor:id,first_name,last_name',
      ])
      ->find($this->documentoRevisoesId);
  }

  /**
   * Ordem obrigatória (Etapa 18.1.CORREÇÃO): 1) permissão NA OBRA ATUAL,
   * 2) resolver o Documento NA OBRA ATUAL (404 se for de outra obra —
   * mesmo tenant ou não), só DEPOIS 3) setar qualquer estado Livewire.
   * Documento de outra obra nunca chega a ser "aberto" nem por um
   * instante.
   */
  public function abrirRevisoes(string $documentoId): void
  {
    $this->garantirPermissaoNaObraAtual('ver');
    $documento = $this->resolverDocumentoDaObraAtual($documentoId);

    $this->documentoRevisoesId = $documento->id;
    $this->buscaAtividadeVincular = '';
    unset($this->atividadesVinculadas, $this->atividadesParaVincular);
    $this->resetFormRevisao();
  }

  public function fecharRevisoes(): void
  {
    $this->documentoRevisoesId = null;
    $this->buscaAtividadeVincular = '';
    unset($this->atividadesVinculadas, $this->atividadesParaVincular);
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

    // Ciclo 18, Etapa 18.2 — extraído para App\Actions\Engenharia\
    // AnexarRevisaoDocumento (storage privado + atomicidade arquivo↔banco
    // com compensação). Deliberadamente NÃO usa transacaoSegura()/
    // DB::transaction() aqui: envolver o storage físico numa transaction
    // SQL reintroduziria a janela de "commit falhou depois do storage ter
    // sucesso" já auditada no Ciclo 17 — a Action já tem sua própria
    // atomicidade correta (arquivo primeiro, registro depois, compensação
    // se o registro falhar).
    try {
      app(\App\Actions\Engenharia\AnexarRevisaoDocumento::class)->execute(
        $documento,
        [
          'revisao' => $this->revisaoNovaTexto,
          'data_emissao' => $this->revisaoNovaData ?: null,
          'status_documento_id' => $this->revisaoNovaStatusId,
          'descricao' => $this->revisaoNovaDescricao,
          'comentarios' => $this->revisaoNovaComentarios ?: null,
        ],
        $this->revisaoNovaAnexo,
        Auth::id()
      );
    } catch (\Throwable $e) {
      report($e);
      $this->dispatch('show-toast', message: 'Não foi possível concluir a ação. Tente novamente em instantes.', type: 'error');

      return;
    }

    $this->resetFormRevisao();
    unset($this->documentoRevisoes, $this->documentos, $this->totais);
    $this->dispatch('show-toast', message: 'Emissão adicionada.');
  }

  /**
   * Ciclo 18, Etapa 18.3 — liberar/revogar sempre operam sobre a REVISÃO
   * VIGENTE do Documento atualmente aberto no modal, nunca sobre um ID de
   * revisão vindo de fora (impossível manipular pra atingir uma revisão
   * antiga ou de outro documento). Reaproveita EXATAMENTE os helpers de
   * contexto já corrigidos/auditados na Etapa 18.1.CORREÇÃO
   * (garantirPermissaoNaObraAtual/resolverDocumentoDaObraAtual) — nunca
   * o erro já corrigido de autorização tenant-wide.
   */
  public function liberarRevisaoVigente(): void
  {
    $this->garantirPermissaoNaObraAtual('editar');
    $documento = $this->resolverDocumentoDaObraAtual($this->documentoRevisoesId);
    $revisao = $documento->revisaoVigente();
    abort_if($revisao === null, 404);

    // Etapa 18.3.CORREÇÃO — $revisao já É a vigente fresca resolvida
    // acima, então a guarda de domínio da Action nunca deveria disparar
    // por este caminho; o catch cobre só a janela teórica de corrida
    // (outra requisição criou uma revisão mais nova entre as duas linhas
    // acima e a chamada da Action), nunca deixando exceção crua na tela.
    try {
      app(\App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento::class)->liberar($revisao, Auth::user());
    } catch (\App\Exceptions\RevisaoDocumentoNaoVigenteException) {
      unset($this->documentoRevisoes, $this->documentos);
      $this->dispatch('show-toast', message: 'Esta revisão não é mais a vigente — a lista foi atualizada, tente novamente.', type: 'error');

      return;
    }

    unset($this->documentoRevisoes, $this->documentos);
    $this->dispatch('show-toast', message: 'Revisão liberada para construção.');
  }

  public function revogarLiberacaoRevisaoVigente(): void
  {
    $this->garantirPermissaoNaObraAtual('editar');
    $documento = $this->resolverDocumentoDaObraAtual($this->documentoRevisoesId);
    $revisao = $documento->revisaoVigente();
    abort_if($revisao === null, 404);

    try {
      app(\App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento::class)->revogar($revisao, Auth::user());
    } catch (\App\Exceptions\RevisaoDocumentoNaoVigenteException) {
      unset($this->documentoRevisoes, $this->documentos);
      $this->dispatch('show-toast', message: 'Esta revisão não é mais a vigente — a lista foi atualizada, tente novamente.', type: 'error');

      return;
    }

    unset($this->documentoRevisoes, $this->documentos);
    $this->dispatch('show-toast', message: 'Liberação para construção revogada.');
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
  // VÍNCULO COM ATIVIDADES (Ciclo 18, Etapa 18.1)
  // =========================================================================

  /**
   * Atividades já vinculadas ao documento aberto no modal — 1 query em
   * lote (nunca N+1: a listagem principal de documentos nunca carrega
   * atividades, só este computed do modal de detalhe, sob demanda).
   * Parte de `$this->documentoRevisoes` (já escopado à obra atual +
   * permissão 'ver', com cache de #[Computed] — não gera query extra)
   * em vez de ler `documentoRevisoesId` cru: se o Documento não resolveu
   * (outra obra ou sem permissão), nunca chega a montar nenhuma atividade.
   */
  #[Computed]
  public function atividadesVinculadas(): \Illuminate\Support\Collection
  {
    $documento = $this->documentoRevisoes;
    if (!$documento) {
      return collect();
    }

    return Atividade::query()
      ->whereHas('documentosEngenharia', fn($q) => $q->where('documento_engenharia_id', $documento->id))
      ->orderBy('codigo_cronograma')
      ->get(['id', 'codigo_cronograma', 'nome', 'status', 'fora_do_cronograma']);
  }

  /**
   * Resultado da busca de atividades pra vincular — parte de
   * `$this->documentoRevisoes` (já escopado à obra atual + permissão
   * 'ver'), nunca confia no `documentoRevisoesId` cru nem no que a UI
   * mostra (vincularAtividade() revalida tudo de novo no servidor). Só
   * busca com 2+ caracteres e limita a 20 resultados — nunca renderiza
   * milhares de <option>. Atividades arquivadas (fora_do_cronograma) não
   * entram na busca de NOVOS vínculos, mas um vínculo já existente com
   * uma atividade que depois foi arquivada continua aparecendo em
   * atividadesVinculadas().
   */
  #[Computed]
  public function atividadesParaVincular(): \Illuminate\Support\Collection
  {
    $busca = trim($this->buscaAtividadeVincular);
    if (mb_strlen($busca) < 2) {
      return collect();
    }

    $documento = $this->documentoRevisoes;
    if (!$documento) {
      return collect();
    }

    $jaVinculadasIds = DocumentoEngenhariaAtividade::where('documento_engenharia_id', $documento->id)
      ->pluck('atividade_id');

    return Atividade::query()
      ->where('obra_id', $documento->obra_id)
      ->where('fora_do_cronograma', false)
      ->whereNotIn('id', $jaVinculadasIds)
      ->where(function ($q) use ($busca) {
        $q->where('nome', 'like', "%{$busca}%")
          ->orWhere('codigo_cronograma', 'like', "%{$busca}%");
      })
      ->orderBy('codigo_cronograma')
      ->limit(20)
      ->get(['id', 'codigo_cronograma', 'nome']);
  }

  public function updatedBuscaAtividadeVincular(): void
  {
    unset($this->atividadesParaVincular);
  }

  /**
   * Ciclo 18, Etapa 18.1.CORREÇÃO — ordem obrigatória: 1) permissão
   * 'editar' NA OBRA ATUAL do componente (nunca "em alguma obra do
   * tenant"); 2) resolver o Documento NA OBRA ATUAL; 3) resolver a
   * Atividade NA OBRA ATUAL (nunca só comparar atividade.obra_id ===
   * documento.obra_id entre si — isso permitia Documento B + Atividade B
   * com o componente contextualizado em A, achado da auditoria
   * adversarial). Cross-tenant continua coberto de graça pelo global
   * scope de BelongsToTenant nos dois `where('obra_id', ...)`.
   * Atividade arquivada só bloqueia quando é um vínculo GENUINAMENTE
   * NOVO — um clique duplo numa atividade já vinculada que foi arquivada
   * nesse meio tempo continua idempotente (nunca quebra por trás de um
   * duplo-clique).
   */
  public function vincularAtividade(string $atividadeId): void
  {
    $this->garantirPermissaoNaObraAtual('editar');

    $documento = $this->resolverDocumentoDaObraAtual($this->documentoRevisoesId);
    $atividade = Atividade::where('obra_id', $this->obraId)->findOrFail($atividadeId);

    $jaVinculada = $documento->atividades()->where('atividade_id', $atividade->id)->exists();
    if (!$jaVinculada) {
      abort_unless(!$atividade->fora_do_cronograma, 403, 'Não é possível vincular uma atividade arquivada.');
    }

    $this->transacaoSegura(function () use ($documento, $atividade) {
      // syncWithoutDetaching é idempotente por natureza — chamar duas
      // vezes pra mesma atividade nunca cria uma segunda linha no pivô.
      $documento->atividades()->syncWithoutDetaching([$atividade->id]);
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->buscaAtividadeVincular = '';
    unset($this->atividadesVinculadas, $this->atividadesParaVincular);
    $this->dispatch('show-toast', message: 'Atividade vinculada.');
  }

  /**
   * Ciclo 18, Etapa 18.1.CORREÇÃO — este era o ponto mais crítico da
   * auditoria: a versão anterior não validava obra nenhuma aqui (nem a
   * checagem mínima que vincularAtividade() já tinha), permitindo
   * desvincular um par Documento×Atividade de OUTRA obra do tenant só
   * manipulando `documentoRevisoesId` (propriedade pública Livewire).
   * Ordem obrigatória: 1) permissão 'editar' NA OBRA ATUAL; 2) resolver
   * Documento NA OBRA ATUAL; 3) resolver Atividade NA OBRA ATUAL; 4)
   * confirmar que o vínculo realmente EXISTE entre os dois (404 se não —
   * nunca um detach silencioso de "nada").
   */
  public function desvincularAtividade(string $atividadeId): void
  {
    $this->garantirPermissaoNaObraAtual('editar');

    $documento = $this->resolverDocumentoDaObraAtual($this->documentoRevisoesId);
    $atividade = Atividade::where('obra_id', $this->obraId)->findOrFail($atividadeId);

    abort_unless(
      $documento->atividades()->where('atividade_id', $atividade->id)->exists(),
      404
    );

    $this->transacaoSegura(function () use ($documento, $atividade) {
      $documento->atividades()->detach($atividade->id);
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    unset($this->atividadesVinculadas, $this->atividadesParaVincular);
    $this->dispatch('show-toast', message: 'Atividade desvinculada.');
  }

  // =========================================================================
  // MODAL IMPORTAR PLANILHA
  // =========================================================================

  public function abrirImportar(): void
  {
    $this->garantirPermissao('criar');
    $this->arquivoImportacao = null;
    $this->previaImportacao = null;
    $this->tipoImportacao = null;
    $this->resetValidation();
    $this->modalImportarAberto = true;
  }

  public function escolherTipoImportacao(string $tipo): void
  {
    $this->tipoImportacao = in_array($tipo, ['novos', 'atualizacao'], true) ? $tipo : null;
  }

  public function voltarTipoImportacao(): void
  {
    $this->tipoImportacao = null;
    $this->previaImportacao = null;
  }

  public function fecharImportar(): void
  {
    $this->modalImportarAberto = false;
    $this->arquivoImportacao = null;
    $this->previaImportacao = null;
    $this->tipoImportacao = null;
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

    $previa = $importador->analisar($linhas, $this->obraId);
    $this->previaImportacao = $this->filtrarPorTipoImportacao($previa);
  }

  /**
   * Separa as linhas da prévia (já deduplicadas por analisar()) em
   * aplicáveis vs ignoradas, conforme o tipo escolhido no passo inicial
   * do modal — não altera DocumentoEngenhariaImporter::analisar()/
   * aplicar(), só decide qual subconjunto de $previa['linhas'] chega até
   * aplicar(). Consulta nova e pequena (só código→id), mesmo espírito de
   * "duplicar um helper pequeno" já usado em outras telas do projeto em
   * vez de mexer numa classe já testada.
   */
  private function filtrarPorTipoImportacao(array $previa): array
  {
    $codigos = array_column($previa['linhas'], 'codigo');
    $existentes = DocumentoEngenharia::where('obra_id', $this->obraId)
      ->whereIn('codigo', $codigos)
      ->pluck('id', 'codigo');

    $aplicaveis = [];
    $ignorados = [];

    foreach ($previa['linhas'] as $linha) {
      $existe = $existentes->has($linha['codigo']);

      if ($this->tipoImportacao === 'novos' && $existe) {
        $ignorados[] = $linha + ['motivo' => 'já existe — não será criado por este caminho'];
      } elseif ($this->tipoImportacao === 'atualizacao' && !$existe) {
        $ignorados[] = $linha + ['motivo' => 'não encontrado — não será atualizado por este caminho'];
      } else {
        $aplicaveis[] = $linha;
      }
    }

    $previa['linhas'] = $aplicaveis;
    $previa['ignorados'] = $ignorados;

    return $previa;
  }

  public function confirmarImportacao(): void
  {
    $this->garantirPermissao('criar');

    if (!$this->previaImportacao) {
      return;
    }

    $importador = new DocumentoEngenhariaImporter();
    $linhas = $this->previaImportacao['linhas'];
    $ignorados = count($this->previaImportacao['ignorados'] ?? []);
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
    $mensagem = "Importação concluída: {$resultado['novos']} novos, {$resultado['atualizados']} atualizados, {$resultado['novas_revisoes']} revisões novas.";
    if ($ignorados > 0) {
      $mensagem .= " {$ignorados} linha(s) ignorada(s) por não corresponder ao tipo escolhido.";
    }
    $this->dispatch('show-toast', message: $mensagem);
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

    {{-- Cards de decisão — clicáveis: aplicam o filtro de status correspondente na
         lista abaixo (valores precisam bater com as STATUS_FILTRO_* na classe do
         componente, mesma convenção já usada no <option> de "Não Emitido"). --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100" style="cursor:pointer" title="Ver todos" wire:click="$set('statusIdFiltro', null)"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold">{{ $this->totais['total'] }}</div>
                <small class="text-muted">Total de Documentos</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-success" style="cursor:pointer" title="Filtrar concluídos" wire:click="$set('statusIdFiltro', '__concluido__')"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-success">{{ $this->totais['concluidos'] }}</div>
                <small class="text-muted">Concluídos</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-warning" style="cursor:pointer" title="Filtrar aguardando 1ª emissão" wire:click="$set('statusIdFiltro', '__aguardando__')"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-warning">{{ $this->totais['aguardando'] }}</div>
                <small class="text-muted">Aguardando 1ª Emissão</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-danger" style="cursor:pointer" title="Filtrar atrasados" wire:click="$set('statusIdFiltro', '__atrasado__')"><div class="card-body py-3 text-center">
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
                            <th style="width:13%">Código</th>
                            <th>Descrição</th>
                            <th style="width:12%">Disciplina</th>
                            <th style="width:10%">Status</th>
                            <th style="width:14%">Situação Documental</th>
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
                            <td class="small">
                                {{-- Ciclo 18, Etapa 18.3 — situação documental: revisão vigente +
                                     liberação para construção, derivadas de $documento->revisaoVigente()/
                                     estaLiberadoParaConstrucao() (nunca texto solto). --}}
                                @if($documento->latestRevisao)
                                <span class="fw-semibold">{{ $documento->latestRevisao->revisao }}</span> —
                                @if($documento->estaLiberadoParaConstrucao())
                                <span class="badge bg-label-success">Liberado p/ construção</span>
                                @else
                                <span class="badge bg-label-warning">Não liberado</span>
                                @endif
                                @else
                                <span class="text-muted">Sem emissão</span>
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
                    @if(!$tipoImportacao)
                    {{-- Passo 0: escolha do tipo de importação --}}
                    <p class="text-muted small">O que esta planilha representa?</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card h-100 border-2" style="cursor:pointer" wire:click="escolherTipoImportacao('novos')">
                                <div class="card-body">
                                    <h6 class="mb-1"><i class="bx bx-plus-circle me-1 text-primary"></i>Nova Lista de Documentos</h6>
                                    <p class="small text-muted mb-0">
                                        A planilha traz documentos que ainda não existem no Registro Mestre desta
                                        obra. Códigos já cadastrados não serão alterados.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100 border-2" style="cursor:pointer" wire:click="escolherTipoImportacao('atualizacao')">
                                <div class="card-body">
                                    <h6 class="mb-1"><i class="bx bx-refresh me-1 text-warning"></i>Atualização de Status e Revisões</h6>
                                    <p class="small text-muted mb-0">
                                        A planilha traz informações atualizadas de documentos já existentes.
                                        Códigos não encontrados não serão criados.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    @else
                    <p class="text-muted small">
                        <span class="badge bg-label-{{ $tipoImportacao === 'novos' ? 'primary' : 'warning' }} me-1">
                            {{ $tipoImportacao === 'novos' ? 'Nova Lista de Documentos' : 'Atualização de Status e Revisões' }}
                        </span>
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" wire:click="voltarTipoImportacao">trocar</button>
                    </p>
                    <p class="text-muted small">
                        Lê a aba <strong>"LD"</strong> da planilha (Disciplina / Código / Revisão / Título / Status).
                        Uma nova revisão só é registrada quando a Revisão da planilha muda em relação à última já salva.
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
                        <div class="col-3">
                            <div class="card h-100"><div class="card-body py-2 text-center">
                                <div class="fs-5 fw-bold">{{ $previaImportacao['novos'] }}</div>
                                <small class="text-muted">Documentos novos</small>
                            </div></div>
                        </div>
                        <div class="col-3">
                            <div class="card h-100"><div class="card-body py-2 text-center">
                                <div class="fs-5 fw-bold">{{ $previaImportacao['atualizados'] }}</div>
                                <small class="text-muted">Atualizados</small>
                            </div></div>
                        </div>
                        <div class="col-3">
                            <div class="card h-100"><div class="card-body py-2 text-center">
                                <div class="fs-5 fw-bold">{{ $previaImportacao['novas_revisoes'] }}</div>
                                <small class="text-muted">Revisões novas</small>
                            </div></div>
                        </div>
                        <div class="col-3">
                            <div class="card h-100"><div class="card-body py-2 text-center">
                                <div class="fs-5 fw-bold {{ count($previaImportacao['ignorados'] ?? []) > 0 ? 'text-muted' : '' }}">{{ count($previaImportacao['ignorados'] ?? []) }}</div>
                                <small class="text-muted">Ignorados</small>
                            </div></div>
                        </div>
                    </div>

                    @if(!empty($previaImportacao['ignorados']))
                    <div class="alert alert-secondary py-2">
                        <strong class="d-block mb-1"><i class="bx bx-info-circle me-1"></i>{{ count($previaImportacao['ignorados']) }} linha(s) não correspondem ao tipo escolhido:</strong>
                        <ul class="mb-0 small" style="max-height:150px; overflow-y:auto">
                            @foreach($previaImportacao['ignorados'] as $linhaIgnorada)
                            <li>Código "{{ $linhaIgnorada['codigo'] }}" (linha {{ $linhaIgnorada['linha'] }}) — {{ $linhaIgnorada['motivo'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

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
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="fecharImportar">Cancelar</button>
                    @if($tipoImportacao && !$previaImportacao)
                    <button class="btn btn-primary" wire:click="analisarImportacao" wire:loading.attr="disabled">
                        <i class="bx bx-search-alt me-1"></i>Analisar
                    </button>
                    @elseif($previaImportacao)
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
                                    <th style="width:170px">Liberação p/ Construção</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $revisaoVigenteId = $doc->latestRevisao?->id; @endphp
                                @forelse($doc->revisoes as $rev)
                                @php $ehVigente = $rev->id === $revisaoVigenteId; @endphp
                                <tr wire:key="revisao-{{ $rev->id }}" class="{{ $ehVigente ? 'table-active' : '' }}">
                                    <td class="fw-semibold">
                                        {{ $rev->revisao }}
                                        @if($ehVigente)
                                        <span class="badge bg-label-primary ms-1" style="font-size:0.65rem">vigente</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted">{{ $rev->data_emissao?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="small">{{ $rev->descricao }}</td>
                                    <td class="small text-muted">{{ $rev->comentarios ?? '—' }}</td>
                                    <td class="text-center">
                                        @if($rev->anexo_path)
                                        {{-- Ciclo 18, Etapa 18.2 — storage privado: nunca mais uma URL pública
                                             direta (Storage::url()); o navegador sempre passa pelo controller
                                             autorizado, que decide inline (visualizar) vs attachment (baixar). --}}
                                        <a href="{{ route('documentos-engenharia.revisoes.download', $rev) }}?inline=1" target="_blank" rel="noopener" class="me-2" title="Visualizar {{ $rev->anexo_nome_original }}">
                                            <i class="bx bxs-file-pdf text-danger fs-5"></i>
                                        </a>
                                        <a href="{{ route('documentos-engenharia.revisoes.download', $rev) }}" title="Baixar {{ $rev->anexo_nome_original }}">
                                            <i class="bx bx-download fs-5"></i>
                                        </a>
                                        @else
                                        <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{-- Ciclo 18, Etapa 18.3 — badge de liberação, imutável pra revisões
                                             antigas (histórico) e com ação só na revisão VIGENTE. --}}
                                        @if($rev->estaLiberadaParaConstrucao())
                                        <span class="badge bg-label-success d-block mb-1">
                                            <i class="bx bx-check-circle me-1"></i>Liberada
                                        </span>
                                        <span class="text-muted" style="font-size:0.7rem">
                                            @if($rev->liberadaParaConstrucaoPor())
                                            {{ $rev->liberadaParaConstrucaoPor()->first_name }} {{ $rev->liberadaParaConstrucaoPor()->last_name }}
                                            @else
                                            Usuário removido
                                            @endif
                                            em {{ $rev->liberadaParaConstrucaoEm()?->format('d/m/Y H:i') }}
                                        </span>
                                        @else
                                        <span class="badge bg-label-secondary">Não liberada</span>
                                        @endif

                                        @if($ehVigente && Auth::user()->temPermissaoNaObra($obraId, 'engenharia.pacotes', 'editar'))
                                        <div class="mt-1">
                                            @if($rev->estaLiberadaParaConstrucao())
                                            <button type="button" class="btn btn-xs btn-outline-warning" style="font-size:0.7rem; padding:2px 6px"
                                                    onclick="confirmarAcao(this, { mensagem: 'Revogar a liberação para construção desta revisão?', metodo: 'revogarLiberacaoRevisaoVigente', args: [], corBotao: 'warning', icone: 'bx-undo' })">
                                                Revogar
                                            </button>
                                            @else
                                            <button type="button" class="btn btn-xs btn-outline-success" style="font-size:0.7rem; padding:2px 6px"
                                                    onclick="confirmarAcao(this, { mensagem: 'Liberar esta revisão para construção?', metodo: 'liberarRevisaoVigente', args: [], corBotao: 'success', icone: 'bx-check' })">
                                                Liberar
                                            </button>
                                            @endif
                                        </div>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">Nenhuma emissão registrada ainda.</td></tr>
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

                    {{-- Ciclo 18, Etapa 18.1 — Atividades vinculadas --}}
                    <div class="border-top pt-3 mb-4">
                        <h6 class="fw-bold mb-3">Atividades Vinculadas</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:110px">Código</th>
                                        <th>Nome</th>
                                        <th style="width:130px">Status</th>
                                        @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'editar'))
                                        <th style="width:110px"></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($this->atividadesVinculadas as $atv)
                                    <tr wire:key="atividade-vinculada-{{ $atv->id }}">
                                        <td class="small">{{ $atv->codigo_cronograma ?? '—' }}</td>
                                        <td class="small">
                                            {{ $atv->nome }}
                                            @if($atv->fora_do_cronograma)
                                            <span class="badge bg-label-secondary ms-1">Arquivada</span>
                                            @endif
                                        </td>
                                        <td class="small text-muted">{{ $atv->status->value }}</td>
                                        @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'editar'))
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="confirmarAcao(this, { mensagem: 'Desvincular esta atividade do documento?', metodo: 'desvincularAtividade', args: ['{{ $atv->id }}'], corBotao: 'danger', icone: 'bx-unlink' })">
                                                Desvincular
                                            </button>
                                        </td>
                                        @endif
                                    </tr>
                                    @empty
                                    <tr><td colspan="4" class="text-center text-muted py-3">Nenhuma atividade vinculada ainda.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'editar'))
                        <div class="row g-2 align-items-start">
                            <div class="col-md-6">
                                <label class="form-label small">Vincular atividade</label>
                                <input type="text" class="form-control form-control-sm" wire:model.live.debounce.400ms="buscaAtividadeVincular"
                                       placeholder="Buscar por código ou nome (mín. 2 caracteres)...">
                                @if(mb_strlen(trim($buscaAtividadeVincular)) >= 2)
                                <div class="list-group mt-1" style="max-height:220px; overflow-y:auto;">
                                    @forelse($this->atividadesParaVincular as $candidata)
                                    <button type="button" wire:key="candidata-{{ $candidata->id }}"
                                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1 px-2"
                                            wire:click="vincularAtividade('{{ $candidata->id }}')">
                                        <span class="small"><strong>{{ $candidata->codigo_cronograma ?? '—' }}</strong> — {{ $candidata->nome }}</span>
                                        <i class="bx bx-plus text-primary"></i>
                                    </button>
                                    @empty
                                    <span class="list-group-item small text-muted py-1 px-2">Nenhuma atividade encontrada.</span>
                                    @endforelse
                                </div>
                                @endif
                            </div>
                        </div>
                        @endif
                    </div>

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
