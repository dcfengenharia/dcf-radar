<?php

use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Exports\LinhaBaseAtividadesExport;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\CategoriaRestricao;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\Etapa;
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
use Livewire\Attributes\Computed;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
  use ExecutaComTransacaoSegura;

  public Work $obra;

  // Modal criar linha de base
  public bool $modalCriar = false;
  public ?string $importacaoSelecionada = null;
  public string $nome = '';
  public string $descricao = '';

  // Expansão de atividades por linha de base
  public ?string $linhaExpandida = null;

  // Filtros da lista de atividades (só fazem efeito com uma linha expandida)
  public string $buscaAtividade = '';
  public ?string $disciplinaIdFiltro = null;
  public ?string $etapaIdFiltro = null;
  public ?string $faturamentoDiretoFiltro = null; // '' = todos | '1' = sim | '0' = não
  public ?string $entregavelIdFiltro = null;
  public ?string $equipeResponsavelIdFiltro = null;
  public ?string $personalizado1IdFiltro = null;
  public ?string $personalizado2IdFiltro = null;
  public ?string $personalizado3IdFiltro = null;
  public ?string $personalizado4IdFiltro = null;
  public ?string $personalizado5IdFiltro = null;
  public string $caminhoCriticoFiltro = '';
  public string $temRestricoesFiltro = '';

  // Modal de edição da atividade (nome, disciplina, caminho crítico)
  public ?string $atividadeEditandoId = null;
  public string $editNome = '';
  public ?string $editDisciplinaId = null;
  public bool $editCaminhoCritico = false;

  // Formulário de nova restrição inline (campos completos)
  public ?string $atividadeParaRestricao = null;
  public string $restricaoDescricao = '';
  public string $restricaoPrazo = '';
  public bool $restricaoBloqueante = true;
  public ?string $restricaoCategoriaId = null;
  public ?string $restricaoResponsavelId = null;
  public string $restricaoResponsavelExt = '';
  public bool $restricaoRespExterno = false;
  public ?int $restricaoProbabilidade = null;
  public ?int $restricaoImpacto = null;

  public function mount(Work $obra): void
  {
    $this->obra = $obra;
  }

  #[Computed]
  public function linhasBase(): \Illuminate\Support\Collection
  {
    return LinhaBase::where('obra_id', $this->obra->id)
      ->with(['importacao', 'criador:id,first_name,last_name'])
      ->latest()
      ->get();
  }

  #[Computed]
  public function importacoesDisponiveis(): \Illuminate\Support\Collection
  {
    return CronogramaImportacao::where('obra_id', $this->obra->id)
      ->doesntHave('linhaBase')
      // Uma importação puramente de Avanço não tem Previsto — não faz
      // sentido virar Linha de Base.
      ->whereIn('tipo', [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value])
      ->orderByDesc('importado_em')
      ->get(['id', 'data_status', 'importado_em', 'criadas', 'atualizadas', 'removidas']);
  }

  #[Computed]
  public function linhaBaseSelecionada(): ?LinhaBase
  {
    if (!$this->linhaExpandida) {
      return null;
    }

    return $this->linhasBase->firstWhere('id', $this->linhaExpandida);
  }

  /**
   * Compara dois códigos de EAP (ex: "5.1.10" vs "5.1.3") segmento a
   * segmento como números — mesmo helper usado no Lookahead
   * (⚡lookahead.blade.php), pra manter a mesma ordenação em todo o app.
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
   * Ordena atividades dentro do mesmo pacote: por ordem_manual quando
   * definida (mesmo campo usado pra reordenar no Lookahead); senão pelo
   * código do cronograma (posição original no MS Project — fiel à
   * sequência importada); só cai pra início planejado e nome quando não
   * há nem reordenação manual nem código (dado legado ou atividade
   * manual nunca importada).
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
   * Achata a EAP (pacotes + atividades) numa árvore expansível/recolhível,
   * mesmo padrão do Lookahead — as datas de linha de base de cada
   * atividade vêm do snapshot gravado na importação vinculada a esta
   * linha de base (histórico fiel), caindo pros campos ao vivo da
   * Atividade só se não houver snapshot (dado antigo).
   */
  #[Computed]
  public function arvoreAtividades(): array
  {
    $lb = $this->linhaBaseSelecionada;
    if (!$lb) {
      return [];
    }

    $atividades = Atividade::where('obra_id', $this->obra->id)
      ->where('fora_do_cronograma', false)
      ->when(
        $this->buscaAtividade !== '',
        fn($q) => $q->where(function ($q2) {
          $q2
            ->where('nome', 'like', "%{$this->buscaAtividade}%")
            ->orWhere('external_uid', 'like', "%{$this->buscaAtividade}%");
        })
      )
      ->when($this->disciplinaIdFiltro, fn($q) => $q->where('disciplina_id', $this->disciplinaIdFiltro))
      ->when($this->etapaIdFiltro, fn($q) => $q->where('etapa_id', $this->etapaIdFiltro))
      ->when(
        $this->faturamentoDiretoFiltro !== null && $this->faturamentoDiretoFiltro !== '',
        fn($q) => $q->where('faturamento_direto', $this->faturamentoDiretoFiltro === '1')
      )
      ->when($this->entregavelIdFiltro, fn($q) => $q->where('entregavel_id', $this->entregavelIdFiltro))
      ->when(
        $this->equipeResponsavelIdFiltro,
        fn($q) => $q->where('equipe_responsavel_id', $this->equipeResponsavelIdFiltro)
      )
      ->when($this->personalizado1IdFiltro, fn($q) => $q->where('personalizado_1_id', $this->personalizado1IdFiltro))
      ->when($this->personalizado2IdFiltro, fn($q) => $q->where('personalizado_2_id', $this->personalizado2IdFiltro))
      ->when($this->personalizado3IdFiltro, fn($q) => $q->where('personalizado_3_id', $this->personalizado3IdFiltro))
      ->when($this->personalizado4IdFiltro, fn($q) => $q->where('personalizado_4_id', $this->personalizado4IdFiltro))
      ->when($this->personalizado5IdFiltro, fn($q) => $q->where('personalizado_5_id', $this->personalizado5IdFiltro))
      ->when(
        $this->caminhoCriticoFiltro !== '',
        fn($q) => $q->where('caminho_critico', $this->caminhoCriticoFiltro === '1')
      )
      ->with('disciplina:id,nome')
      ->withCount('restricoes')
      ->when(
        $this->temRestricoesFiltro !== '',
        fn($q) => $q->having('restricoes_count', $this->temRestricoesFiltro === '1' ? '>' : '=', 0)
      )
      ->get();

    if ($atividades->isEmpty()) {
      return [];
    }

    $snapshots = AtividadeSnapshot::where('cronograma_importacao_id', $lb->cronograma_importacao_id)
      ->whereIn('atividade_id', $atividades->pluck('id'))
      ->get()
      ->keyBy('atividade_id');

    $linhas = $atividades->map(function ($at) use ($snapshots) {
      $snap = $snapshots->get($at->id);
      $inicio = $snap?->baseline_inicio ?? $at->baseline_inicio;
      $termino = $snap?->baseline_termino ?? $at->baseline_termino;
      $duracao = $inicio && $termino ? $inicio->diffInDays($termino) + 1 : null;

      return [
        'atividade' => $at,
        'inicioBaseline' => $inicio,
        'terminoBaseline' => $termino,
        'duracaoBaseline' => $duracao,
      ];
    });

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
    // flip() pra virar lookup O(1) (has()) — o percorrer() abaixo checa
    // pertinência uma vez por pacote candidato a cada nível da recursão,
    // então um contains() O(n) aqui vira o gargalo dominante em EAPs
    // grandes (centenas de pacotes).
    $idsRelevantes = $idsRelevantes->unique()->flip();

    $atividadesPorPacote = $linhas->groupBy(fn($row) => $row['atividade']->pacote_trabalho_id ?? 'sem_pacote');

    // Agrupa os filhos relevantes por parent_id UMA vez, em vez de
    // refiltrar toda a coleção de pacotes a cada chamada de percorrer()
    // (era O(pacotes²) — o gargalo real da árvore em obras grandes).
    $filhosPorPai = $todosPacotes->filter(fn($p) => $idsRelevantes->has($p->id))->groupBy('parent_id');

    $ordenarGrupo = function ($grupo) {
      return $grupo->sort(fn($a, $b) => $this->compararOrdemAtividade($a['atividade'], $b['atividade']))->values();
    };

    $resultado = [];

    $percorrer = function (string $pacoteId, array $ancestrais) use (
      &$percorrer,
      &$resultado,
      $todosPacotes,
      $filhosPorPai,
      $atividadesPorPacote,
      $ordenarGrupo
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

      $filhos = $filhosPorPai
        ->get($pacoteId, collect())
        ->sort(fn($a, $b) => $this->compararCodigos($a->codigo, $b->codigo));

      foreach ($filhos as $filho) {
        $percorrer($filho->id, $novosAncestrais);
      }

      $grupo = $ordenarGrupo($atividadesPorPacote->get($pacoteId, collect()));
      foreach ($grupo as $row) {
        $resultado[] = [
          'tipo' => 'atividade',
          'id' => $row['atividade']->id,
          'nivel' => count($novosAncestrais),
          'ancestrais' => $novosAncestrais,
          'row' => $row,
        ];
      }
    };

    // Nível raiz: intercala pacotes raiz E atividades sem pacote que
    // tenham código do cronograma (posição real no MS Project), numa
    // única sequência ordenada — em vez de jogar as órfãs sempre no
    // final, ignorando onde elas realmente ficam no cronograma (ex:
    // um marco de início solto, sem pacote pai).
    $raizes = $filhosPorPai->get(null, collect());

    $orfas = $atividadesPorPacote->get('sem_pacote', collect());
    $orfasComCodigo = $orfas->filter(fn($row) => $row['atividade']->codigo_cronograma !== null);
    $orfasSemCodigo = $orfas->filter(fn($row) => $row['atividade']->codigo_cronograma === null);

    $entradasRaiz = collect();
    foreach ($raizes as $pacote) {
      $entradasRaiz->push(['codigo' => $pacote->codigo, 'tipo' => 'pacote', 'payload' => $pacote]);
    }
    foreach ($orfasComCodigo as $row) {
      $entradasRaiz->push(['codigo' => $row['atividade']->codigo_cronograma, 'tipo' => 'atividade', 'payload' => $row]);
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
      ];
    }

    // Órfãs sem código do cronograma (dado legado pré-migração, ou
    // atividade manual sem pacote): mantém o comportamento de sempre,
    // no final, ordenadas por data/nome.
    $grupoOrfasSemCodigo = $ordenarGrupo($orfasSemCodigo);
    foreach ($grupoOrfasSemCodigo as $row) {
      $resultado[] = [
        'tipo' => 'atividade',
        'id' => $row['atividade']->id,
        'nivel' => 0,
        'ancestrais' => [],
        'row' => $row,
      ];
    }

    return $resultado;
  }

  #[Computed]
  public function disciplinas(): \Illuminate\Support\Collection
  {
    return Disciplina::orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function etapas(): \Illuminate\Support\Collection
  {
    return Etapa::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function entregaveis(): \Illuminate\Support\Collection
  {
    return Entregavel::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function equipesResponsaveis(): \Illuminate\Support\Collection
  {
    return EquipeResponsavel::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados1(): \Illuminate\Support\Collection
  {
    return Personalizado1::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados2(): \Illuminate\Support\Collection
  {
    return Personalizado2::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados3(): \Illuminate\Support\Collection
  {
    return Personalizado3::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados4(): \Illuminate\Support\Collection
  {
    return Personalizado4::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  #[Computed]
  public function personalizados5(): \Illuminate\Support\Collection
  {
    return Personalizado5::where('obra_id', $this->obra->id)
      ->orderBy('nome')
      ->get(['id', 'nome']);
  }

  /**
   * AtividadePolicy::update() só depende de $atividade->obra_id (sempre
   * a mesma obra nesta página) — chamar o mesmo check de
   * `temPermissaoNaObra()` que a Policy usa, uma vez aqui, em vez de
   * rodar `@can('update', $at)` (Gate::check completo) pra cada linha
   * da árvore evita milhares de resoluções de Gate redundantes em
   * obras grandes.
   */
  #[Computed]
  public function podeEditarAtividades(): bool
  {
    return auth()
      ->user()
      ->temPermissaoNaObra($this->obra->id, 'restricoes.lookahead', 'editar');
  }

  public function exportarAtividadesPdf()
  {
    $lb = $this->linhaBaseSelecionada;

    $pdf = Pdf::loadView('exports.linha-base-atividades-pdf', [
      'obra' => $this->obra,
      'lb' => $lb,
      'linhas' => $this->arvoreAtividades,
    ])->setPaper('a4', 'landscape');

    return response()->streamDownload(fn() => print $pdf->output(), "linha-base-{$lb->id}-atividades.pdf");
  }

  public function exportarAtividadesExcel()
  {
    $lb = $this->linhaBaseSelecionada;

    $linhas = collect($this->arvoreAtividades)
      ->where('tipo', 'atividade')
      ->pluck('row');

    return Excel::download(new LinhaBaseAtividadesExport($linhas), "linha-base-{$lb->id}-atividades.xlsx");
  }

  public function salvar(): void
  {
    $this->validate(
      [
        'nome' => 'required|string|max:120',
        'importacaoSelecionada' => 'required|exists:cronograma_importacoes,id',
      ],
      [
        'nome.required' => 'O nome da linha de base é obrigatório.',
        'importacaoSelecionada.required' => 'Selecione uma importação para vincular.',
        'importacaoSelecionada.exists' => 'Importação não encontrada.',
      ]
    );

    // Correção de segurança (Ciclo 17, pós-auditoria) — importacaoSelecionada
    // é propriedade pública Livewire, manipulável direto pelo cliente (ex.:
    // DevTools). A regra exists:cronograma_importacoes,id acima NUNCA bastou
    // sozinha: ela roda via Query Builder cru contra a tabela
    // (Illuminate\Validation\DatabasePresenceVerifier::getCount()), sem
    // aplicar NENHUM global scope — nem o de tenant (BelongsToTenant), nem
    // qualquer filtro de obra. Resolver o Model explicitamente aqui, escopado
    // por tenant (global scope automático de BelongsToTenant) E por obra_id
    // (explícito — o scope de tenant sozinho não bloqueia uma importação de
    // OUTRA obra do MESMO tenant), transforma o ID client-controlled num
    // Model já validado: nenhuma query daqui pra baixo volta a usar
    // $this->importacaoSelecionada, só $importacao->id.
    $importacao = CronogramaImportacao::where('obra_id', $this->obra->id)
      ->find($this->importacaoSelecionada);

    if (! $importacao) {
      $this->addError('importacaoSelecionada', 'Selecione uma importação válida desta obra.');
      return;
    }

    // Correção pós-QA (Ciclo 17) — checagem explícita ANTES de qualquer
    // escrita: uma importação só pode estar vinculada a UMA LinhaBase
    // ATIVA por obra (unique(obra_id, cronograma_importacao_id) é a
    // defesa final do banco, nunca a regra de negócio em si — o erro cru
    // de constraint nunca deve chegar ao usuário). Se já existe uma
    // LinhaBase ativa pra essa importação, orienta com o nome real dela,
    // sem sequer tentar o INSERT.
    $ativa = LinhaBase::where('obra_id', $this->obra->id)
      ->where('cronograma_importacao_id', $importacao->id)
      ->first();

    if ($ativa) {
      $this->addError('importacaoSelecionada', "Esta importação já está vinculada à linha de base \"{$ativa->nome}\". Escolha outra importação ou edite a linha de base existente.");
      return;
    }

    // A mesma importação pode ter uma LinhaBase anterior EXCLUÍDA (soft
    // delete) — o modelo é 1:1 por design (unique constraint acima), então
    // reutilizar essa importação restaura o registro antigo em vez de
    // tentar criar uma segunda row (que o banco rejeitaria de qualquer
    // forma, mesmo com a checagem acima, por causa da linha em lixeira).
    // Histórico (id, criado_em original) é preservado; nome/descrição/
    // criado_por são atualizados com o que foi informado agora.
    $excluida = LinhaBase::onlyTrashed()
      ->where('obra_id', $this->obra->id)
      ->where('cronograma_importacao_id', $importacao->id)
      ->first();

    $this->transacaoSegura(function () use ($excluida, $importacao) {
      if ($excluida) {
        $excluida->restore();
        $excluida->update([
          'nome' => $this->nome,
          'descricao' => $this->descricao ?: null,
          'criado_por' => auth()->id(),
        ]);

        return $excluida;
      }

      return LinhaBase::create([
        'obra_id' => $this->obra->id,
        'nome' => $this->nome,
        'descricao' => $this->descricao ?: null,
        'cronograma_importacao_id' => $importacao->id,
        'criado_por' => auth()->id(),
      ]);
    }, 'Não foi possível salvar a linha de base devido a um erro interno. Nenhum dado foi alterado. Tente novamente; se o problema continuar, procure o administrador.');

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetCriar();
    unset($this->linhasBase, $this->importacoesDisponiveis);
    $this->dispatch('show-toast', message: 'Linha de base salva com sucesso.');
  }

  public function excluir(string $id): void
  {
    $lb = LinhaBase::findOrFail($id);

    $this->transacaoSegura(fn() => $lb->delete());

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    unset($this->linhasBase, $this->importacoesDisponiveis);
    if ($this->linhaExpandida === $id) {
      $this->linhaExpandida = null;
    }
    $this->dispatch('show-toast', message: 'Linha de base removida.');
  }

  public function expandir(string $id): void
  {
    $this->linhaExpandida = $this->linhaExpandida === $id ? null : $id;
    $this->limparFiltrosAtividades();
    unset($this->arvoreAtividades, $this->linhaBaseSelecionada);
  }

  public function temFiltrosAtividadesAtivos(): bool
  {
    return $this->buscaAtividade !== '' ||
      $this->disciplinaIdFiltro !== null ||
      $this->etapaIdFiltro !== null ||
      ($this->faturamentoDiretoFiltro !== null && $this->faturamentoDiretoFiltro !== '') ||
      $this->entregavelIdFiltro !== null ||
      $this->equipeResponsavelIdFiltro !== null ||
      $this->personalizado1IdFiltro !== null ||
      $this->personalizado2IdFiltro !== null ||
      $this->personalizado3IdFiltro !== null ||
      $this->personalizado4IdFiltro !== null ||
      $this->personalizado5IdFiltro !== null ||
      $this->caminhoCriticoFiltro !== '' ||
      $this->temRestricoesFiltro !== '';
  }

  public function limparFiltrosAtividades(): void
  {
    $this->buscaAtividade = '';
    $this->disciplinaIdFiltro = null;
    $this->etapaIdFiltro = null;
    $this->faturamentoDiretoFiltro = null;
    $this->entregavelIdFiltro = null;
    $this->equipeResponsavelIdFiltro = null;
    $this->personalizado1IdFiltro = null;
    $this->personalizado2IdFiltro = null;
    $this->personalizado3IdFiltro = null;
    $this->personalizado4IdFiltro = null;
    $this->personalizado5IdFiltro = null;
    $this->caminhoCriticoFiltro = '';
    $this->temRestricoesFiltro = '';
  }

  // =========================================================================
  // EDITAR ATIVIDADE (nome, disciplina, caminho crítico)
  // =========================================================================

  public function abrirEdicaoAtividade(string $atividadeId): void
  {
    $atividade = Atividade::findOrFail($atividadeId);
    $this->authorize('update', $atividade);

    $this->atividadeEditandoId = $atividadeId;
    $this->editNome = $atividade->nome;
    $this->editDisciplinaId = $atividade->disciplina_id;
    $this->editCaminhoCritico = $atividade->caminho_critico;
    $this->resetErrorBag();
  }

  public function cancelarEdicaoAtividade(): void
  {
    $this->atividadeEditandoId = null;
  }

  public function salvarEdicaoAtividade(): void
  {
    $atividade = Atividade::findOrFail($this->atividadeEditandoId);
    $this->authorize('update', $atividade);

    $this->validate(
      [
        'editNome' => 'required|string|min:3|max:255',
        'editDisciplinaId' => 'nullable|exists:disciplinas,id',
      ],
      [],
      ['editNome' => 'nome']
    );

    $this->transacaoSegura(
      fn() => $atividade->update([
        'nome' => $this->editNome,
        'disciplina_id' => $this->editDisciplinaId ?: null,
        'caminho_critico' => $this->editCaminhoCritico,
      ])
    );

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->atividadeEditandoId = null;
    unset($this->arvoreAtividades);
    $this->dispatch('show-toast', message: 'Atividade atualizada.');
  }

  #[Computed]
  public function categorias(): \Illuminate\Support\Collection
  {
    return CategoriaRestricao::orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function usuariosDaObra(): \Illuminate\Support\Collection
  {
    return $this->obra
      ->users()
      ->orderBy('first_name')
      ->get(['users.id', 'users.first_name', 'users.last_name']);
  }

  public function abrirNovaRestricao(string $atividadeId): void
  {
    $this->atividadeParaRestricao = $atividadeId;
    $this->restricaoDescricao = '';
    $this->restricaoPrazo = '';
    $this->restricaoBloqueante = true;
    $this->restricaoCategoriaId = null;
    $this->restricaoResponsavelId = null;
    $this->restricaoResponsavelExt = '';
    $this->restricaoRespExterno = false;
    $this->restricaoProbabilidade = null;
    $this->restricaoImpacto = null;
    $this->resetValidation();
  }

  public function salvarRestricao(): void
  {
    $this->validate(
      [
        'restricaoDescricao' => 'required|string|min:5',
        'restricaoPrazo' => 'required|date',
        'restricaoCategoriaId' => 'nullable|exists:categorias_restricao,id',
        'restricaoResponsavelId' => 'nullable|exists:users,id',
        'restricaoProbabilidade' => 'nullable|integer|min:0|max:10',
        'restricaoImpacto' => 'nullable|integer|min:0|max:10',
      ],
      [
        'restricaoDescricao.required' => 'Descreva a restrição.',
        'restricaoPrazo.required' => 'Informe o prazo limite.',
      ]
    );

    $this->transacaoSegura(
      fn() => Restricao::create([
        'atividade_id' => $this->atividadeParaRestricao,
        'descricao' => $this->restricaoDescricao,
        'prazo_limite' => $this->restricaoPrazo,
        'bloqueante' => $this->restricaoBloqueante,
        'categoria_id' => $this->restricaoCategoriaId ?: null,
        'responsavel_id' => !$this->restricaoRespExterno ? ($this->restricaoResponsavelId ?: null) : null,
        'responsavel_externo' => $this->restricaoRespExterno ? ($this->restricaoResponsavelExt ?: null) : null,
        'probabilidade' => $this->restricaoProbabilidade,
        'impacto' => $this->restricaoImpacto,
        'status' => StatusRestricao::Aberta->value,
        'aberta_em' => now(),
        'created_by_id' => auth()->id(),
      ])
    );

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->atividadeParaRestricao = null;
    unset($this->atividadesDaLinha);
    $this->dispatch('show-toast', message: 'Restrição criada com sucesso.');
  }

  public function cancelarRestricao(): void
  {
    $this->atividadeParaRestricao = null;
  }

  private function resetCriar(): void
  {
    $this->modalCriar = false;
    $this->importacaoSelecionada = null;
    $this->nome = '';
    $this->descricao = '';
  }
};
?>

<div>

{{-- Overlay de loading — cobre expandir/filtrar/exportar (qualquer
     wire:click ou wire:model.live desta página), pra não parecer que o
     sistema travou enquanto a árvore de atividades é recalculada. --}}
<div wire:loading.flex wire:target="expandir,buscaAtividade,disciplinaIdFiltro,etapaIdFiltro,faturamentoDiretoFiltro,entregavelIdFiltro,equipeResponsavelIdFiltro,personalizado1IdFiltro,personalizado2IdFiltro,personalizado3IdFiltro,personalizado4IdFiltro,personalizado5IdFiltro,caminhoCriticoFiltro,temRestricoesFiltro,limparFiltrosAtividades"
     class="position-fixed top-0 start-0 w-100 h-100 align-items-start justify-content-center"
     style="z-index:9999;background:rgba(255,255,255,.4);padding-top:80px">
    <div class="d-flex align-items-center gap-2 bg-white shadow rounded px-4 py-2 border">
      <img src="{{ asset('assets/img/loader/dcf_logo.gif') }}" alt="DCF" height="50">
      <span class="text-muted">Calma bb... Carregando atividades...</span>
    </div>
</div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Header --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div>
             <h5 class="mb-1 mt-2">⏱️ Linhas de Base</h5>
            <small class="text-muted mb-0">Uma linha de base (baseline) é a versão aprovada do plano original de um projeto. Ela funciona como uma fotografia do planejamento e serve como referencial para medições.</small>
        </div>
        <button class="btn btn-primary" wire:click="$set('modalCriar', true)">
            <i class="bx bx-plus me-1"></i>Nova Linha de Base
        </button>
    </div>



    {{-- ------------------------------------------------------------------ --}}
    {{-- Estado vazio --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($this->linhasBase->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-bookmark fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhuma linha de base salva para esta obra.</p>
            <p class="text-muted small mb-3">
                Importe um cronograma e depois salve-o aqui como referência com nome e descrição.
            </p>
            @if($this->importacoesDisponiveis->isNotEmpty())
            <button class="btn btn-primary" wire:click="$set('modalCriar', true)">
                <i class="bx bx-plus me-1"></i>Salvar primeira linha de base
            </button>
            @else
            <a href="{{ route('radar.cronograma') }}" class="btn btn-outline-primary">
                <i class="bx bx-upload me-1"></i>Importar cronograma primeiro
            </a>
            @endif
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Lista de linhas de base --}}
    {{-- ------------------------------------------------------------------ --}}
    @foreach($this->linhasBase as $lb)
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-start gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h6 class="mb-0 fw-bold">{{ $lb->nome }}</h6>
                        <span class="badge bg-label-primary">
                            <i class="bx bx-calendar me-1"></i>
                            {{ $lb->importacao?->data_status?->format('d/m/Y') ?? $lb->importacao?->importado_em?->format('d/m/Y') }}
                        </span>
                    </div>
                    @if($lb->descricao)
                    <p class="text-muted small mb-1">{{ $lb->descricao }}</p>
                    @endif
                    <small class="text-muted">
                        Importação de
                        <strong>{{ $lb->importacao?->importado_em?->format('d/m/Y H:i') }}</strong>
                        — {{ $lb->importacao?->criadas ?? 0 }} criadas,
                        {{ $lb->importacao?->atualizadas ?? 0 }} atualizadas
                        @if($lb->criador)
                        — por {{ $lb->criador->first_name }} {{ $lb->criador->last_name }}
                        @endif
                    </small>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <button class="btn btn-sm btn-outline-secondary"
                            wire:click="expandir('{{ $lb->id }}')"
                            title="{{ $linhaExpandida === $lb->id ? 'Fechar' : 'Ver atividades' }}">
                        <i class="bx {{ $linhaExpandida === $lb->id ? 'bx-chevron-up' : 'bx-chevron-down' }} me-1"></i>
                        Atividades
                    </button>
                    <a href="{{ route('radar.curvas', ['linha_base_id' => $lb->id]) }}" class="btn btn-sm btn-outline-info"
                       title="Ver curvas usando esta linha de base">
                        <i class="bx bx-line-chart"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            title="Remover linha de base"
                            onclick="confirmarAcao(this, {
                                mensagem: 'Remover a linha de base \'{{ $lb->nome }}\'? Esta ação não apaga os dados do cronograma.',
                                metodo: 'excluir',
                                args: ['{{ $lb->id }}'],
                                icone: 'bx-trash',
                            })">
                        <i class="bx bx-trash"></i>
                    </button>
                </div>
            </div>
        </div>

        {{-- Seção expandida: atividades --}}
        @if($linhaExpandida === $lb->id)
        <div class="card-footer bg-light p-0">
            <div class="p-3">
                <h6 class="text-muted mb-3">
                    <i class="bx bx-list-ul me-1"></i>
                    Atividades — clique em <strong>+ Restrição</strong> para registrar um impedimento,
                    ou no ícone de lápis pra editar nome/disciplina/caminho crítico
                </h6>

                @if(empty($this->arvoreAtividades))
                <p class="text-muted small mb-0">Nenhuma atividade ativa encontrada para esta obra.</p>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Nome da Tarefa</th>
                                <th>Disciplina</th>
                                <th class="text-center" style="width:90px">Duração LB</th>
                                <th class="text-center" style="width:100px">Início LB</th>
                                <th class="text-center" style="width:100px">Término LB</th>
                                <th class="text-center" style="width:110px">Caminho Crítico</th>
                                <th class="text-center" style="width:90px">Restrições</th>
                                <th style="width:150px">Ações</th>
                            </tr>
                        </thead>
                        <tbody x-data="{ recolhidos: [] }">
                            @foreach($this->arvoreAtividades as $linha)
                            @php $ancestraisJson = json_encode($linha['ancestrais']); @endphp

                            @if($linha['tipo'] === 'pacote')
                            <tr wire:key="pacote-{{ $lb->id }}-{{ $linha['id'] }}"
                                x-show="!({{ $ancestraisJson }}).some(id => recolhidos.includes(id))"
                                class="table-light">
                                <td colspan="9" style="padding-left: {{ $linha['nivel'] * 24 }}px">
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
                            @endphp
                            <tr wire:key="atividade-{{ $lb->id }}-{{ $linha['id'] }}"
                                x-show="!({{ $ancestraisJson }}).some(id => recolhidos.includes(id))">
                                <td class="text-muted small">{{ $at->external_uid ?? '—' }}</td>
                                <td style="padding-left: 24px">{{ $at->nome }}</td>
                                <td class="small">{{ $at->disciplina?->nome ?? '—' }}</td>
                                <td class="text-center text-muted small">{{ $row['duracaoBaseline'] ?? '—' }}</td>
                                <td class="text-center text-muted small">
                                    {{ $row['inicioBaseline']?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td class="text-center text-muted small">
                                    {{ $row['terminoBaseline']?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td class="text-center">
                                    @if($at->caminho_critico)
                                    <span class="badge bg-danger"><i class="bx bx-error-circle me-1"></i>Sim</span>
                                    @else
                                    <span class="badge bg-label-secondary">Não</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($at->restricoes_count > 0)
                                    <span class="badge bg-warning text-dark">
                                        {{ $at->restricoes_count }}
                                    </span>
                                    @else
                                    <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if($atividadeParaRestricao === $at->id)
                                    <span class="text-muted small"><i class="bx bx-pencil me-1"></i>editando...</span>
                                    @else
                                    @if($this->podeEditarAtividades)
                                    <button class="btn btn-xs btn-outline-secondary py-0 px-1" title="Editar"
                                            wire:click="abrirEdicaoAtividade('{{ $at->id }}')">
                                        <i class="bx bx-pencil"></i>
                                    </button>
                                    @endif
                                    <button class="btn btn-xs btn-outline-warning py-0 px-2"
                                            wire:click="abrirNovaRestricao('{{ $at->id }}')">
                                        <i class="bx bx-plus me-1"></i>Restrição
                                    </button>
                                    @endif
                                </td>
                            </tr>

                            {{-- Formulário inline de nova restrição (campos completos) --}}
                            @if($atividadeParaRestricao === $at->id)
                            <tr class="table-warning">
                                <td colspan="9" class="p-3">
                                    <div class="card border-warning shadow-none mb-0">
                                        <div class="card-body py-3">
                                            <h6 class="mb-3">
                                                <i class="bx bx-error-circle text-warning me-1"></i>
                                                Nova restrição em: <em>{{ $at->nome }}</em>
                                            </h6>
                                            <div class="row g-2">
                                                {{-- Descrição --}}
                                                <div class="col-12">
                                                    <label class="form-label form-label-sm">Descrição <span class="text-danger">*</span></label>
                                                    <input type="text"
                                                           class="form-control form-control-sm @error('restricaoDescricao') is-invalid @enderror"
                                                           wire:model="restricaoDescricao"
                                                           placeholder="Descreva o impedimento...">
                                                    @error('restricaoDescricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                </div>
                                                {{-- Tipo + Prazo --}}
                                                <div class="col-md-6">
                                                    <label class="form-label form-label-sm">Tipo de Restrição</label>
                                                    <select class="form-select form-select-sm" wire:model="restricaoCategoriaId">
                                                        <option value="">— Sem tipo —</option>
                                                        @foreach($this->categorias as $cat)
                                                        <option value="{{ $cat->id }}">{{ $cat->nome }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label form-label-sm">Prazo limite <span class="text-danger">*</span></label>
                                                    <input type="date"
                                                           class="form-control form-control-sm @error('restricaoPrazo') is-invalid @enderror"
                                                           wire:model="restricaoPrazo">
                                                    @error('restricaoPrazo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                </div>
                                                {{-- Responsável --}}
                                                <div class="col-12">
                                                    <label class="form-label form-label-sm">Responsável por resolver</label>
                                                    <div class="d-flex gap-3 mb-1">
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input" type="radio"
                                                                   id="ri_{{ $at->id }}" wire:model.live="restricaoRespExterno" value="0">
                                                            <label class="form-check-label small" for="ri_{{ $at->id }}">Interno</label>
                                                        </div>
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input" type="radio"
                                                                   id="re_{{ $at->id }}" wire:model.live="restricaoRespExterno" value="1">
                                                            <label class="form-check-label small" for="re_{{ $at->id }}">Externo</label>
                                                        </div>
                                                    </div>
                                                    @if(!$restricaoRespExterno)
                                                    <select class="form-select form-select-sm" wire:model="restricaoResponsavelId">
                                                        <option value="">— Sem responsável —</option>
                                                        @foreach($this->usuariosDaObra as $u)
                                                        <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                                                        @endforeach
                                                    </select>
                                                    @else
                                                    <input type="text" class="form-control form-control-sm"
                                                           wire:model="restricaoResponsavelExt"
                                                           placeholder="Nome externo...">
                                                    @endif
                                                </div>
                                                {{-- P×I --}}
                                                <div class="col-md-3">
                                                    <label class="form-label form-label-sm">Probabilidade (0–10)</label>
                                                    <input type="number" class="form-control form-control-sm"
                                                           min="0" max="10" wire:model="restricaoProbabilidade">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label form-label-sm">Impacto (0–10)</label>
                                                    <input type="number" class="form-control form-control-sm"
                                                           min="0" max="10" wire:model="restricaoImpacto">
                                                </div>
                                                <div class="col-md-6 d-flex align-items-center">
                                                    <div class="form-check form-switch mt-3">
                                                        <input class="form-check-input" type="checkbox"
                                                               wire:model="restricaoBloqueante"
                                                               id="bloqueante_{{ $at->id }}">
                                                        <label class="form-check-label small" for="bloqueante_{{ $at->id }}">
                                                            <strong>Bloqueante</strong>
                                                            <span class="text-muted d-block" style="font-size:0.75rem">
                                                                Impede o comprometimento da atividade
                                                            </span>
                                                        </label>
                                                    </div>
                                                </div>
                                                {{-- Legenda P×I --}}
                                                <div class="col-12">
                                                    <div class="alert alert-light border py-2 mb-0">
                                                        <small class="text-muted">
                                                            <strong>P×I:</strong>
                                                            0 = não ocorre / sem impacto &nbsp;|&nbsp;
                                                            5 = possível / atraso moderado &nbsp;|&nbsp;
                                                            10 = certo / paralisa a obra.
                                                            P×I ≥ 50 = crítico.
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-2 mt-2">
                                                <button class="btn btn-sm btn-warning" wire:click="salvarRestricao">
                                                    <i class="bx bx-check me-1"></i>Salvar
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary" wire:click="cancelarRestricao">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            @endif
                            @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>
    @endforeach

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Nova Linha de Base --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($modalCriar)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-bookmark-plus me-2"></i>Nova Linha de Base</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalCriar', false)"></button>
                </div>
                <div class="modal-body">
                    {{-- Importação disponível --}}
                    <div class="mb-3">
                        <label class="form-label">Importação de referência <span class="text-danger">*</span></label>
                        @if($this->importacoesDisponiveis->isEmpty())
                        <div class="alert alert-warning py-2 mb-0">
                            <i class="bx bx-info-circle me-1"></i>
                            Todas as importações já possuem uma linha de base vinculada.
                            <a href="{{ route('radar.cronograma') }}" class="ms-2">Importar novo cronograma →</a>
                        </div>
                        @else
                        <select class="form-select @error('importacaoSelecionada') is-invalid @enderror"
                                wire:model="importacaoSelecionada">
                            <option value="">— Selecione uma importação —</option>
                            @foreach($this->importacoesDisponiveis as $imp)
                            <option value="{{ $imp->id }}">
                                {{ $imp->importado_em->format('d/m/Y H:i') }}
                                @if($imp->data_status)
                                — Status: {{ $imp->data_status->format('d/m/Y') }}
                                @endif
                                — {{ $imp->criadas }} criadas, {{ $imp->atualizadas }} atualizadas
                            </option>
                            @endforeach
                        </select>
                        @error('importacaoSelecionada')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        @endif
                    </div>

                    {{-- Nome --}}
                    <div class="mb-3">
                        <label class="form-label">Nome da linha de base <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome"
                               placeholder="ex: Baseline 0 – Contrato Original">
                        @error('nome')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Descrição --}}
                    <div class="mb-0">
                        <label class="form-label">Descrição <span class="text-muted">(opcional)</span></label>
                        <textarea class="form-control" rows="2"
                                  wire:model="descricao"
                                  placeholder="Contexto, motivo ou observações sobre este snapshot..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="$set('modalCriar', false)">
                        Cancelar
                    </button>
                    <button class="btn btn-primary"
                            wire:click="salvar"
                            @disabled($this->importacoesDisponiveis->isEmpty())>
                        <i class="bx bx-bookmark-plus me-1"></i>Salvar linha de base
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal: Editar Atividade (nome, disciplina, caminho crítico) --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($atividadeEditandoId)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-pencil me-2"></i>Editar Atividade</h5>
                    <button type="button" class="btn-close" wire:click="cancelarEdicaoAtividade"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome da tarefa <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('editNome') is-invalid @enderror"
                               wire:model="editNome">
                        @error('editNome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Disciplina</label>
                        <select class="form-select @error('editDisciplinaId') is-invalid @enderror"
                                wire:model="editDisciplinaId">
                            <option value="">— Sem disciplina —</option>
                            @foreach($this->disciplinas as $d)
                            <option value="{{ $d->id }}">{{ $d->nome }}</option>
                            @endforeach
                        </select>
                        @error('editDisciplinaId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="editCaminhoCritico"
                               wire:model="editCaminhoCritico">
                        <label class="form-check-label" for="editCaminhoCritico">
                            <strong>Caminho crítico</strong>
                            <small class="text-muted d-block">
                                Classificação manual — não há cálculo automático de CPM nesta versão
                            </small>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="cancelarEdicaoAtividade">Cancelar</button>
                    <button class="btn btn-primary" wire:click="salvarEdicaoAtividade" wire:loading.attr="disabled">
                        Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Canva lateral: filtros + exportação das atividades da linha       --}}
    {{-- de base expandida — mesmo padrão de Restrições/Lookahead/Curvas S. --}}
    {{-- SEMPRE renderizado (nunca dentro de @if($linhaExpandida)) — o     --}}
    {{-- <style> abaixo só é extraído pro CSS externo do componente se     --}}
    {{-- estiver presente já na primeira carga da página; um bloco que só  --}}
    {{-- aparece depois de um wire:click nunca teria suas regras aplicadas --}}
    {{-- (mesma armadilha documentada em project_minhas_obras_cards_avanco). --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="canva-filtros-linhas-base" :class="filtrosAbertos ? 'canva-filtros-linhas-base-aberto' : ''" x-data="{ filtrosAbertos: false }">
        @if($linhaExpandida)
        <button type="button" class="canva-filtros-linhas-base-aba" @click="filtrosAbertos = true" title="Filtros">
            <i class="bx bx-filter-alt"></i>
            @if($this->temFiltrosAtividadesAtivos())
            <span class="badge bg-warning position-absolute top-0 start-0 translate-middle p-1 border border-light rounded-circle"></span>
            @endif
        </button>
        @endif

        <div class="canva-filtros-linhas-base-header d-flex align-items-center justify-content-between border-bottom px-4 py-3">
            <h6 class="mb-0 fw-semibold"><i class="bx bx-filter-alt me-1"></i>Filtros — Atividades</h6>
            <a href="javascript:void(0)" class="text-body" @click="filtrosAbertos = false">
                <i class="bx bx-x fs-4"></i>
            </a>
        </div>

        <div class="canva-filtros-linhas-base-body px-4 py-3">
            <div class="row g-2">
                <div class="col-12">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                        <input type="text" class="form-control" placeholder="Buscar por nome ou ID..."
                               wire:model.live.debounce.300ms="buscaAtividade">
                    </div>
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
                    <select class="form-select form-select-sm" wire:model.live="etapaIdFiltro">
                        <option value="">Todas as etapas</option>
                        @foreach($this->etapas as $et)
                        <option value="{{ $et->id }}">{{ $et->nome }}</option>
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
                    <select class="form-select form-select-sm" wire:model.live="caminhoCriticoFiltro">
                        <option value="">Caminho crítico: todos</option>
                        <option value="1">Somente caminho crítico</option>
                        <option value="0">Somente fora do caminho crítico</option>
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="temRestricoesFiltro">
                        <option value="">Restrições: todas</option>
                        <option value="1">Somente com restrições</option>
                        <option value="0">Somente sem restrições</option>
                    </select>
                </div>
                @if($this->temFiltrosAtividadesAtivos())
                <div class="col-12">
                    <button class="btn btn-outline-secondary btn-sm w-100" wire:click="limparFiltrosAtividades">
                        <i class="bx bx-x me-1"></i>Limpar filtros
                    </button>
                </div>
                @endif
                <div class="col-12"><hr class="my-1"></div>
                @if(!empty($this->arvoreAtividades))
                <div class="col-12">
                    <button class="btn btn-outline-danger btn-sm w-100" wire:click="exportarAtividadesPdf">
                        <i class="bx bxs-file-pdf me-1"></i>PDF
                    </button>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-success btn-sm w-100" wire:click="exportarAtividadesExcel">
                        <i class="bx bxs-file-export me-1"></i>Excel
                    </button>
                </div>
                @else
                <div class="col-12">
                    <small class="text-muted">Nenhuma atividade encontrada com os filtros atuais.</small>
                </div>
                @endif
            </div>
        </div>

        {{-- z-index:1080 fica ACIMA da navbar fixa do template (.layout-navbar,
             z-index:1075) e ABAIXO dos modais do Bootstrap (z-index:1090) — ver
             explicação completa no mesmo bloco em ⚡restricoes.blade.php.
             Bug de teste manual, 2026-09-02 — `top: 0` fazia o canva cobrir a
             faixa da navbar fixa (0 a 3.875rem, = $navbar-height do tema);
             como o z-index do canva é maior, um clique no sino/perfil/
             app-grid nessa faixa era engolido pelo canva aberto (mesmo bug
             nos 8 arquivos que usam este padrão). Corrigido começando o
             canva abaixo da navbar. --}}
        <style>
        .canva-filtros-linhas-base {
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

        .canva-filtros-linhas-base.canva-filtros-linhas-base-aberto {
            right: 0;
        }

        .canva-filtros-linhas-base-body {
            flex: 1 1 auto;
            overflow-y: auto;
        }

        .canva-filtros-linhas-base-aba {
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

        .canva-filtros-linhas-base.canva-filtros-linhas-base-aberto .canva-filtros-linhas-base-aba {
            opacity: 0;
            pointer-events: none;
        }

        @media (max-width: 575.98px) {
            .canva-filtros-linhas-base {
                width: 300px;
                right: -300px;
            }

            .canva-filtros-linhas-base.canva-filtros-linhas-base-aberto {
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
