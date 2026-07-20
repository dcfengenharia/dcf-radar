<?php

use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemAtividade;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Exports\LookaheadExport;
use App\Models\Atividade;
use App\Notifications\PlanoSemanalGeradoNotification;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\CategoriaRestricao;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\ItemProntidao;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\Restricao;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
  use ExecutaComTransacaoSegura;

  public Work $obra;

  // ---- Filtros ----
  public string $fonteData = 'tendencia'; // 'baseline' | 'tendencia'
  public int $janelaDias = 30; // 30 | 60 | 90 | 0 (0 = todo o cronograma, sem filtro de data)
  public bool $ocultarConcluidas = true;
  public string $search = '';
  public ?string $etapaIdFiltro = null;
  public ?string $frenteTrabalhoIdFiltro = null;
  public ?string $faturamentoDiretoFiltro = null; // '' = todos | '1' = sim | '0' = não
  public ?string $entregavelIdFiltro = null;
  public ?string $equipeResponsavelIdFiltro = null;
  public ?string $personalizado1IdFiltro = null;
  public ?string $personalizado2IdFiltro = null;
  public ?string $personalizado3IdFiltro = null;
  public ?string $personalizado4IdFiltro = null;
  public ?string $personalizado5IdFiltro = null;
  public ?string $tendenciaImportacaoId = null; // null = importação mais recente
  public ?string $linhaBaseId = null; // null = baseline ao vivo da tabela atividades

  // ---- Modal nova atividade ----
  public bool $modalAtividadeAberto = false;
  public string $nomeNovaAtividade = '';
  public ?string $disciplinaIdNova = null;
  public ?string $frenteTrabalhoIdNova = null;
  public ?string $etapaIdNova = null;
  public ?string $pacoteTrabalhoIdNova = null;
  public ?string $inicioNovaAtividade = null;
  public ?string $terminoNovaAtividade = null;
  public ?string $responsavelIdNovaAtividade = null;

  // ---- Modal restrição (atividade travada) ----
  public bool $modalRestricaoAberto = false;
  public ?string $atividadeIdRestricao = null;
  public string $descricaoNova = '';
  public bool $blocanteNova = true;
  public ?string $prazolimiteNova = null;
  public ?int $probabilidadeNova = null;
  public ?int $impactoNova = null;
  public ?string $categoriaIdNova = null;
  public ?string $responsavelIdNova = null;
  public string $responsavelExternoNova = '';
  public bool $responsavelExterno = false;

  // ---- Modal detalhe / matriz de prontidão ----
  public ?string $modalAtividadeId = null;
  public string $comentarioNovoAtividade = '';

  // ---- Modal dar baixa na restrição ----
  public ?string $baixandoRestricaoId = null;
  public ?string $dataBaixaNova = null;
  public string $textoBaixaNova = '';

  // ---- Confirmação "Gerar Plano Semanal" ----
  public bool $modalGerarPlanoAberto = false;

  // ---- Configurar impressão ----
  public bool $modalImprimirAberto = false;
  public string $orientacaoImpressao = 'landscape'; // 'portrait' | 'landscape'

  public function mount(Work $obra): void
  {
    $this->obra = $obra;
  }

  // =========================================================================
  // COMPUTED — DADOS DE REFERÊNCIA (cache compartilhado com outras telas)
  // =========================================================================

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
  public function disciplinas()
  {
    return Disciplina::orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function frentesTrabalho()
  {
    return FrenteTrabalho::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function etapas()
  {
    return Etapa::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function entregaveis()
  {
    return Entregavel::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function equipesResponsaveis()
  {
    return EquipeResponsavel::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados1()
  {
    return Personalizado1::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados2()
  {
    return Personalizado2::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados3()
  {
    return Personalizado3::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados4()
  {
    return Personalizado4::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados5()
  {
    return Personalizado5::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
  }

  /**
   * Compara dois códigos de EAP (ex: "5.1.10.1" vs "5.1.3") segmento a
   * segmento como números, não como string — senão "10" fica antes de
   * "3" na comparação lexicográfica.
   */
  private function compararCodigos(?string $a, ?string $b): int
  {
    $a = explode('.', $a ?? '');
    $b = explode('.', $b ?? '');

    foreach (range(0, max(count($a), count($b)) - 1) as $i) {
      $x = (int) ($a[$i] ?? 0);
      $y = (int) ($b[$i] ?? 0);
      if ($x !== $y) {
        return $x <=> $y;
      }
    }

    return 0;
  }

  /**
   * Ordena atividades dentro do mesmo grupo (pacote) por ordem_manual —
   * atividades nunca reordenadas manualmente (ordem_manual = null) caem
   * pelo código do cronograma (posição original no MS Project), e só na
   * falta dele por início planejado e nome como desempate estável (dado
   * legado ou atividade manual nunca importada).
   */
  private function compararOrdemAtividade(Atividade $a, Atividade $b): int
  {
    if ($a->ordem_manual !== null && $b->ordem_manual !== null) {
      return $a->ordem_manual <=> $b->ordem_manual;
    }
    if ($a->ordem_manual !== null) {
      return -1;
    }
    if ($b->ordem_manual !== null) {
      return 1;
    }

    if ($a->codigo_cronograma !== null && $b->codigo_cronograma !== null) {
      return $this->compararCodigos($a->codigo_cronograma, $b->codigo_cronograma);
    }

    $ia = $a->inicio_planejado?->timestamp ?? PHP_INT_MAX;
    $ib = $b->inicio_planejado?->timestamp ?? PHP_INT_MAX;

    return $ia !== $ib ? $ia <=> $ib : strcmp($a->nome, $b->nome);
  }

  /**
   * Grupo de atividades (mesmo pacote) já ordenado pelo comparador acima —
   * usado tanto por linhasArvore() (exibição) quanto por moverAtividade()
   * (reordenar), pra garantir que os dois enxerguem a mesma sequência.
   */
  private function grupoDeAtividadesOrdenado(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
  {
    return $rows->sort(fn($a, $b) => $this->compararOrdemAtividade($a['atividade'], $b['atividade']))->values();
  }

  /**
   * Lista indentada de todos os PacoteTrabalho da obra (EAP), pra o
   * usuário escolher onde uma atividade manual entra na hierarquia.
   */
  #[Computed]
  public function pacotesParaSelecao(): array
  {
    $todos = PacoteTrabalho::where('obra_id', $this->obra->id)->get(['id', 'nome', 'codigo', 'parent_id']);
    $porPai = $todos->groupBy('parent_id');

    $resultado = [];

    $percorrer = function ($paiId, int $nivel) use (&$percorrer, &$resultado, $porPai) {
      $filhos = ($porPai->get($paiId) ?? collect())->sort(fn($a, $b) => $this->compararCodigos($a->codigo, $b->codigo));

      foreach ($filhos as $p) {
        $resultado[] = [
          'id' => $p->id,
          'label' => str_repeat('— ', $nivel) . ($p->codigo ? "{$p->codigo} · " : '') . $p->nome,
        ];
        $percorrer($p->id, $nivel + 1);
      }
    };

    $percorrer(null, 0);

    return $resultado;
  }

  #[Computed]
  public function importacoesDisponiveis()
  {
    return CronogramaImportacao::where('obra_id', $this->obra->id)
      ->orderByDesc('importado_em')
      ->get(['id', 'arquivo', 'importado_em']);
  }

  #[Computed]
  public function linhasBase()
  {
    return LinhaBase::where('obra_id', $this->obra->id)
      ->with('importacao:id,importado_em,arquivo')
      ->latest()
      ->get(['id', 'nome', 'cronograma_importacao_id']);
  }

  /** Importação sendo tratada como "tendência" — a selecionada, ou a mais recente. */
  #[Computed]
  public function importacaoTendenciaAtual(): ?CronogramaImportacao
  {
    if ($this->tendenciaImportacaoId) {
      return $this->importacoesDisponiveis->firstWhere('id', $this->tendenciaImportacaoId);
    }

    return $this->importacoesDisponiveis->first();
  }

  #[Computed]
  public function linhaBaseSelecionada(): ?LinhaBase
  {
    if (!$this->linhaBaseId) {
      return null;
    }

    return $this->linhasBase->firstWhere('id', $this->linhaBaseId);
  }

  // =========================================================================
  // COMPUTED — LISTAGEM PRINCIPAL
  // =========================================================================

  #[Computed]
  public function atividades()
  {
    $query = Atividade::with(['frenteTrabalho:id,nome', 'disciplina:id,nome'])
      ->where('obra_id', $this->obra->id)
      ->where('fora_do_cronograma', false)
      ->withCount([
        'restricoes as restricoes_bloqueantes' => fn($q) => $q
          ->where('bloqueante', true)
          ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros']),
        'restricoes as restricoes_nao_bloqueantes' => fn($q) => $q
          ->where('bloqueante', false)
          ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros']),
        'comentarios',
      ]);

    if ($this->ocultarConcluidas) {
      $query->where('status', '!=', StatusAtividade::Concluido->value);
    }
    if ($this->search !== '') {
      $query->where('nome', 'like', '%' . $this->search . '%');
    }
    if ($this->etapaIdFiltro) {
      $query->where('etapa_id', $this->etapaIdFiltro);
    }
    if ($this->frenteTrabalhoIdFiltro) {
      $query->where('frente_trabalho_id', $this->frenteTrabalhoIdFiltro);
    }
    if ($this->faturamentoDiretoFiltro !== null && $this->faturamentoDiretoFiltro !== '') {
      $query->where('faturamento_direto', $this->faturamentoDiretoFiltro === '1');
    }
    if ($this->entregavelIdFiltro) {
      $query->where('entregavel_id', $this->entregavelIdFiltro);
    }
    if ($this->equipeResponsavelIdFiltro) {
      $query->where('equipe_responsavel_id', $this->equipeResponsavelIdFiltro);
    }
    if ($this->personalizado1IdFiltro) {
      $query->where('personalizado_1_id', $this->personalizado1IdFiltro);
    }
    if ($this->personalizado2IdFiltro) {
      $query->where('personalizado_2_id', $this->personalizado2IdFiltro);
    }
    if ($this->personalizado3IdFiltro) {
      $query->where('personalizado_3_id', $this->personalizado3IdFiltro);
    }
    if ($this->personalizado4IdFiltro) {
      $query->where('personalizado_4_id', $this->personalizado4IdFiltro);
    }
    if ($this->personalizado5IdFiltro) {
      $query->where('personalizado_5_id', $this->personalizado5IdFiltro);
    }

    $atividades = $query->get();

    // Snapshots só são buscados quando o usuário escolhe uma importação/linha
    // de base específica (fora do "atual") — no caso padrão, lê direto da
    // tabela atividades (mais rápido, igual comportamento de antes).
    $snapshotsTendencia = $this->tendenciaImportacaoId
      ? AtividadeSnapshot::where('cronograma_importacao_id', $this->tendenciaImportacaoId)
        ->whereIn('atividade_id', $atividades->pluck('id'))
        ->get()
        ->keyBy('atividade_id')
      : collect();

    $lb = $this->linhaBaseSelecionada;
    $snapshotsBaseline = $lb
      ? AtividadeSnapshot::where('cronograma_importacao_id', $lb->cronograma_importacao_id)
        ->whereIn('atividade_id', $atividades->pluck('id'))
        ->get()
        ->keyBy('atividade_id')
      : collect();

    $totalItens = $this->itensProntidao->count();

    $itensOkMap =
      $totalItens > 0
        ? AtividadeItemProntidao::whereIn('atividade_id', $atividades->pluck('id'))
          ->where('concluido', true)
          ->selectRaw('atividade_id, COUNT(*) as total')
          ->groupBy('atividade_id')
          ->pluck('total', 'atividade_id')
        : collect();

    $hoje = now()->startOfDay();
    $fimJanela = now()
      ->startOfDay()
      ->addDays($this->janelaDias);

    return $atividades
      ->map(function ($at) use ($totalItens, $itensOkMap, $snapshotsTendencia, $snapshotsBaseline, $hoje, $fimJanela) {
        if ($this->tendenciaImportacaoId) {
          $snap = $snapshotsTendencia->get($at->id);
          $inicioTend = $snap?->inicio_planejado;
          $terminoTend = $snap?->data_termino;
        } else {
          $inicioTend = $at->inicio_planejado;
          $terminoTend = $at->data_termino;
        }

        if ($this->linhaBaseId) {
          $snapB = $snapshotsBaseline->get($at->id);
          $inicioBase = $snapB?->baseline_inicio;
          $terminoBase = $snapB?->baseline_termino;
        } else {
          $inicioBase = $at->baseline_inicio;
          $terminoBase = $at->baseline_termino;
        }

        $inicioJanela = $this->fonteData === 'baseline' ? $inicioBase : $inicioTend;
        $terminoJanela = $this->fonteData === 'baseline' ? $terminoBase : $terminoTend;

        // janelaDias = 0 significa "todo o cronograma" — sem filtro de data.
        $dentroDaJanela =
          $this->janelaDias === 0 ||
          ($inicioJanela && $inicioJanela->between($hoje, $fimJanela)) ||
          ($terminoJanela && $terminoJanela->between($hoje, $fimJanela));

        if (!$dentroDaJanela) {
          return null;
        }

        $itensOk = $totalItens > 0 ? (int) ($itensOkMap->get($at->id) ?? 0) : $totalItens;
        $pronta = $at->restricoes_bloqueantes === 0 && ($totalItens === 0 || $itensOk >= $totalItens);

        return [
          'atividade' => $at,
          'inicioTendencia' => $inicioTend,
          'terminoTendencia' => $terminoTend,
          'inicioBaseline' => $inicioBase,
          'terminoBaseline' => $terminoBase,
          'restricoesBloq' => $at->restricoes_bloqueantes,
          'restricoesNaoBloq' => $at->restricoes_nao_bloqueantes,
          'itensOk' => $itensOk,
          'totalItens' => $totalItens,
          'pronta' => $pronta,
        ];
      })
      ->filter()
      ->values();
  }

  /**
   * Achata a EAP (PacoteTrabalho) numa lista ordenada (pacotes + atividades
   * intercalados, com nível de profundidade e cadeia de ancestrais) para
   * renderizar como árvore expansível/recolhível sem precisar de includes
   * recursivos no Blade.
   */
  #[Computed]
  public function linhasArvore(): array
  {
    $linhas = $this->atividades;

    if ($linhas->isEmpty()) {
      return [];
    }

    $pacoteIdsComAtividade = $linhas
      ->pluck('atividade.pacote_trabalho_id')
      ->filter()
      ->unique();

    $todosPacotes = PacoteTrabalho::where('obra_id', $this->obra->id)
      ->get(['id', 'nome', 'codigo', 'parent_id'])
      ->keyBy('id');

    $idsRelevantes = collect();
    foreach ($pacoteIdsComAtividade as $pid) {
      $atual = $pid;
      while ($atual && $todosPacotes->has($atual)) {
        $idsRelevantes->push($atual);
        $atual = $todosPacotes->get($atual)->parent_id;
      }
    }
    $idsRelevantes = $idsRelevantes->unique();

    $atividadesPorPacote = $linhas->groupBy(fn($row) => $row['atividade']->pacote_trabalho_id ?? 'sem_pacote');

    $resultado = [];

    $percorrer = function (string $pacoteId, array $ancestrais) use (
      &$percorrer,
      &$resultado,
      $todosPacotes,
      $idsRelevantes,
      $atividadesPorPacote
    ) {
      $pacote = $todosPacotes->get($pacoteId);

      $resultado[] = [
        'tipo' => 'pacote',
        'id' => $pacote->id,
        'nivel' => count($ancestrais),
        'ancestrais' => $ancestrais,
        'pacote' => $pacote,
      ];

      $novosAncestrais = [...$ancestrais, $pacote->id];

      $filhos = $todosPacotes
        ->filter(fn($p) => $p->parent_id === $pacoteId && $idsRelevantes->contains($p->id))
        ->sort(fn($a, $b) => $this->compararCodigos($a->codigo, $b->codigo));

      foreach ($filhos as $filho) {
        $percorrer($filho->id, $novosAncestrais);
      }

      $grupo = $this->grupoDeAtividadesOrdenado($atividadesPorPacote->get($pacoteId, collect()));

      foreach ($grupo as $i => $row) {
        $resultado[] = [
          'tipo' => 'atividade',
          'id' => $row['atividade']->id,
          'nivel' => count($novosAncestrais),
          'ancestrais' => $novosAncestrais,
          'row' => $row,
          'posicaoNoGrupo' => $i,
          'totalNoGrupo' => $grupo->count(),
        ];
      }
    };

    // Nível raiz: intercala pacotes raiz E atividades sem pacote que
    // tenham código do cronograma (posição real no MS Project), numa
    // única sequência ordenada — em vez de jogar as órfãs sempre no
    // final, ignorando onde elas realmente ficam no cronograma (ex: um
    // marco de início solto, sem pacote pai). O agrupamento usado por
    // moverAtividade()/posicaoNoGrupo continua sendo o grupo "sem pacote"
    // completo (ordenado por compararOrdemAtividade, que já considera
    // codigo_cronograma) — só a posição visual entre os pacotes muda, não
    // o conceito de "o que se move junto".
    $grupoSemPacoteCompleto = $this->grupoDeAtividadesOrdenado($atividadesPorPacote->get('sem_pacote', collect()));
    $totalSemPacote = $grupoSemPacoteCompleto->count();

    $raizes = $todosPacotes->filter(fn($p) => $p->parent_id === null && $idsRelevantes->contains($p->id));

    $entradasRaiz = collect();
    foreach ($raizes as $pacote) {
      $entradasRaiz->push(['codigo' => $pacote->codigo, 'tipo' => 'pacote', 'payload' => $pacote]);
    }
    foreach ($grupoSemPacoteCompleto as $i => $row) {
      if ($row['atividade']->codigo_cronograma === null) {
        continue;
      }
      $entradasRaiz->push([
        'codigo' => $row['atividade']->codigo_cronograma,
        'tipo' => 'atividade',
        'payload' => $row,
        'posicaoNoGrupo' => $i,
      ]);
    }

    $entradasRaiz = $entradasRaiz->sort(fn($a, $b) => $this->compararCodigos($a['codigo'], $b['codigo']));

    foreach ($entradasRaiz as $entrada) {
      if ($entrada['tipo'] === 'pacote') {
        $percorrer($entrada['payload']->id, []);
        continue;
      }

      $resultado[] = [
        'tipo' => 'atividade',
        'id' => $entrada['payload']['atividade']->id,
        'nivel' => 0,
        'ancestrais' => [],
        'row' => $entrada['payload'],
        'posicaoNoGrupo' => $entrada['posicaoNoGrupo'],
        'totalNoGrupo' => $totalSemPacote,
      ];
    }

    // Órfãs sem código do cronograma (dado legado pré-migração, ou
    // atividade manual sem pacote): mantém o comportamento de sempre, no
    // final.
    foreach ($grupoSemPacoteCompleto as $i => $row) {
      if ($row['atividade']->codigo_cronograma !== null) {
        continue;
      }
      $resultado[] = [
        'tipo' => 'atividade',
        'id' => $row['atividade']->id,
        'nivel' => 0,
        'ancestrais' => [],
        'row' => $row,
        'posicaoNoGrupo' => $i,
        'totalNoGrupo' => $totalSemPacote,
      ];
    }

    return $resultado;
  }

  #[Computed]
  public function qtdProntasParaComprometer(): int
  {
    return $this->atividades
      ->filter(fn($row) => $row['pronta'] && $row['atividade']->status === StatusAtividade::Planejado)
      ->count();
  }

  // =========================================================================
  // FILTROS
  // =========================================================================

  private function invalidarListagem(): void
  {
    unset($this->atividades, $this->linhasArvore, $this->qtdProntasParaComprometer);
  }

  public function updatedFonteData(): void
  {
    $this->invalidarListagem();
  }
  public function updatedJanelaDias(): void
  {
    $this->invalidarListagem();
  }
  public function updatedOcultarConcluidas(): void
  {
    $this->invalidarListagem();
  }
  public function updatedSearch(): void
  {
    $this->invalidarListagem();
  }
  public function updatedEtapaIdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedFrenteTrabalhoIdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedFaturamentoDiretoFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedEntregavelIdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedEquipeResponsavelIdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedPersonalizado1IdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedPersonalizado2IdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedPersonalizado3IdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedPersonalizado4IdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedPersonalizado5IdFiltro(): void
  {
    $this->invalidarListagem();
  }
  public function updatedTendenciaImportacaoId(): void
  {
    $this->invalidarListagem();
  }
  public function updatedLinhaBaseId(): void
  {
    $this->invalidarListagem();
  }

  // =========================================================================
  // REORDENAR ATIVIDADES DENTRO DO PACOTE (subir/descer)
  // =========================================================================

  public function moverAtividadeCima(string $atividadeId): void
  {
    $this->moverAtividade($atividadeId, -1);
  }

  public function moverAtividadeBaixo(string $atividadeId): void
  {
    $this->moverAtividade($atividadeId, 1);
  }

  private function moverAtividade(string $atividadeId, int $direcao): void
  {
    $atividadeAlvo = Atividade::findOrFail($atividadeId);
    $this->authorize('update', $atividadeAlvo);

    $grupo = $this->grupoDeAtividadesOrdenado(
      $this->atividades->filter(
        fn($row) => $row['atividade']->pacote_trabalho_id === $atividadeAlvo->pacote_trabalho_id
      )
    )->pluck('atividade');

    $indiceAtual = $grupo->search(fn($a) => $a->id === $atividadeId);
    $indiceVizinho = $indiceAtual + $direcao;

    if ($indiceAtual === false || $indiceVizinho < 0 || $indiceVizinho >= $grupo->count()) {
      return;
    }

    $this->transacaoSegura(function () use ($grupo, $indiceAtual, $indiceVizinho) {
      // Backfill: garante ordem_manual sequencial pro grupo inteiro antes de
      // trocar (atividades nunca reordenadas manualmente têm ordem_manual = null).
      foreach ($grupo as $i => $a) {
        $a->ordem_manual = $i;
      }

      [$grupo[$indiceAtual]->ordem_manual, $grupo[$indiceVizinho]->ordem_manual] = [
        $grupo[$indiceVizinho]->ordem_manual,
        $grupo[$indiceAtual]->ordem_manual,
      ];

      foreach ($grupo as $a) {
        $a->save();
      }
    });

    $this->invalidarListagem();
  }

  // =========================================================================
  // MODAL: NOVA ATIVIDADE MANUAL
  // =========================================================================

  public function abrirModalNovaAtividade(): void
  {
    $this->authorize('create', [Atividade::class, $this->obra->id]);
    $this->resetModalAtividade();
    $this->modalAtividadeAberto = true;
  }

  private function resetModalAtividade(): void
  {
    $this->nomeNovaAtividade = '';
    $this->disciplinaIdNova = null;
    $this->frenteTrabalhoIdNova = null;
    $this->etapaIdNova = null;
    $this->pacoteTrabalhoIdNova = null;
    $this->inicioNovaAtividade = null;
    $this->terminoNovaAtividade = null;
    $this->responsavelIdNovaAtividade = null;
    $this->resetErrorBag();
  }

  public function salvarAtividade(): void
  {
    $this->authorize('create', [Atividade::class, $this->obra->id]);

    $this->validate(
      [
        'nomeNovaAtividade' => 'required|string|min:3',
        'disciplinaIdNova' => 'nullable|exists:disciplinas,id',
        'frenteTrabalhoIdNova' => 'nullable|exists:frentes_trabalho,id',
        'etapaIdNova' => 'nullable|exists:etapas,id',
        'pacoteTrabalhoIdNova' => 'nullable|exists:pacotes_trabalho,id',
        'inicioNovaAtividade' => 'required|date',
        'terminoNovaAtividade' => 'required|date|after_or_equal:inicioNovaAtividade',
        'responsavelIdNovaAtividade' => 'nullable|exists:users,id',
      ],
      [],
      [
        'nomeNovaAtividade' => 'nome da tarefa',
        'inicioNovaAtividade' => 'início',
        'terminoNovaAtividade' => 'término',
      ]
    );

    $inicio = Carbon::parse($this->inicioNovaAtividade);
    $termino = Carbon::parse($this->terminoNovaAtividade);

    $atividade = $this->transacaoSegura(fn () => Atividade::create([
      'obra_id' => $this->obra->id,
      'nome' => $this->nomeNovaAtividade,
      'disciplina_id' => $this->disciplinaIdNova ?: null,
      'frente_trabalho_id' => $this->frenteTrabalhoIdNova ?: null,
      'etapa_id' => $this->etapaIdNova ?: null,
      'pacote_trabalho_id' => $this->pacoteTrabalhoIdNova ?: null,
      'responsavel_id' => $this->responsavelIdNovaAtividade ?: null,
      'inicio_planejado' => $inicio,
      'data_termino' => $termino,
      // Atividade manual não vem de importação — replica as próprias datas
      // como baseline, senão nunca apareceria no filtro "por baseline".
      'baseline_inicio' => $inicio,
      'baseline_termino' => $termino,
      'duracao_dias' => $inicio->diffInDays($termino) + 1,
      'status' => StatusAtividade::Planejado->value,
      'origem' => OrigemAtividade::Manual->value,
    ]));

    if (! $atividade) {
      return;
    }

    $this->modalAtividadeAberto = false;
    $this->resetModalAtividade();
    $this->invalidarListagem();
    unset($this->frentesTrabalho, $this->pacotesParaSelecao);

    // Confirma se a atividade recém-criada realmente aparece na lista com
    // os filtros atuais — se não aparecer, avisa o motivo em vez de deixar
    // o usuário achando que "sumiu" sem explicação.
    $visivel = $this->atividades->contains(fn($row) => $row['atividade']->id === $atividade->id);

    if ($visivel) {
      $this->dispatch('show-toast', message: 'Atividade criada.');
    } else {
      $this->dispatch(
        'show-toast',
        message: 'Atividade criada, mas está fora do filtro atual (janela de dias, etapa ou frente de trabalho selecionados). Ajuste os filtros para vê-la.',
        type: 'warning'
      );
    }
  }

  // =========================================================================
  // MODAL: CRIAR RESTRIÇÃO (atividade pré-definida)
  // =========================================================================

  public function abrirModalRestricao(string $atividadeId): void
  {
    $this->authorize('create', [Restricao::class, $this->obra->id]);
    $this->resetModalRestricao();
    $this->atividadeIdRestricao = $atividadeId;
    $this->modalRestricaoAberto = true;
  }

  private function resetModalRestricao(): void
  {
    $this->atividadeIdRestricao = null;
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
    $this->authorize('create', [Restricao::class, $this->obra->id]);

    $this->validate(
      [
        'atividadeIdRestricao' => 'required|string|exists:atividades,id',
        'descricaoNova' => 'required|string|min:5',
        'probabilidadeNova' => 'nullable|integer|min:0|max:10',
        'impactoNova' => 'nullable|integer|min:0|max:10',
        'prazolimiteNova' => 'nullable|date',
        'categoriaIdNova' => 'nullable|exists:categorias_restricao,id',
        'responsavelIdNova' => 'nullable|exists:users,id',
      ],
      [],
      [
        'descricaoNova' => 'descrição',
      ]
    );

    $restricao = $this->transacaoSegura(fn () => Restricao::create([
      'atividade_id' => $this->atividadeIdRestricao,
      'descricao' => $this->descricaoNova,
      'bloqueante' => $this->blocanteNova,
      'probabilidade' => $this->probabilidadeNova,
      'impacto' => $this->impactoNova,
      'prazo_limite' => $this->prazolimiteNova,
      'categoria_id' => $this->categoriaIdNova ?: null,
      'responsavel_id' => !$this->responsavelExterno ? ($this->responsavelIdNova ?: null) : null,
      'responsavel_externo' => $this->responsavelExterno ? ($this->responsavelExternoNova ?: null) : null,
      'status' => StatusRestricao::Aberta->value,
      'aberta_em' => now(),
    ]));

    if (! $restricao) {
      return;
    }

    $this->modalRestricaoAberto = false;
    $this->resetModalRestricao();
    $this->dispatch('show-toast', message: 'Restrição registrada.');
    $this->invalidarListagem();
    unset($this->atividadeDetalhe);
  }

  // =========================================================================
  // MODAL: DAR BAIXA NA RESTRIÇÃO
  // =========================================================================

  public function abrirModalBaixa(string $restricaoId): void
  {
    $restricao = Restricao::findOrFail($restricaoId);
    $this->authorize('resolver', $restricao);

    $this->baixandoRestricaoId = $restricaoId;
    $this->dataBaixaNova = now()->toDateString();
    $this->textoBaixaNova = '';
    $this->resetErrorBag();
  }

  private function resetModalBaixa(): void
  {
    $this->baixandoRestricaoId = null;
    $this->dataBaixaNova = null;
    $this->textoBaixaNova = '';
    $this->resetErrorBag();
  }

  public function darBaixaRestricao(): void
  {
    $restricao = Restricao::findOrFail($this->baixandoRestricaoId);
    $this->authorize('resolver', $restricao);

    $this->validate(
      [
        'dataBaixaNova' => 'required|date|before_or_equal:today',
        'textoBaixaNova' => 'nullable|string',
      ],
      [],
      [
        'dataBaixaNova' => 'data da baixa',
      ]
    );

    $this->transacaoSegura(function () use ($restricao) {
      if ($this->textoBaixaNova !== '') {
        $restricao->acoes()->create(['autor_id' => Auth::id(), 'descricao' => $this->textoBaixaNova]);
      }

      $restricao->update([
        'status' => StatusRestricao::Resolvida->value,
        'resolvida_em' => Carbon::parse($this->dataBaixaNova),
      ]);
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetModalBaixa();
    $this->dispatch('show-toast', message: 'Restrição resolvida.');
    $this->invalidarListagem();
    unset($this->atividadeDetalhe);
  }

  // =========================================================================
  // MODAL: DETALHE / MATRIZ DE PRONTIDÃO
  // =========================================================================

  public function verAtividade(string $atividadeId): void
  {
    $this->modalAtividadeId = $atividadeId;
    unset($this->atividadeDetalhe);
  }

  #[Computed]
  public function atividadeDetalhe()
  {
    if (!$this->modalAtividadeId) {
      return null;
    }

    $at = Atividade::with([
      'restricoes' => fn($q) => $q
        ->with(['categoria:id,nome', 'responsavel:id,first_name,last_name'])
        ->orderByRaw("FIELD(status,'aberta','em_tratamento','aguardando_terceiros','resolvida')"),
      'frenteTrabalho:id,nome',
      'disciplina:id,nome',
      'comentarios' => fn($q) => $q->with('autor:id,first_name,last_name')->latest(),
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

    return [
      'atividade' => $at,
      'checklist' => $itens->map(
        fn($item) => [
          'id' => $item->id,
          'nome' => $item->nome,
          'concluido' => (bool) ($registros->get($item->id)?->concluido ?? false),
          'concluido_por' => $registros->get($item->id)?->conclusor,
          'concluido_em' => $registros->get($item->id)?->concluido_em,
        ]
      ),
    ];
  }

  public function marcarItemNaDetalhe(string $atividadeId, string $itemId, bool $valor): void
  {
    $this->transacaoSegura(function () use ($atividadeId, $itemId, $valor) {
      AtividadeItemProntidao::updateOrCreate(
        ['atividade_id' => $atividadeId, 'item_prontidao_id' => $itemId],
        ['concluido' => $valor, 'concluido_por' => $valor ? Auth::id() : null, 'concluido_em' => $valor ? now() : null]
      );
    });
    unset($this->atividadeDetalhe);
    $this->invalidarListagem();
  }

  public function adicionarComentarioAtividade(string $atividadeId): void
  {
    $this->validate(
      ['comentarioNovoAtividade' => 'required|string|min:2'],
      [],
      ['comentarioNovoAtividade' => 'comentário']
    );

    $atividade = Atividade::findOrFail($atividadeId);
    $this->authorize('comentar', $atividade);

    $this->transacaoSegura(fn () => $atividade->comentarios()->create(['autor_id' => Auth::id(), 'comentario' => $this->comentarioNovoAtividade]));

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->comentarioNovoAtividade = '';
    unset($this->atividadeDetalhe);
    $this->invalidarListagem();
    $this->dispatch('show-toast', message: 'Comentário adicionado.');
  }

  // =========================================================================
  // GERAR PLANO SEMANAL (comprometer em lote as prontas do filtro atual)
  // =========================================================================

  public function abrirConfirmacaoGerarPlano(): void
  {
    if ($this->qtdProntasParaComprometer === 0) {
      $this->dispatch('show-toast', message: 'Nenhuma atividade pronta no filtro atual.');
      return;
    }
    $this->modalGerarPlanoAberto = true;
  }

  public function gerarPlanoSemanal(): void
  {
    abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.lookahead', 'editar'), 403);

    $rows = $this->atividades
      ->filter(fn($row) => $row['pronta'] && $row['atividade']->status === StatusAtividade::Planejado);
    $atividadesAlvo = $rows->pluck('atividade');
    $ids = $atividadesAlvo->pluck('id');
    // Este botão comprometido sempre congela na semana CORRENTE do
    // sistema (mesma convenção de ⚡plano-semanal.blade.php::mount()) —
    // os filtros desta tela (janela de dias, fonte de dados etc.) não
    // têm noção de "semana", só decidem quais atividades são elegíveis.
    $semanaAlvo = Carbon::now()->startOfWeek()->toDateString();

    $this->transacaoSegura(function () use ($ids, $atividadesAlvo, $semanaAlvo) {
      foreach ($ids as $id) {
        Atividade::find($id)?->update(['status' => StatusAtividade::Comprometido->value]);
      }

      (new RegistrarComprometimentoSemanal)->execute(
        $this->obra, $semanaAlvo, $atividadesAlvo, OrigemProgramacaoSemanalItem::Lote
      );
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->modalGerarPlanoAberto = false;
    $count = $ids->count();
    $this->invalidarListagem();
    $this->dispatch('show-toast', message: "{$count} atividade(s) comprometida(s) no Plano Semanal.");

    if ($count > 0) {
      $usuario = Auth::user();
      $destinatarios = $this->obra
        ->users()
        ->where('users.id', '!=', $usuario->id)
        ->get();
      if ($destinatarios->isNotEmpty()) {
        \Illuminate\Support\Facades\Notification::send(
          $destinatarios,
          new PlanoSemanalGeradoNotification($this->obra, $usuario, $count)
        );
      }
    }
  }

  // =========================================================================
  // EXPORTAÇÃO (PDF / EXCEL) DA LISTA FILTRADA
  // =========================================================================

  public function exportarPdf()
  {
    $pdf = Pdf::loadView('exports.lookahead-pdf', [
      'obra' => $this->obra,
      'linhas' => $this->atividades,
      'janelaDias' => $this->janelaDias,
      'fonteData' => $this->fonteData,
    ]);

    return response()->streamDownload(
      fn() => print $pdf->output(),
      "lookahead-{$this->obra->id}-{$this->janelaDias}dias.pdf"
    );
  }

  public function exportarExcel()
  {
    return Excel::download(
      new LookaheadExport($this->atividades),
      "lookahead-{$this->obra->id}-{$this->janelaDias}dias.xlsx"
    );
  }

  public function abrirModalImprimir(): void
  {
    $this->modalImprimirAberto = true;
  }

  public function imprimirPdf()
  {
    $pdf = Pdf::loadView('exports.lookahead-arvore-pdf', [
      'obra' => $this->obra,
      'linhas' => $this->linhasArvore,
      'janelaDias' => $this->janelaDias,
      'fonteData' => $this->fonteData,
    ])->setPaper('a4', $this->orientacaoImpressao);

    $this->modalImprimirAberto = false;

    return response()->streamDownload(
      fn() => print $pdf->output(),
      "lookahead-arvore-{$this->obra->id}-{$this->janelaDias}dias.pdf"
    );
  }
};
?>

<div>

{{-- Overlay de loading --}}
<div wire:loading.flex class="position-fixed top-0 start-0 w-100 h-100 align-items-start justify-content-center"
     style="z-index:9999;background:rgba(255,255,255,.4);padding-top:80px">
    <div class="d-flex align-items-center gap-2 bg-white shadow rounded px-4 py-2 border">
        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        <span class="small text-muted">Calma aí bb...</span>
    </div>
</div>

{{-- Filtros: ver canva lateral (.canva-filtros-lookahead) perto do fim
     do arquivo, antes do fechamento da div raiz. --}}

{{-- =========================================================================
     TABELA (hierarquia EAP: pacotes expansíveis + atividades)
     ========================================================================= --}}
@if ($this->atividades->count() > 0)
@php
    $niveisExistentes = collect($this->linhasArvore)
        ->where('tipo', 'pacote')
        ->pluck('nivel')
        ->unique()
        ->sort()
        ->values();
@endphp
<div class="card table-responsive"
     x-data="{
        recolhidos: [],
        niveisIds: {{ json_encode(
            collect($this->linhasArvore)
                ->where('tipo', 'pacote')
                ->groupBy('nivel')
                ->map(fn($g) => $g->pluck('id')->values())
        ) }},
        colapsarAteNivel(nivel) {
            this.recolhidos = [];
            Object.entries(this.niveisIds).forEach(([n, ids]) => {
                if (parseInt(n) >= nivel) {
                    ids.forEach(id => {
                        if (!this.recolhidos.includes(id)) this.recolhidos.push(id);
                    });
                }
            });
        },
        expandirTudo() { this.recolhidos = []; }
     }">
    <div class="card-header py-2 d-flex align-items-center gap-2 flex-wrap border-bottom">
        <small class="text-muted me-1">Colapsar por nível:</small>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                @click="expandirTudo()">
            <i class="bx bx-expand-alt me-1"></i>Expandir tudo
        </button>
        @foreach ($niveisExistentes as $nv)
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                @click="colapsarAteNivel({{ $nv }})">
            Nível {{ $nv + 1 }}+
        </button>
        @endforeach
    </div>
    <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
            <tr>
                <th style="width: 30%;">Tarefa</th>
                <th style="width: 7%;">Disciplina</th>
                <th style="width: 10%;">Frente de Trabalho</th>
                <th class="text-center" style="width: 5%;">Início LB</th>
                <th class="text-center" style="width: 5%;">Término LB</th>
                <th class="text-center" style="width: 5%;">Início</th>
                <th class="text-center" style="width: 5%;">Término</th>
                <th class="text-center" style="width: 5%;">%</th>
                <th class="text-center" style="width: 5%;">Restrições</th>
                <th class="text-center" style="width: 10%;">Prontidão</th>
                <th class="text-center" style="width: 10%;">Status</th>
                <th class="text-center" style="width: 5%;">Ações</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($this->linhasArvore as $linha)
            @php $ancestraisJson = json_encode($linha['ancestrais']); @endphp
            <!-- SUMÁRIAS -->
            @if ($linha['tipo'] === 'pacote')
            <tr wire:key="pacote-{{ $linha['id'] }}"
                x-show="!({{ $ancestraisJson }}).some(id => recolhidos.includes(id))"
                class="table-light">
                <td colspan="12" style="padding-left: {{ $linha['nivel'] * 24 }}px">
                    <button type="button" class="btn btn-sm btn-link p-0 me-1 text-dark"
                            @click="recolhidos.includes('{{ $linha['id'] }}') ? recolhidos.splice(recolhidos.indexOf('{{ $linha['id'] }}'), 1) : recolhidos.push('{{ $linha['id'] }}')">
                        <i class="bx" :class="recolhidos.includes('{{ $linha['id'] }}') ? 'bx-chevron-right' : 'bx-chevron-down'"></i>
                    </button>
                    <i class="bx bx-folder text-warning me-1"></i>
                    <strong>{{ $linha['pacote']->codigo }} · {{ $linha['pacote']->nome }}</strong>
                </td>
            </tr>
            @else
            @php
                $row = $linha['row'];
                $at  = $row['atividade'];
                $pct = $row['totalItens'] > 0 ? round($row['itensOk'] / $row['totalItens'] * 100) : 100;
            @endphp
            <tr wire:key="atividade-{{ $linha['id'] }}"
                x-show="!({{ $ancestraisJson }}).some(id => recolhidos.includes(id))">
                <td style="max-width:220px; padding-left: 24px">
                    <button class="btn btn-link p-0 text-start fw-semibold text-dark lh-sm" style="font-size:.875rem"
                            wire:click="verAtividade('{{ $at->id }}')">
                        {{ $at->nome }}
                    </button>
                    @if ($at->caminho_critico)
                    <span class="badge bg-label-danger ms-1" style="font-size:.65rem">CC</span>
                    @endif
                    @if ($at->comentarios_count > 0)
                    <span class="badge bg-label-info ms-1" style="font-size:.65rem" title="{{ $at->comentarios_count }} comentário(s)">
                        <i class="bx bx-comment-detail"></i> {{ $at->comentarios_count }}
                    </span>
                    @endif
                </td>
                <td><small>{{ $at->disciplina?->nome ?? '—' }}</small></td>
                <td><small>{{ $at->frenteTrabalho?->nome ?? '—' }}</small></td>
                <td class="text-center"><small>{{ $row['inicioBaseline']?->format('d/m/y') ?? '—' }}</small></td>
                <td class="text-center"><small>{{ $row['terminoBaseline']?->format('d/m/y') ?? '—' }}</small></td>
                <td class="text-center"><small>{{ $row['inicioTendencia']?->format('d/m/y') ?? '—' }}</small></td>
                <td class="text-center"><small>{{ $row['terminoTendencia']?->format('d/m/y') ?? '—' }}</small></td>
                <td class="text-center">
                    @php
                        $pctVal = $at->percentual_concluido !== null ? (int) $at->percentual_concluido : null;
                    @endphp
                    @if($pctVal !== null)
                    @php
                        $r = 16; $circ = round(2 * M_PI * $r, 2);
                        $dash = round($pctVal / 100 * $circ, 2);
                        $clr = $pctVal >= 100 ? '#28a745' : ($pctVal >= 50 ? '#fd7e14' : '#007bff');
                    @endphp
                    <div class="d-inline-flex flex-column align-items-center" title="{{ $pctVal }}%">
                        <svg width="42" height="42" viewBox="0 0 42 42">
                            <circle cx="21" cy="21" r="{{ $r }}" fill="none" stroke="#e9ecef" stroke-width="5"/>
                            {{-- 100%: círculo cheio sem dasharray/linecap — com round linecap,
                                 o traço de volta completa deixa as duas pontas arredondadas
                                 (início e fim, no mesmo ponto) se sobrepondo de um jeito que
                                 parece uma "costura" aberta, mesmo o valor batendo 100%. --}}
                            @if($pctVal >= 100)
                            <circle cx="21" cy="21" r="{{ $r }}" fill="none" stroke="{{ $clr }}" stroke-width="5"/>
                            @else
                            <circle cx="21" cy="21" r="{{ $r }}" fill="none"
                                    stroke="{{ $clr }}" stroke-width="5"
                                    stroke-dasharray="{{ $dash }} {{ $circ }}"
                                    stroke-dashoffset="{{ round($circ / 4, 2) }}"
                                    stroke-linecap="round"/>
                            @endif
                            <text x="21" y="25" text-anchor="middle"
                                  font-size="9" font-weight="600" fill="#566a7f">{{ $pctVal }}%</text>
                        </svg>
                    </div>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-center">
                    <span class="badge bg-danger">{{ $row['restricoesBloq'] }}</span>/<span class="badge bg-secondary">{{ $row['restricoesNaoBloq'] }}</span>
                </td>
                <td class="text-center" style="min-width:110px">
                    @if ($row['totalItens'] > 0)
                        <div class="progress" style="height:6px">
                            <div class="progress-bar {{ $row['pronta'] ? 'bg-success' : 'bg-warning' }}" style="width:{{ $pct }}%"></div>
                        </div>
                        <small class="text-muted">{{ $row['itensOk'] }}/{{ $row['totalItens'] }}</small>
                    @else
                        <span class="text-muted small">—</span>
                    @endif
                </td>
                <td class="text-center">
                    <span class="badge {{ $row['pronta'] ? 'bg-success' : 'bg-warning text-dark' }}">
                        {{ $row['pronta'] ? 'Pronta' : 'Não pronta' }}
                    </span>
                </td>
                <td class="text-center text-nowrap">
                    @can('update', $at)
                    <button class="btn btn-xs btn-outline-secondary py-0 px-1" title="Mover para cima"
                            wire:click="moverAtividadeCima('{{ $at->id }}')"
                            @disabled($linha['posicaoNoGrupo'] === 0)>&uarr;</button>
                    <button class="btn btn-xs btn-outline-secondary py-0 px-1" title="Mover para baixo"
                            wire:click="moverAtividadeBaixo('{{ $at->id }}')"
                            @disabled($linha['posicaoNoGrupo'] === $linha['totalNoGrupo'] - 1)>&darr;</button>
                    @endcan
                    @can('create', [Restricao::class, $obra->id])
                    <button class="btn btn-xs btn-outline-warning py-0 px-1" title="Nova restrição"
                            wire:click="abrirModalRestricao('{{ $at->id }}')">
                        <i class="bx bx-shield-alt-2"></i>
                    </button>
                    @endcan
                </td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>
</div>
@else
<div class="text-center py-5">
    <i class="bx bx-calendar-week display-3 text-muted"></i>
    <h5 class="fw-bold mt-3">Nenhuma atividade nesta janela</h5>
    <p class="text-muted">Ajuste o filtro de dias, a fonte de data (baseline/tendência) ou a busca.</p>
</div>
@endif


{{-- =========================================================================
     MODAL: NOVA ATIVIDADE MANUAL
     ========================================================================= --}}
@if ($modalAtividadeAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-plus me-2"></i>Nova Atividade</h5>
                <button type="button" class="btn-close" wire:click="$set('modalAtividadeAberto', false)"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nome da tarefa <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('nomeNovaAtividade') is-invalid @enderror"
                           wire:model="nomeNovaAtividade" placeholder="Ex: Concretagem do berço 3">
                    @error('nomeNovaAtividade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Disciplina</label>
                        <select class="form-select" wire:model="disciplinaIdNova">
                            <option value="">— Sem disciplina —</option>
                            @foreach ($this->disciplinas as $d)
                            <option value="{{ $d->id }}">{{ $d->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Frente de trabalho</label>
                        <select class="form-select" wire:model="frenteTrabalhoIdNova">
                            <option value="">— Sem frente de trabalho —</option>
                            @foreach ($this->frentesTrabalho as $f)
                            <option value="{{ $f->id }}">{{ $f->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Etapa</label>
                        <select class="form-select" wire:model="etapaIdNova">
                            <option value="">— Sem etapa —</option>
                            @foreach ($this->etapas as $et)
                            <option value="{{ $et->id }}">{{ $et->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Posição na hierarquia do cronograma
                            <i class="bx bx-info-circle" title="Escolha onde essa atividade entra na EAP, pra aparecer no lugar certo da árvore"></i>
                        </label>
                        <select class="form-select" wire:model="pacoteTrabalhoIdNova">
                            <option value="">— Sem pacote (fica solta no topo) —</option>
                            @foreach ($this->pacotesParaSelecao as $p)
                            <option value="{{ $p['id'] }}">{{ $p['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Início <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('inicioNovaAtividade') is-invalid @enderror"
                               wire:model="inicioNovaAtividade">
                        @error('inicioNovaAtividade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Término <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('terminoNovaAtividade') is-invalid @enderror"
                               wire:model="terminoNovaAtividade">
                        @error('terminoNovaAtividade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Responsável</label>
                    <select class="form-select" wire:model="responsavelIdNovaAtividade">
                        <option value="">— Sem responsável —</option>
                        @foreach ($this->usuariosDaObra as $u)
                        <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$set('modalAtividadeAberto', false)">Cancelar</button>
                <button type="button" class="btn btn-primary" wire:click="salvarAtividade" wire:loading.attr="disabled">
                    Criar Atividade
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: CRIAR RESTRIÇÃO
     ========================================================================= --}}
@if ($modalRestricaoAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-shield-alt-2 me-2"></i>Nova Restrição</h5>
                <button type="button" class="btn-close" wire:click="$set('modalRestricaoAberto', false)"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Descrição <span class="text-danger">*</span></label>
                    <textarea class="form-control @error('descricaoNova') is-invalid @enderror"
                              rows="3" wire:model="descricaoNova"
                              placeholder="Descreva o impedimento com clareza..."></textarea>
                    @error('descricaoNova')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Tipo de Restrição</label>
                        <select class="form-select" wire:model="categoriaIdNova">
                            <option value="">— Sem tipo —</option>
                            @foreach ($this->categorias as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Prazo limite para resolução</label>
                        <input type="date" class="form-control" wire:model="prazolimiteNova">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Responsável por resolver</label>
                    <div class="mb-2">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="lhRespInterno" wire:model.live="responsavelExterno" value="0">
                            <label class="form-check-label" for="lhRespInterno">Usuário interno</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="lhRespExterno" wire:model.live="responsavelExterno" value="1">
                            <label class="form-check-label" for="lhRespExterno">Externo</label>
                        </div>
                    </div>
                    @if (! $responsavelExterno)
                    <select class="form-select" wire:model="responsavelIdNova">
                        <option value="">— Sem responsável —</option>
                        @foreach ($this->usuariosDaObra as $u)
                        <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                        @endforeach
                    </select>
                    @else
                    <input type="text" class="form-control" wire:model="responsavelExternoNova" placeholder="Nome da empresa ou pessoa externa...">
                    @endif
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Probabilidade (0–10)</label>
                        <input type="number" class="form-control" min="0" max="10" wire:model="probabilidadeNova">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Impacto (0–10)</label>
                        <input type="number" class="form-control" min="0" max="10" wire:model="impactoNova">
                    </div>
                </div>

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="lhBloqueante" wire:model="blocanteNova">
                    <label class="form-check-label" for="lhBloqueante">
                        <strong>Restrição bloqueante</strong>
                        <small class="text-muted d-block">Impede que a atividade seja comprometida no Plano Semanal</small>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$set('modalRestricaoAberto', false)">Cancelar</button>
                <button type="button" class="btn btn-primary" wire:click="salvarRestricao" wire:loading.attr="disabled">
                    Registrar Restrição
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: DAR BAIXA NA RESTRIÇÃO
     ========================================================================= --}}
@if ($baixandoRestricaoId)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-check-circle me-2 text-success"></i>Dar Baixa na Restrição</h5>
                <button type="button" class="btn-close" wire:click="$set('baixandoRestricaoId', null)"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Data da baixa <span class="text-danger">*</span></label>
                    <input type="date" class="form-control @error('dataBaixaNova') is-invalid @enderror"
                           wire:model="dataBaixaNova">
                    @error('dataBaixaNova')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">Observação (opcional)</label>
                    <textarea class="form-control" rows="3" wire:model="textoBaixaNova"
                              placeholder="Explique como a restrição foi resolvida, se relevante..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$set('baixandoRestricaoId', null)">Cancelar</button>
                <button type="button" class="btn btn-success" wire:click="darBaixaRestricao" wire:loading.attr="disabled">
                    Confirmar Baixa
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: DETALHE / MATRIZ DE PRONTIDÃO
     ========================================================================= --}}
@if ($modalAtividadeId)
@php $detalhe = $this->atividadeDetalhe; @endphp
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            @if (! $detalhe)
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
            </div>
            @else
            @php
                $at        = $detalhe['atividade'];
                $checklist = $detalhe['checklist'];
                $okChk     = collect($checklist)->where('concluido', true)->count();
                $totalChk  = collect($checklist)->count();
                $temBloq   = $at->restricoes->filter(fn ($r) =>
                    in_array($r->status->value ?? $r->status, ['aberta', 'em_tratamento', 'aguardando_terceiros']) && $r->bloqueante
                )->count() > 0;
                $atividadePronta = ! $temBloq && ($totalChk === 0 || $okChk >= $totalChk);
            @endphp
            <div class="modal-header bg-dark text-white">
                <div class="flex-grow-1 mb-2">
                    <h5 class="modal-title mb-2 text-white">{{ $at->nome }}</h5>
                    <small class="opacity-75">
                        <i class="bx bx-folder me-1"></i>{{ $at->frenteTrabalho?->nome ?? 'Sem frente de trabalho' }}
                        · {{ $at->disciplina?->nome ?? 'Sem disciplina' }}
                        @if ($at->caminho_critico)
                        <span class="badge bg-danger ms-2">Caminho Crítico</span>
                        @endif
                        <span class="badge {{ $atividadePronta ? 'bg-success' : 'bg-warning text-dark' }} ms-2">
                            {{ $atividadePronta ? 'Pronta' : 'Não pronta' }}
                        </span>
                    </small>
                </div>
                <button type="button" class="btn-close btn-close-white" wire:click="$set('modalAtividadeId', null)"></button>
            </div>

            <div class="px-4 py-3 bg-light border-bottom">
                <div class="row g-3 text-center">
                    <div class="col-3">
                        <div class="small text-muted">Início (Linha de Base)</div>
                        <h4 class="fw-semibold">{{ $at->baseline_inicio?->format('d/m/Y') ?? '—' }}</h4>
                    </div>
                    <div class="col-3">
                        <div class="small text-muted">Término (Linha de Base)</div>
                        <h4 class="fw-semibold">{{ $at->baseline_termino?->format('d/m/Y') ?? '—' }}</h4>
                    </div>
                    <div class="col-3">
                        <div class="small text-muted">Início (Tendência)</div>
                        <h4 class="fw-semibold">{{ $at->inicio_planejado?->format('d/m/Y') ?? '—' }}</h4>
                    </div>
                    <div class="col-3">
                        <div class="small text-muted">Término (Tendência)</div>
                        <h4 class="fw-semibold">{{ $at->data_termino?->format('d/m/Y') ?? '—' }}</h4>
                    </div>
                </div>
            </div>


            <div class="px-4 py-3 bg-light border-bottom">
              <div class="row g-3">
                <div class="col-sm-6 col-lg-3 mb-2">
                  <div class="card card-border-shadow-primary h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2 pb-1">
                        <h4 class="ms-1 mb-0">42</h4>
                      </div>
                      <p class="mb-1">On route vehicles</p>
                      <p class="mb-0">
                        <span class="fw-medium me-1">+18.2%</span>
                        <small class="text-muted">than last week</small>
                      </p>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-2">
                  <div class="card card-border-shadow-warning h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2 pb-1">
                        <div class="avatar me-2">
                          <span class="avatar-initial rounded bg-label-warning"><i class="bx bx-error"></i></span>
                        </div>
                        <h4 class="ms-1 mb-0">8</h4>
                      </div>
                      <p class="mb-1">Vehicles with errors</p>
                      <p class="mb-0">
                        <span class="fw-medium me-1">-8.7%</span>
                        <small class="text-muted">than last week</small>
                      </p>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-2">
                  <div class="card card-border-shadow-danger h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2 pb-1">
                        <div class="avatar me-2">
                          <span class="avatar-initial rounded bg-label-danger"
                            ><i class="bx bx-git-repo-forked"></i
                          ></span>
                        </div>
                        <h4 class="ms-1 mb-0">27</h4>
                      </div>
                      <p class="mb-1">Deviated from route</p>
                      <p class="mb-0">
                        <span class="fw-medium me-1">+4.3%</span>
                        <small class="text-muted">than last week</small>
                      </p>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-2">
                  <div class="card card-border-shadow-info h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2 pb-1">
                        <div class="avatar me-2">
                          <span class="avatar-initial rounded bg-label-info"><i class="bx bx-time-five"></i></span>
                        </div>
                        <h4 class="ms-1 mb-0">13</h4>
                      </div>
                      <p class="mb-1">Late vehicles</p>
                      <p class="mb-0">
                        <span class="fw-medium me-1">-2.5%</span>
                        <small class="text-muted">than last week</small>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-body p-0">
                <div class="row g-0">
                    <div class="{{ $checklist->isNotEmpty() ? 'col-md-8 border-end' : 'col-12' }}">
                        <div class="p-4">
                            <h6 class="fw-bold mb-3 d-flex align-items-center justify-content-between">
                                <span><i class="bx bx-block me-2 text-danger"></i>Restrições</span>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-secondary">{{ $at->restricoes->count() }}</span>
                                    @can('create', [Restricao::class, $obra->id])
                                    <button class="btn btn-xs btn-outline-warning py-0 px-2"
                                            wire:click="$set('modalAtividadeId', null)"
                                            x-init
                                            @click.stop="$nextTick(() => $wire.abrirModalRestricao('{{ $at->id }}'))">
                                        <i class="bx bx-plus me-1"></i>Nova
                                    </button>
                                    @endcan
                                </div>
                            </h6>

                            @if ($at->restricoes->isEmpty())
                            <div class="text-center text-muted py-4">
                                <i class="bx bx-check-circle fs-2 text-success d-block mb-2"></i>
                                Nenhuma restrição nesta atividade.
                            </div>
                            @else
                            @foreach ($at->restricoes as $r)
                            @php
                                $rsv     = $r->status instanceof \App\Enums\StatusRestricao ? $r->status->value : $r->status;
                                $raberta = in_array($rsv, ['aberta', 'em_tratamento', 'aguardando_terceiros']);
                                $rlabel  = match ($rsv) {
                                    'aberta' => 'Aberta', 'em_tratamento' => 'Em Tratamento',
                                    'aguardando_terceiros' => 'Ag. Terceiros', 'resolvida' => 'Resolvida',
                                    default => $rsv,
                                };
                                $rcor = match ($rsv) {
                                    'aberta' => 'danger', 'em_tratamento' => 'warning',
                                    'aguardando_terceiros' => 'info', 'resolvida' => 'success',
                                    default => 'secondary',
                                };
                                $rvencida = $r->prazo_limite && $raberta && $r->prazo_limite->isPast();
                            @endphp
                            <div class="card {{ $r->bloqueante && $raberta ? 'border-danger' : 'border-light' }} mb-3 shadow-none">
                                <div class="card-body py-2 px-3">
                                    <div class="d-flex align-items-start gap-2 mb-1">
                                        <div class="flex-grow-1 me-2">
                                            <span style="font-size:.875rem">{{ $r->descricao }}</span>
                                            @if ($r->categoria)
                                            <span class="badge bg-label-secondary ms-1" style="font-size:.7rem">{{ $r->categoria->nome }}</span>
                                            @endif
                                        </div>
                                        <span class="badge bg-{{ $rcor }} flex-shrink-0">{{ $rlabel }}</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div>
                                            @if ($r->responsavel)
                                            <small class="text-muted d-block"><i class="bx bx-user me-1"></i>{{ $r->responsavel->first_name }} {{ $r->responsavel->last_name }}</small>
                                            @elseif ($r->responsavel_externo)
                                            <small class="text-muted d-block"><i class="bx bx-user me-1"></i>{{ $r->responsavel_externo }}</small>
                                            @endif
                                            @if ($r->prazo_limite)
                                            <small class="{{ $rvencida ? 'text-danger fw-semibold' : 'text-muted' }}">
                                                <i class="bx bx-calendar-exclamation me-1"></i>Prazo: {{ $r->prazo_limite->format('d/m/Y') }}
                                                @if ($rvencida) (vencido) @endif
                                            </small>
                                            @endif
                                        </div>
                                        @if ($raberta)
                                        @can('resolver', $r)
                                        <button class="btn btn-xs btn-outline-success py-0 px-2"
                                                wire:click="$set('modalAtividadeId', null)"
                                                x-init
                                                @click.stop="$nextTick(() => $wire.abrirModalBaixa('{{ $r->id }}'))">
                                            <i class="bx bx-check me-1"></i>Dar baixa
                                        </button>
                                        @endcan
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @endforeach
                            @endif
                        </div>
                    </div>

                    @if ($checklist->isNotEmpty())
                    <div class="col-md-4">
                        <div class="p-4">
                            <h6 class="fw-bold mb-3">
                                <i class="bx bx-check-square me-2 text-success"></i>Prontidão
                                <span class="badge {{ $okChk === $totalChk ? 'bg-success' : 'bg-warning text-dark' }} ms-1">{{ $okChk }}/{{ $totalChk }}</span>
                            </h6>
                            <div class="progress mb-3" style="height:6px">
                                <div class="progress-bar {{ $okChk === $totalChk ? 'bg-success' : 'bg-warning' }}" style="width:{{ $totalChk > 0 ? round($okChk / $totalChk * 100) : 0 }}%"></div>
                            </div>
                            @foreach ($checklist as $itemChk)
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox"
                                       id="lhchk_{{ $at->id }}_{{ $itemChk['id'] }}"
                                       @checked($itemChk['concluido'])
                                       wire:click="marcarItemNaDetalhe('{{ $at->id }}','{{ $itemChk['id'] }}',{{ $itemChk['concluido'] ? 'false' : 'true' }})">
                                <label class="form-check-label {{ $itemChk['concluido'] ? 'text-decoration-line-through text-muted' : '' }}"
                                       for="lhchk_{{ $at->id }}_{{ $itemChk['id'] }}">
                                    {{ $itemChk['nome'] }}
                                </label>
                                @if ($itemChk['concluido'] && $itemChk['concluido_por'])
                                <small class="text-muted d-block">
                                    {{ $itemChk['concluido_por']->first_name }} — {{ $itemChk['concluido_em']?->format('d/m/Y H:i') }}
                                </small>
                                @endif
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>

                <div class="border-top p-4">
                    <h6 class="fw-bold mb-3">
                        <i class="bx bx-comment-detail me-2 text-info"></i>Comentários
                        <span class="badge bg-secondary">{{ $at->comentarios->count() }}</span>
                    </h6>

                    @if ($at->comentarios->isEmpty())
                    <p class="text-muted small mb-3">Nenhum comentário ainda.</p>
                    @else
                    <div class="mb-3" style="max-height:220px; overflow-y:auto">
                        @foreach ($at->comentarios as $com)
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

                    @can('comentar', $at)
                    <div class="d-flex gap-2">
                        <textarea class="form-control form-control-sm @error('comentarioNovoAtividade') is-invalid @enderror"
                                  rows="2" wire:model="comentarioNovoAtividade"
                                  placeholder="Escreva uma observação sobre esta atividade..."></textarea>
                        <button class="btn btn-sm btn-primary flex-shrink-0" style="height:fit-content"
                                wire:click="adicionarComentarioAtividade('{{ $at->id }}')" wire:loading.attr="disabled">
                            <i class="bx bx-send"></i>
                        </button>
                    </div>
                    @error('comentarioNovoAtividade')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @endcan
                </div>
            </div>

            <div class="modal-footer">
                <small class="text-muted me-auto">
                    {{ $atividadePronta ? '✅ Pode ser comprometida no Plano Semanal' : '⚠ Pendências impedem o comprometimento' }}
                </small>
                <button class="btn btn-secondary" wire:click="$set('modalAtividadeId', null)">Fechar</button>
            </div>
            @endif
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: CONFIRMAR GERAR PLANO SEMANAL
     ========================================================================= --}}
@if ($modalGerarPlanoAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-calendar-check me-2 text-success"></i>Gerar Plano Semanal</h5>
                <button type="button" class="btn-close" wire:click="$set('modalGerarPlanoAberto', false)"></button>
            </div>
            <div class="modal-body">
                <p>
                    <strong>{{ $this->qtdProntasParaComprometer }}</strong> atividade(s) pronta(s) dentro do filtro
                    atual serão comprometidas e passarão a aparecer no Plano Semanal.
                </p>
                <p class="text-muted small mb-0">Essa ação não pode ser desfeita por aqui — o desfazimento é manual, atividade por atividade.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$set('modalGerarPlanoAberto', false)">Cancelar</button>
                <button type="button" class="btn btn-success" wire:click="gerarPlanoSemanal" wire:loading.attr="disabled">
                    Confirmar
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- =========================================================================
     MODAL: CONFIGURAR IMPRESSÃO
     ========================================================================= --}}
@if ($modalImprimirAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-printer me-2"></i>Configurar Impressão</h5>
                <button type="button" class="btn-close" wire:click="$set('modalImprimirAberto', false)"></button>
            </div>
            <div class="modal-body">
                <label class="form-label d-block">Orientação da folha</label>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button"
                            class="btn {{ $orientacaoImpressao === 'portrait' ? 'btn-primary' : 'btn-outline-primary' }}"
                            wire:click="$set('orientacaoImpressao', 'portrait')">
                        <i class="bx bx-file me-1"></i>Retrato
                    </button>
                    <button type="button"
                            class="btn {{ $orientacaoImpressao === 'landscape' ? 'btn-primary' : 'btn-outline-primary' }}"
                            wire:click="$set('orientacaoImpressao', 'landscape')">
                        <i class="bx bx-file-blank me-1" style="display:inline-block;transform:rotate(90deg)"></i>Paisagem
                    </button>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    A lista mantém a hierarquia atual (pacotes/atividades) e os filtros aplicados na tela.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$set('modalImprimirAberto', false)">Cancelar</button>
                <button type="button" class="btn btn-primary" wire:click="imprimirPdf" wire:loading.attr="disabled">
                    <i class="bx bxs-file-pdf me-1"></i>Gerar PDF
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- =========================================================================
     CANVA LATERAL DE FILTROS — desliza sobre o conteúdo a partir da borda
     direita, mesmo mecanismo do canva de Filtros do Quadro de Restrições
     (resources/views/pages/radar/⚡restricoes.blade.php): painel fixo com
     transform:right, aba presa na borda esquerda do próprio painel pra
     abrir. Fechado por padrão: é uma sobreposição, não divide espaço com
     a tabela.
     ========================================================================= --}}
<div class="canva-filtros-lookahead" :class="filtrosAbertos ? 'canva-filtros-lookahead-aberto' : ''" x-data="{ filtrosAbertos: false }">
    <button type="button" class="canva-filtros-lookahead-aba" @click="filtrosAbertos = true" title="Filtros">
        <i class="bx bx-filter-alt"></i>
    </button>

    <div class="canva-filtros-lookahead-header d-flex align-items-center justify-content-between border-bottom px-4 py-3">
        <h6 class="mb-0 fw-semibold"><i class="bx bx-filter-alt me-1"></i>Filtros</h6>
        <a href="javascript:void(0)" class="text-body" @click="filtrosAbertos = false">
            <i class="bx bx-x fs-4"></i>
        </a>
    </div>

    <div class="canva-filtros-lookahead-body px-4 py-3">
        <div class="row g-2">
            <div class="col-12">
                <small class="text-muted d-block mb-1">Janela:</small>
                <div class="btn-group btn-group-sm w-100" role="group">
                    @foreach ([30, 60, 90, 0] as $dias)
                    <button type="button"
                            class="btn {{ $janelaDias === $dias ? 'btn-primary' : 'btn-outline-primary' }}"
                            wire:click="$set('janelaDias', {{ $dias }})">
                        {{ $dias > 0 ? "{$dias}d" : 'Todo' }}
                    </button>
                    @endforeach
                </div>
            </div>
            <div class="col-12">
                <small class="text-muted d-block mb-1">Fonte de dados:</small>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <button type="button"
                            class="btn {{ $fonteData === 'baseline' ? 'btn-dark' : 'btn-outline-dark' }}"
                            wire:click="$set('fonteData', 'baseline')">
                        Linha de Base
                    </button>
                    <button type="button"
                            class="btn {{ $fonteData === 'tendencia' ? 'btn-dark' : 'btn-outline-dark' }}"
                            wire:click="$set('fonteData', 'tendencia')">
                        Tendência
                    </button>
                </div>
            </div>
            <div class="col-12">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bx bx-search"></i></span>
                    <input type="text" class="form-control" placeholder="Buscar tarefa..."
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="etapaIdFiltro">
                    <option value="">Todas as etapas</option>
                    @foreach ($this->etapas as $et)
                    <option value="{{ $et->id }}">{{ $et->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="frenteTrabalhoIdFiltro">
                    <option value="">Todas as frentes de trabalho</option>
                    @foreach ($this->frentesTrabalho as $f)
                    <option value="{{ $f->id }}">{{ $f->nome }}</option>
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
                    @foreach ($this->entregaveis as $en)
                    <option value="{{ $en->id }}">{{ $en->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="equipeResponsavelIdFiltro">
                    <option value="">Todas as equipes/responsáveis</option>
                    @foreach ($this->equipesResponsaveis as $eq)
                    <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="personalizado1IdFiltro">
                    <option value="">Personalizado 1: Todos</option>
                    @foreach ($this->personalizados1 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="personalizado2IdFiltro">
                    <option value="">Personalizado 2: Todos</option>
                    @foreach ($this->personalizados2 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="personalizado3IdFiltro">
                    <option value="">Personalizado 3: Todos</option>
                    @foreach ($this->personalizados3 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="personalizado4IdFiltro">
                    <option value="">Personalizado 4: Todos</option>
                    @foreach ($this->personalizados4 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="personalizado5IdFiltro">
                    <option value="">Personalizado 5: Todos</option>
                    @foreach ($this->personalizados5 as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="tendenciaImportacaoId">
                    <option value="">Tendência: mais recente</option>
                    @foreach ($this->importacoesDisponiveis as $imp)
                    <option value="{{ $imp->id }}">
                        Tendência: {{ $imp->importado_em->format('d/m/Y H:i') }} — {{ $imp->arquivo }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <select class="form-select form-select-sm" wire:model.live="linhaBaseId">
                    <option value="">Linha de Base: ao vivo</option>
                    @foreach ($this->linhasBase as $lb)
                    <option value="{{ $lb->id }}">
                        Linha de Base: {{ $lb->nome }} ({{ $lb->importacao?->importado_em?->format('d/m/Y') }})
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <div class="alert alert-light border py-2 mb-0 small">
                    <i class="bx bx-info-circle me-1"></i>
                    <strong>Tendência:</strong>
                    {{ $this->importacaoTendenciaAtual ? $this->importacaoTendenciaAtual->importado_em->format('d/m/Y H:i') . ' — ' . $this->importacaoTendenciaAtual->arquivo : 'sem importação registrada' }}
                    <br>
                    <strong>Linha de Base:</strong>
                    {{ $this->linhaBaseSelecionada ? $this->linhaBaseSelecionada->nome . ' (' . $this->linhaBaseSelecionada->importacao?->importado_em?->format('d/m/Y') . ')' : 'última importação (ao vivo)' }}
                </div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="togOcultarConcluidas"
                           wire:model.live="ocultarConcluidas">
                    <label class="form-check-label small" for="togOcultarConcluidas">Ocultar concluídas</label>
                </div>
            </div>
            <div class="col-12"><hr class="my-1"></div>
            @if ($this->atividades->count() > 0)
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
            <div class="col-12">
                <button class="btn btn-outline-secondary btn-sm w-100" wire:click="abrirModalImprimir">
                    <i class="bx bx-printer me-1"></i>Imprimir
                </button>
            </div>
            @endif
            @if (\Illuminate\Support\Facades\Auth::user()->temPermissaoNaObra($obra->id, 'restricoes.lookahead', 'editar'))
            <div class="col-12">
                <button class="btn btn-success btn-sm w-100" wire:click="abrirConfirmacaoGerarPlano">
                    <i class="bx bx-calendar-check me-1"></i>Gerar Plano Semanal
                </button>
            </div>
            @endif
            @can('create', [Atividade::class, $obra->id])
            <div class="col-12">
                <button class="btn btn-primary btn-sm w-100" wire:click="abrirModalNovaAtividade">
                    <i class="bx bx-plus me-1"></i>Nova Atividade
                </button>
            </div>
            @endcan
        </div>
    </div>

    {{-- z-index:1080 fica ACIMA da navbar fixa do template (.layout-navbar,
         z-index:1075) e ABAIXO dos modais do Bootstrap (z-index:1090) — ver
         explicação completa no mesmo bloco em ⚡restricoes.blade.php. --}}
    <style>
    .canva-filtros-lookahead {
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

    .canva-filtros-lookahead.canva-filtros-lookahead-aberto {
        right: 0;
    }

    .canva-filtros-lookahead-body {
        flex: 1 1 auto;
        overflow-y: auto;
    }

    .canva-filtros-lookahead-aba {
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

    .canva-filtros-lookahead.canva-filtros-lookahead-aberto .canva-filtros-lookahead-aba {
        opacity: 0;
        pointer-events: none;
    }

    @media (max-width: 575.98px) {
        .canva-filtros-lookahead {
            width: 300px;
            right: -300px;
        }

        .canva-filtros-lookahead.canva-filtros-lookahead-aberto {
            right: 0;
        }
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
