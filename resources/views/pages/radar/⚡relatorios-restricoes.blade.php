<?php

use App\Enums\PilarLean;
use App\Enums\StatusRestricao;
use App\Exports\RelatorioRestricoesExport;
use App\Models\AtividadeItemProntidao;
use App\Models\CategoriaRestricao;
use App\Models\Disciplina;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\FrenteTrabalho;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Restricao;
use App\Models\User;
use App\Models\Work;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Relatórios de Restrições — analytics pro gestor da obra: quantas
 * restrições cada responsável tem, quantas estão previstas por período
 * (filtro de intervalo escolhido pelo usuário), prontidão por disciplina,
 * atrasadas, tempo médio de resolução, por pilar/categoria e um resumo de
 * risco alto (P×I) com link pra Matriz completa. Página só de leitura —
 * sem ExecutaComTransacaoSegura (não há create/update/delete aqui).
 */
new class extends Component {
  public Work $obra;

  private const MESES_PT = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

  private const CORES_PILAR = [
    'materiais' => '#696cff',
    'mao_de_obra' => '#03c3ec',
    'equipamentos' => '#ffab00',
    'informacoes' => '#71dd37',
    'condicoes_precedentes' => '#ff3e1d',
  ];

  // ---- Filtros ----
  public string $filtroDataInicio = '';
  public string $filtroDataFim = '';
  public string $granularidade = 'mensal';
  public string $baseTemporal = 'prazo_limite';
  public string $filtroStatus = '';
  public string $filtroPilar = '';
  public string $filtroCategoriaId = '';
  public bool $apenasBloqueantes = false;
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

  public function mount(Work $obra): void
  {
    $this->obra = $obra;
    $this->filtroDataInicio = now()->subMonths(6)->startOfMonth()->format('Y-m-d');
    $this->filtroDataFim = now()->addMonths(6)->endOfMonth()->format('Y-m-d');
  }

  // =========================================================================
  // QUERY BASE
  // =========================================================================

  private function queryBase(): \Illuminate\Database\Eloquent\Builder
  {
    // Mesmo padrão de queryRestricoesFiltrada() em ⚡restricoes.blade.php:
    // JOIN direto em vez de whereHas — mais rápido com os índices já criados.
    // Sem select('restricoes.*') aqui de propósito: a maioria dos indicadores
    // é agregada (selectRaw com COUNT/SUM/AVG) e um select('restricoes.*')
    // pré-existente quebra sql_mode=only_full_group_by do MySQL (selectRaw
    // ACRESCENTA ao select, não substitui). Só atrasadas() precisa da linha
    // inteira pra hidratar o Model — ela mesma adiciona o select ali.
    return Restricao::join('atividades as a', function ($join) {
      $join
        ->on('restricoes.atividade_id', '=', 'a.id')
        ->whereNull('a.deleted_at')
        ->where('a.obra_id', $this->obra->id);
    })
      ->when($this->filtroStatus, fn($q) => $q->where('restricoes.status', $this->filtroStatus))
      ->when($this->apenasBloqueantes, fn($q) => $q->where('restricoes.bloqueante', true))
      ->when($this->filtroCategoriaId, fn($q) => $q->where('restricoes.categoria_id', $this->filtroCategoriaId))
      ->when(
        $this->filtroPilar,
        fn($q) => $q->whereHas('categoria', fn($c) => $c->where('pilar_lean', $this->filtroPilar))
      )
      ->when($this->filtroResponsavelId, fn($q) => $q->where('restricoes.responsavel_id', $this->filtroResponsavelId))
      ->tap(fn($q) => $this->aplicarFiltrosClassificacaoAtividade($q));
  }

  /**
   * Filtros de classificação da atividade (alias `a.`) compartilhados por
   * queryBase() e prontidaoPorDisciplina() — os dois joinam atividades como
   * `a`. Extraído pra não duplicar os 8 ->when() em cada site.
   */
  private function aplicarFiltrosClassificacaoAtividade(\Illuminate\Database\Eloquent\Builder $q): void
  {
    $q->when($this->filtroDisciplinaId, fn($qq) => $qq->where('a.disciplina_id', $this->filtroDisciplinaId))
      ->when($this->filtroFrenteTrabalhoId, fn($qq) => $qq->where('a.frente_trabalho_id', $this->filtroFrenteTrabalhoId))
      ->when(
        $this->filtroFaturamentoDireto !== null && $this->filtroFaturamentoDireto !== '',
        fn($qq) => $qq->where('a.faturamento_direto', $this->filtroFaturamentoDireto === '1')
      )
      ->when($this->filtroEntregavelId, fn($qq) => $qq->where('a.entregavel_id', $this->filtroEntregavelId))
      ->when($this->filtroEquipeResponsavelId, fn($qq) => $qq->where('a.equipe_responsavel_id', $this->filtroEquipeResponsavelId))
      ->when($this->filtroPersonalizado1Id, fn($qq) => $qq->where('a.personalizado_1_id', $this->filtroPersonalizado1Id))
      ->when($this->filtroPersonalizado2Id, fn($qq) => $qq->where('a.personalizado_2_id', $this->filtroPersonalizado2Id))
      ->when($this->filtroPersonalizado3Id, fn($qq) => $qq->where('a.personalizado_3_id', $this->filtroPersonalizado3Id))
      ->when($this->filtroPersonalizado4Id, fn($qq) => $qq->where('a.personalizado_4_id', $this->filtroPersonalizado4Id))
      ->when($this->filtroPersonalizado5Id, fn($qq) => $qq->where('a.personalizado_5_id', $this->filtroPersonalizado5Id));
  }

  // =========================================================================
  // INDICADORES
  // =========================================================================

  #[Computed]
  public function totais(): array
  {
    $row = $this->queryBase()
      ->selectRaw(
        "
                COUNT(*) as total,
                SUM(restricoes.status != 'resolvida') as abertas,
                SUM(restricoes.status = 'resolvida') as resolvidas
            "
      )
      ->first();

    return [
      'total' => (int) ($row->total ?? 0),
      'abertas' => (int) ($row->abertas ?? 0),
      'resolvidas' => (int) ($row->resolvidas ?? 0),
      'atrasadas' => $this->atrasadas->count(),
      'tempoMedioGeral' => $this->tempoMedioResolucao['geral'],
      'riscoAlto' => $this->riscoAltoPXI,
    ];
  }

  #[Computed]
  public function porResponsavel(): array
  {
    $linhas = $this->queryBase()
      ->select('restricoes.responsavel_id', 'restricoes.responsavel_externo')
      ->selectRaw(
        "
                COUNT(*) as total,
                SUM(restricoes.status != 'resolvida') as abertas,
                SUM(restricoes.bloqueante = 1 AND restricoes.status != 'resolvida') as bloqueantes
            "
      )
      ->groupBy('restricoes.responsavel_id', 'restricoes.responsavel_externo')
      ->get();

    $ids = $linhas->pluck('responsavel_id')->filter()->unique();
    $usuarios = $ids->isNotEmpty() ? User::whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])->keyBy('id') : collect();

    return $linhas
      ->map(function ($l) use ($usuarios) {
        if ($l->responsavel_id && $usuarios->has($l->responsavel_id)) {
          $u = $usuarios->get($l->responsavel_id);
          $nome = trim("{$u->first_name} {$u->last_name}");
        } elseif ($l->responsavel_externo) {
          $nome = "Externo: {$l->responsavel_externo}";
        } else {
          $nome = 'Sem responsável';
        }

        return [
          'nome' => $nome,
          'total' => (int) $l->total,
          'abertas' => (int) $l->abertas,
          'bloqueantes' => (int) $l->bloqueantes,
        ];
      })
      ->sortByDesc('total')
      ->values()
      ->all();
  }

  #[Computed]
  public function porPeriodo(): array
  {
    if (!$this->filtroDataInicio || !$this->filtroDataFim) {
      return [];
    }

    $coluna = $this->baseTemporal === 'aberta_em' ? 'aberta_em' : 'prazo_limite';
    $formato = $this->granularidade === 'semanal' ? '%x-%v' : '%Y-%m';

    $linhas = $this->queryBase()
      ->whereNotNull("restricoes.{$coluna}")
      ->whereBetween("restricoes.{$coluna}", [$this->filtroDataInicio, $this->filtroDataFim])
      ->selectRaw("DATE_FORMAT(restricoes.{$coluna}, '{$formato}') as periodo, COUNT(*) as total, SUM(restricoes.status = 'resolvida') as resolvidas")
      ->groupBy('periodo')
      ->orderBy('periodo')
      ->get();

    return $linhas
      ->map(
        fn($l) => [
          'periodo' => $l->periodo,
          'label' => $this->formatarPeriodoLabel($l->periodo),
          'total' => (int) $l->total,
          'resolvidas' => (int) $l->resolvidas,
        ]
      )
      ->all();
  }

  private function formatarPeriodoLabel(string $periodo): string
  {
    if ($this->granularidade === 'semanal') {
      [$ano, $semana] = explode('-', $periodo);
      return "S{$semana}/" . substr($ano, 2);
    }

    [$ano, $mes] = explode('-', $periodo);
    return self::MESES_PT[(int) $mes - 1] . '/' . substr($ano, 2);
  }

  // =========================================================================
  // PPC — ADERÊNCIA AO PLANEJAMENTO SEMANAL (Last Planner System)
  // =========================================================================

  private function ppcQuery(): \Illuminate\Database\Eloquent\Builder
  {
    // Ancorado em ProgramacaoSemanalItem (o conjunto CONGELADO no momento
    // do comprometimento), não em Atividade.inicio_planejado/data_termino
    // ao vivo — diferente do PPC ao vivo de ⚡plano-semanal.blade.php, que
    // usa status ATUAL da atividade e não serve pra tendência histórica.
    //
    // Ciclo 24 — correção do denominador duplicado em semanas revisadas:
    // `CriarRevisaoProgramacaoSemanal` nunca apaga os itens da versão
    // original ao criar uma revisão (histórico linear, sempre preservado)
    // — sem o filtro abaixo, um `COUNT(*)` cru contaria os itens das DUAS
    // versões pra mesma semana. O join agora só aceita a linha de
    // `programacoes_semanais` cuja `versao` é a MAIOR entre todas as
    // versões da MESMA obra+semana_inicio — exatamente a mesma noção de
    // "vigente" já usada por `ProgramacaoSemanal::ativaPara()`, sem
    // depender de `superseded_at` (que pode ficar `null` em dado legado).
    return ProgramacaoSemanalItem::query()
      ->join('programacoes_semanais as ps', function ($join) {
        $join->on('ps.id', '=', 'programacao_semanal_itens.programacao_semanal_id')
          ->whereRaw(
            'ps.versao = (SELECT MAX(ps2.versao) FROM programacoes_semanais ps2 '
            . 'WHERE ps2.obra_id = ps.obra_id AND ps2.semana_inicio = ps.semana_inicio)'
          );
      })
      ->join('atividades as a', function ($join) {
        $join->on('a.id', '=', 'programacao_semanal_itens.atividade_id')->whereNull('a.deleted_at');
      })
      ->where('ps.obra_id', $this->obra->id)
      // Exclui a semana corrente/futura — ainda em andamento, mostraria PPC artificialmente baixo.
      ->where('ps.semana_fim', '<', now()->startOfWeek()->toDateString())
      ->when($this->filtroDataInicio, fn($q) => $q->where('ps.semana_inicio', '>=', $this->filtroDataInicio))
      ->when($this->filtroDataFim, fn($q) => $q->where('ps.semana_inicio', '<=', $this->filtroDataFim));
  }

  #[Computed]
  public function ppcPorSemana(): array
  {
    $linhas = $this->ppcQuery()
      ->selectRaw(
        "
                ps.semana_inicio as semana_inicio,
                COUNT(*) as comprometidas,
                SUM(a.concluido_em IS NOT NULL AND DATE(a.concluido_em) <= ps.semana_fim) as concluidas_no_prazo
            "
      )
      ->groupBy('ps.semana_inicio', 'ps.semana_fim')
      ->orderBy('ps.semana_inicio')
      ->get();

    return $linhas
      ->map(function ($l) {
        $comprometidas = (int) $l->comprometidas;
        $concluidas = (int) $l->concluidas_no_prazo;
        $percentual = $comprometidas > 0 ? round(($concluidas / $comprometidas) * 100, 1) : 0.0;

        return [
          'semana_label' => $this->formatarSemanaLabel($l->semana_inicio),
          'comprometidas' => $comprometidas,
          'concluidas_no_prazo' => $concluidas,
          'ppc_percentual' => $percentual,
          'cor' => $percentual >= 80 ? '#71dd37' : ($percentual >= 60 ? '#ffab00' : '#ff3e1d'),
        ];
      })
      ->all();
  }

  private function formatarSemanaLabel(string $semanaInicio): string
  {
    $data = \Illuminate\Support\Carbon::parse($semanaInicio);
    return sprintf('S%02d/%s', (int) $data->format('W'), $data->format('y'));
  }

  #[Computed]
  public function atrasadas()
  {
    return $this->queryBase()
      ->select('restricoes.*')
      ->with(['atividade:id,nome', 'responsavel:id,first_name,last_name', 'categoria:id,nome'])
      ->where('restricoes.status', '!=', StatusRestricao::Resolvida->value)
      ->whereNotNull('restricoes.prazo_limite')
      ->where('restricoes.prazo_limite', '<', now()->startOfDay())
      ->orderBy('restricoes.prazo_limite')
      ->get();
  }

  #[Computed]
  public function tempoMedioResolucao(): array
  {
    $porCategoria = $this->queryBase()
      ->leftJoin('categorias_restricao as cat_tempo', 'cat_tempo.id', '=', 'restricoes.categoria_id')
      ->where('restricoes.status', StatusRestricao::Resolvida->value)
      ->whereNotNull('restricoes.resolvida_em')
      ->whereNotNull('restricoes.aberta_em')
      ->selectRaw("COALESCE(cat_tempo.nome, 'Sem categoria') as categoria, AVG(DATEDIFF(restricoes.resolvida_em, restricoes.aberta_em)) as media_dias, COUNT(*) as total")
      ->groupBy('categoria')
      ->orderByDesc('total')
      ->get();

    $geral = $this->queryBase()
      ->where('restricoes.status', StatusRestricao::Resolvida->value)
      ->whereNotNull('restricoes.resolvida_em')
      ->whereNotNull('restricoes.aberta_em')
      ->selectRaw('AVG(DATEDIFF(restricoes.resolvida_em, restricoes.aberta_em)) as media_dias')
      ->value('media_dias');

    return [
      'geral' => $geral !== null ? round((float) $geral, 1) : null,
      'porCategoria' => $porCategoria
        ->map(
          fn($l) => [
            'categoria' => $l->categoria,
            'mediaDias' => round((float) $l->media_dias, 1),
            'total' => (int) $l->total,
          ]
        )
        ->all(),
    ];
  }

  #[Computed]
  public function prontidaoPorDisciplina(): array
  {
    $linhas = AtividadeItemProntidao::join('atividades as a', 'a.id', '=', 'atividade_itens_prontidao.atividade_id')
      ->leftJoin('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
      ->where('a.obra_id', $this->obra->id)
      ->whereNull('a.deleted_at')
      ->tap(fn($q) => $this->aplicarFiltrosClassificacaoAtividade($q))
      ->selectRaw("COALESCE(d.nome, 'Sem disciplina') as disciplina, COUNT(*) as total, SUM(atividade_itens_prontidao.concluido = 1) as concluidos")
      ->groupBy('disciplina')
      ->orderByDesc('total')
      ->get();

    return $linhas
      ->map(
        fn($l) => [
          'disciplina' => $l->disciplina,
          'total' => (int) $l->total,
          'concluidos' => (int) $l->concluidos,
          'percentual' => $l->total > 0 ? round(($l->concluidos / $l->total) * 100, 1) : 0.0,
        ]
      )
      ->all();
  }

  #[Computed]
  public function porPilar(): array
  {
    $linhas = $this->queryBase()
      ->join('categorias_restricao as cat_pilar', 'cat_pilar.id', '=', 'restricoes.categoria_id')
      ->selectRaw("cat_pilar.pilar_lean as pilar, COUNT(*) as total, SUM(restricoes.status != 'resolvida') as abertas")
      ->groupBy('cat_pilar.pilar_lean')
      ->get()
      ->keyBy('pilar');

    $dados = [];
    foreach (PilarLean::cases() as $pilar) {
      $linha = $linhas->get($pilar->value);
      $total = (int) ($linha->total ?? 0);
      if ($total === 0) {
        continue;
      }
      $dados[] = [
        'label' => $this->labelPilar($pilar),
        'total' => $total,
        'abertas' => (int) ($linha->abertas ?? 0),
        'cor' => self::CORES_PILAR[$pilar->value],
      ];
    }

    return $dados;
  }

  private function labelPilar(PilarLean $pilar): string
  {
    return match ($pilar) {
      PilarLean::Materiais => 'Materiais',
      PilarLean::MaoDeObra => 'Mão de Obra',
      PilarLean::Equipamentos => 'Equipamentos',
      PilarLean::Informacoes => 'Informações',
      PilarLean::CondicoesPrecedentes => 'Condições Precedentes',
    };
  }

  #[Computed]
  public function porCategoria(): array
  {
    $linhas = $this->queryBase()
      ->leftJoin('categorias_restricao as cat_lista', 'cat_lista.id', '=', 'restricoes.categoria_id')
      ->selectRaw("COALESCE(cat_lista.nome, 'Sem categoria') as categoria, COUNT(*) as total, SUM(restricoes.status != 'resolvida') as abertas")
      ->groupBy('categoria')
      ->orderByDesc('total')
      ->get();

    return $linhas->map(fn($l) => ['categoria' => $l->categoria, 'total' => (int) $l->total, 'abertas' => (int) $l->abertas])->all();
  }

  #[Computed]
  public function statusGeral(): array
  {
    $linhas = $this->queryBase()->selectRaw('restricoes.status as status, COUNT(*) as total')->groupBy('restricoes.status')->pluck('total', 'status');

    $config = [
      'aberta' => ['label' => 'Aberta', 'cor' => '#ff3e1d'],
      'em_tratamento' => ['label' => 'Em Tratamento', 'cor' => '#ffab00'],
      'aguardando_terceiros' => ['label' => 'Ag. Terceiros', 'cor' => '#03c3ec'],
      'resolvida' => ['label' => 'Resolvida', 'cor' => '#71dd37'],
    ];

    $dados = [];
    foreach ($config as $valor => $cfg) {
      $total = (int) ($linhas[$valor] ?? 0);
      if ($total === 0) {
        continue;
      }
      $dados[] = ['status' => $cfg['label'], 'total' => $total, 'cor' => $cfg['cor']];
    }

    return $dados;
  }

  #[Computed]
  public function riscoDistribuicao(): array
  {
    $riscos = $this->queryBase()
      ->whereIn('restricoes.status', ['aberta', 'em_tratamento', 'aguardando_terceiros'])
      ->whereNotNull('restricoes.probabilidade')
      ->whereNotNull('restricoes.impacto')
      ->selectRaw('restricoes.probabilidade * restricoes.impacto as risco')
      ->pluck('risco');

    return [
      ['label' => 'Baixo', 'total' => $riscos->filter(fn($r) => $r < 25)->count(), 'cor' => '#71dd37'],
      ['label' => 'Médio', 'total' => $riscos->filter(fn($r) => $r >= 25 && $r < 50)->count(), 'cor' => '#ffab00'],
      ['label' => 'Alto', 'total' => $riscos->filter(fn($r) => $r >= 50)->count(), 'cor' => '#ff3e1d'],
    ];
  }

  #[Computed]
  public function riscoAltoPXI(): int
  {
    return collect($this->riscoDistribuicao)->firstWhere('label', 'Alto')['total'] ?? 0;
  }

  // =========================================================================
  // DADOS ESTÁTICOS DE FILTRO
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
  public function disciplinas()
  {
    return Disciplina::orderBy('nome')->get(['id', 'nome']);
  }

  #[Computed]
  public function frentesTrabalho()
  {
    return FrenteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
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

  // =========================================================================
  // FILTROS
  // =========================================================================

  public function updated(): void
  {
    $this->dispatch('relatorio-atualizado', dados: $this->dadosGraficos());
  }

  private function dadosGraficos(): array
  {
    return [
      'porResponsavel' => $this->porResponsavel,
      'porPeriodo' => $this->porPeriodo,
      'porPilar' => $this->porPilar,
      'prontidaoPorDisciplina' => $this->prontidaoPorDisciplina,
      'statusGeral' => $this->statusGeral,
      'porCategoria' => $this->porCategoria,
      'tempoMedioResolucao' => $this->tempoMedioResolucao['porCategoria'],
      'riscoDistribuicao' => $this->riscoDistribuicao,
      'ppcPorSemana' => $this->ppcPorSemana,
    ];
  }

  public function temFiltrosAtivos(): bool
  {
    return $this->filtroStatus ||
      $this->filtroPilar ||
      $this->filtroCategoriaId ||
      $this->apenasBloqueantes ||
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

  public function limparFiltros(): void
  {
    $this->filtroStatus = '';
    $this->filtroPilar = '';
    $this->filtroCategoriaId = '';
    $this->apenasBloqueantes = false;
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
    $this->filtroDataInicio = now()->subMonths(6)->startOfMonth()->format('Y-m-d');
    $this->filtroDataFim = now()->addMonths(6)->endOfMonth()->format('Y-m-d');
    $this->dispatch('relatorio-atualizado', dados: $this->dadosGraficos());
  }

  // =========================================================================
  // EXPORTAÇÃO
  // =========================================================================

  private function dadosExport(): array
  {
    return [
      'porResponsavel' => $this->porResponsavel,
      'porPeriodo' => $this->porPeriodo,
      'atrasadas' => $this->atrasadas
        ->map(
          fn($r) => [
            $r->atividade?->nome ?? '—',
            Str::limit($r->descricao, 60),
            $r->responsavel ? "{$r->responsavel->first_name} {$r->responsavel->last_name}" : ($r->responsavel_externo ?: '—'),
            $r->prazo_limite?->format('d/m/Y') ?? '—',
            $r->prazo_limite ? (int) $r->prazo_limite->diffInDays(now()) : '—',
          ]
        )
        ->all(),
      'tempoMedioResolucao' => $this->tempoMedioResolucao,
      'prontidaoPorDisciplina' => $this->prontidaoPorDisciplina,
      'porPilar' => $this->porPilar,
      'porCategoria' => $this->porCategoria,
      'statusGeral' => $this->statusGeral,
      'riscoDistribuicao' => $this->riscoDistribuicao,
      'ppcPorSemana' => $this->ppcPorSemana,
    ];
  }

  public function exportarExcel()
  {
    return Excel::download(new RelatorioRestricoesExport($this->dadosExport()), "relatorio-restricoes-{$this->obra->id}.xlsx");
  }

  public function exportarPdf()
  {
    $pdf = Pdf::loadView('exports.relatorio-restricoes-pdf', [
      'obra' => $this->obra,
      'dados' => $this->dadosExport(),
      'totais' => $this->totais,
      'periodo' => [$this->filtroDataInicio, $this->filtroDataFim],
    ]);

    return response()->streamDownload(fn() => print $pdf->output(), "relatorio-restricoes-{$this->obra->id}.pdf");
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
     CARDS DE KPI
     ========================================================================= --}}
<div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
    <div class="col">
        <div class="card card-border-shadow-primary h-100">
            <div class="card-body">
                <h4 class="display-6 fw-bold text-primary mb-1">{{ $this->totais['total'] }}</h4>
                <p class="mb-0">Total no Filtro</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card card-border-shadow-danger h-100">
            <div class="card-body">
                <h4 class="display-6 fw-bold text-danger mb-1">{{ $this->totais['abertas'] }}</h4>
                <p class="mb-0">Abertas</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card card-border-shadow-warning h-100">
            <div class="card-body">
                <h4 class="display-6 fw-bold text-warning mb-1">{{ $this->totais['atrasadas'] }}</h4>
                <p class="mb-0">Atrasadas</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <h4 class="display-6 fw-bold mb-1">
                    {{ $this->totais['tempoMedioGeral'] !== null ? $this->totais['tempoMedioGeral'] . 'd' : '—' }}
                </h4>
                <p class="mb-0">Tempo Médio de Resolução</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <h4 class="display-6 fw-bold mb-1">{{ $this->totais['riscoAlto'] }}</h4>
                <p class="mb-0">Risco Alto (P×I)</p>
                <a href="{{ route('radar.matriz') }}" wire:navigate class="small">Ver Matriz P×I &rarr;</a>
            </div>
        </div>
    </div>
</div>

{{-- =========================================================================
     STATUS GERAL
     ========================================================================= --}}
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Status Geral das Restrições</h6></div>
    <div class="card-body">
        @if(count($this->statusGeral) > 0)
        <div style="height: 220px;">
            <canvas id="grafico-status-geral"></canvas>
        </div>
        @else
        <p class="text-muted text-center py-4 mb-0">Nenhuma restrição encontrada com os filtros atuais.</p>
        @endif
    </div>
</div>

{{-- =========================================================================
     POR RESPONSÁVEL
     ========================================================================= --}}
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Restrições por Responsável</h6></div>
    <div class="card-body">
        @if(count($this->porResponsavel) > 0)
        <div style="height: 280px;">
            <canvas id="grafico-por-responsavel"></canvas>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Responsável</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Abertas</th>
                        <th class="text-center">Bloqueantes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->porResponsavel as $r)
                    <tr>
                        <td>{{ $r['nome'] }}</td>
                        <td class="text-center">{{ $r['total'] }}</td>
                        <td class="text-center">{{ $r['abertas'] }}</td>
                        <td class="text-center">{{ $r['bloqueantes'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted text-center py-4 mb-0">Nenhuma restrição encontrada com os filtros atuais.</p>
        @endif
    </div>
</div>

{{-- =========================================================================
     POR PERÍODO
     ========================================================================= --}}
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h6 class="mb-0">Restrições por Período</h6>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="baseTemporal">
                <option value="prazo_limite">Base: Prazo Limite</option>
                <option value="aberta_em">Base: Data de Abertura</option>
            </select>
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="granularidade">
                <option value="mensal">Mensal</option>
                <option value="semanal">Semanal</option>
            </select>
            <input type="date" class="form-control form-control-sm" style="width:auto" wire:model.live="filtroDataInicio">
            <span class="text-muted">&rarr;</span>
            <input type="date" class="form-control form-control-sm" style="width:auto" wire:model.live="filtroDataFim">
        </div>
    </div>
    <div class="card-body">
        @if(count($this->porPeriodo) > 0)
        <div style="height: 280px;">
            <canvas id="grafico-por-periodo"></canvas>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Período</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Resolvidas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->porPeriodo as $p)
                    <tr>
                        <td>{{ $p['label'] }}</td>
                        <td class="text-center">{{ $p['total'] }}</td>
                        <td class="text-center">{{ $p['resolvidas'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted text-center py-4 mb-0">Nenhuma restrição com data dentro do intervalo selecionado.</p>
        @endif
    </div>
</div>

{{-- =========================================================================
     PPC — ADERÊNCIA AO PLANEJAMENTO SEMANAL (Last Planner System)
     ========================================================================= --}}
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">PPC — Aderência ao Planejamento Semanal</h6></div>
    <div class="card-body">
        @if(count($this->ppcPorSemana) > 0)
        <div style="height: 280px;">
            <canvas id="grafico-ppc"></canvas>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Semana</th>
                        <th class="text-center">Comprometidas</th>
                        <th class="text-center">Concluídas no Prazo</th>
                        <th class="text-center">PPC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->ppcPorSemana as $p)
                    <tr>
                        <td>{{ $p['semana_label'] }}</td>
                        <td class="text-center">{{ $p['comprometidas'] }}</td>
                        <td class="text-center">{{ $p['concluidas_no_prazo'] }}</td>
                        <td class="text-center">{{ $p['ppc_percentual'] }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted text-center py-4 mb-0">Nenhuma semana comprometida (Plano Semanal) já fechada dentro do intervalo selecionado.</p>
        @endif
    </div>
</div>

<div class="row">
    {{-- =========================================================================
         POR PILAR LEAN
         ========================================================================= --}}
    <div class="col-12 col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Restrições por Pilar Lean</h6></div>
            <div class="card-body">
                @if(count($this->porPilar) > 0)
                <div style="height: 240px;">
                    <canvas id="grafico-por-pilar"></canvas>
                </div>
                @else
                <p class="text-muted text-center py-4 mb-0">Nenhuma restrição categorizada por pilar.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- =========================================================================
         PRONTIDÃO POR DISCIPLINA
         ========================================================================= --}}
    <div class="col-12 col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Itens de Prontidão por Disciplina</h6></div>
            <div class="card-body">
                @if(count($this->prontidaoPorDisciplina) > 0)
                <div style="height: 240px;">
                    <canvas id="grafico-prontidao-disciplina"></canvas>
                </div>
                @else
                <div class="alert alert-info d-flex gap-2 align-items-center mb-0">
                    <i class="bx bx-info-circle fs-5"></i>
                    <div>
                        Nenhum item de prontidão configurado ou instanciado ainda nesta obra.
                        <a href="{{ route('cadastros.itens-prontidao') }}" class="ms-1">Configurar &rarr;</a>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- =========================================================================
         DISTRIBUIÇÃO DE RISCO (P×I)
         ========================================================================= --}}
    <div class="col-12 col-lg-4">
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0">Distribuição de Risco (P×I)</h6>
                <a href="{{ route('radar.matriz') }}" wire:navigate class="small">Matriz &rarr;</a>
            </div>
            <div class="card-body">
                @if(collect($this->riscoDistribuicao)->sum('total') > 0)
                <div style="height: 240px;">
                    <canvas id="grafico-risco-distribuicao"></canvas>
                </div>
                @else
                <p class="text-muted text-center py-4 mb-0">Nenhuma restrição com P×I preenchido.</p>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- =========================================================================
     POR CATEGORIA (tabela)
     ========================================================================= --}}
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Restrições por Categoria</h6></div>
    <div class="card-body">
        @if(count($this->porCategoria) > 0)
        <div style="height: 260px;">
            <canvas id="grafico-por-categoria"></canvas>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Abertas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->porCategoria as $c)
                    <tr>
                        <td>{{ $c['categoria'] }}</td>
                        <td class="text-center">{{ $c['total'] }}</td>
                        <td class="text-center">{{ $c['abertas'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted text-center py-4 mb-0">Nenhuma restrição encontrada com os filtros atuais.</p>
        @endif
    </div>
</div>

{{-- =========================================================================
     TEMPO MÉDIO DE RESOLUÇÃO
     ========================================================================= --}}
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Tempo Médio de Resolução por Categoria</h6></div>
    <div class="card-body">
        @if(count($this->tempoMedioResolucao['porCategoria']) > 0)
        <div style="height: 220px;">
            <canvas id="grafico-tempo-resolucao"></canvas>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th class="text-center">Média (dias)</th>
                        <th class="text-center">Restrições Resolvidas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->tempoMedioResolucao['porCategoria'] as $t)
                    <tr>
                        <td>{{ $t['categoria'] }}</td>
                        <td class="text-center">{{ $t['mediaDias'] }}</td>
                        <td class="text-center">{{ $t['total'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted text-center py-4 mb-0">Nenhuma restrição resolvida com os filtros atuais.</p>
        @endif
    </div>
</div>

{{-- =========================================================================
     RESTRIÇÕES ATRASADAS
     ========================================================================= --}}
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Restrições Atrasadas (vencidas)</h6></div>
    <div class="card-body">
        @if($this->atrasadas->count() > 0)
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Atividade</th>
                        <th>Descrição</th>
                        <th>Responsável</th>
                        <th>Categoria</th>
                        <th class="text-center">Prazo</th>
                        <th class="text-center">Dias em Atraso</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->atrasadas as $r)
                    <tr class="table-warning">
                        <td>{{ $r->atividade?->nome ?? '—' }}</td>
                        <td>{{ Str::limit($r->descricao, 60) }}</td>
                        <td>
                            @if($r->responsavel)
                            {{ $r->responsavel->first_name }} {{ $r->responsavel->last_name }}
                            @else
                            {{ $r->responsavel_externo ?: '—' }}
                            @endif
                        </td>
                        <td>{{ $r->categoria?->nome ?? '—' }}</td>
                        <td class="text-center">{{ $r->prazo_limite->format('d/m/Y') }}</td>
                        <td class="text-center text-danger fw-bold">{{ (int) $r->prazo_limite->diffInDays(now()) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted text-center py-4 mb-0">Nenhuma restrição atrasada. 🎉</p>
        @endif
    </div>
</div>

{{-- =========================================================================
     CANVA LATERAL DE FILTROS
     ========================================================================= --}}
<div class="canva-filtros-relrestr" :class="filtrosAbertos ? 'canva-filtros-aberto' : ''" x-data="{ filtrosAbertos: false }">
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
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="togBloqRel"
                           wire:model.live="apenasBloqueantes">
                    <label class="form-check-label small" for="togBloqRel">Bloqueantes</label>
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
            <div class="col-12">
                <button class="btn btn-outline-danger btn-sm w-100" wire:click="exportarPdf">
                    <i class="bx bxs-file-pdf me-1"></i>PDF
                </button>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         CSS DO CANVA — indentado de propósito: o parser de single-file
         component do Livewire extrai <style> que começa na coluna 0 pra um
         mecanismo de "CSS escopado" e remove a tag daqui (bug já documentado
         nesta base de código). Renderizado incondicionalmente (nunca atrás
         de um @if), senão o Livewire não extrai o <style> no cold load.
         ========================================================================= --}}
    <style>
    /* Bug de teste manual, 2026-09-02 — `top: 0` fazia o canva cobrir a
       faixa da navbar fixa (0 a 3.875rem, = $navbar-height do tema); como
       o z-index do canva é maior, um clique no sino/perfil/app-grid nessa
       faixa era engolido pelo canva aberto (mesmo bug nos 8 arquivos que
       usam este padrão — ver ⚡restricoes.blade.php pro diagnóstico
       completo). Corrigido começando o canva abaixo da navbar. */
    .canva-filtros-relrestr {
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

    .canva-filtros-relrestr.canva-filtros-aberto {
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

    .canva-filtros-relrestr.canva-filtros-aberto .canva-filtros-aba {
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
        .canva-filtros-relrestr {
            width: 300px;
            right: -300px;
        }

        .canva-filtros-relrestr.canva-filtros-aberto {
            right: 0;
        }
    }
    </style>

</div>

@include('pages.radar._partials.relatorio-grafico-config')

@script
<script>
    let graficoPorResponsavel = null;
    let graficoPorPeriodo = null;
    let graficoPorPilar = null;
    let graficoPorDisciplina = null;
    let graficoStatusGeral = null;
    let graficoPorCategoria = null;
    let graficoTempoResolucao = null;
    let graficoRiscoDistribuicao = null;
    let graficoPpc = null;

    function desenharPorResponsavel(dados) {
        const canvas = document.getElementById('grafico-por-responsavel');
        if (!canvas) { if (graficoPorResponsavel) { graficoPorResponsavel.destroy(); graficoPorResponsavel = null; } return; }
        if (graficoPorResponsavel && graficoPorResponsavel.canvas !== canvas) { graficoPorResponsavel.destroy(); graficoPorResponsavel = null; }

        const labels = dados.map(d => d.nome);
        const abertas = dados.map(d => d.abertas);
        const bloqueantes = dados.map(d => d.bloqueantes);

        if (graficoPorResponsavel) {
            graficoPorResponsavel.data.labels = labels;
            graficoPorResponsavel.data.datasets[0].data = abertas;
            graficoPorResponsavel.data.datasets[1].data = bloqueantes;
            graficoPorResponsavel.update();
            return;
        }

        graficoPorResponsavel = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Em aberto', data: abertas, backgroundColor: '#696cff' },
                    { label: 'Bloqueantes', data: bloqueantes, backgroundColor: '#ff3e1d' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    function desenharPorPeriodo(dados) {
        const canvas = document.getElementById('grafico-por-periodo');
        if (!canvas) { if (graficoPorPeriodo) { graficoPorPeriodo.destroy(); graficoPorPeriodo = null; } return; }
        if (graficoPorPeriodo && graficoPorPeriodo.canvas !== canvas) { graficoPorPeriodo.destroy(); graficoPorPeriodo = null; }

        const labels = dados.map(d => d.label);
        const total = dados.map(d => d.total);
        const resolvidas = dados.map(d => d.resolvidas);

        if (graficoPorPeriodo) {
            graficoPorPeriodo.data.labels = labels;
            graficoPorPeriodo.data.datasets[0].data = total;
            graficoPorPeriodo.data.datasets[1].data = resolvidas;
            graficoPorPeriodo.update();
            return;
        }

        graficoPorPeriodo = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { type: 'bar', label: 'Restrições', data: total, backgroundColor: '#a5a8f5' },
                    { type: 'line', label: 'Resolvidas', data: resolvidas, borderColor: '#71dd37', backgroundColor: '#71dd37', tension: 0.3 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    function desenharPorPilar(dados) {
        const canvas = document.getElementById('grafico-por-pilar');
        if (!canvas) { if (graficoPorPilar) { graficoPorPilar.destroy(); graficoPorPilar = null; } return; }
        if (graficoPorPilar && graficoPorPilar.canvas !== canvas) { graficoPorPilar.destroy(); graficoPorPilar = null; }

        const labels = dados.map(d => d.label);
        const totais = dados.map(d => d.total);
        const cores = dados.map(d => d.cor);

        if (graficoPorPilar) {
            graficoPorPilar.data.labels = labels;
            graficoPorPilar.data.datasets[0].data = totais;
            graficoPorPilar.data.datasets[0].backgroundColor = cores;
            graficoPorPilar.update();
            return;
        }

        graficoPorPilar = new Chart(canvas, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: totais, backgroundColor: cores }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    function desenharStatusGeral(dados) {
        const canvas = document.getElementById('grafico-status-geral');
        if (!canvas) { if (graficoStatusGeral) { graficoStatusGeral.destroy(); graficoStatusGeral = null; } return; }
        if (graficoStatusGeral && graficoStatusGeral.canvas !== canvas) { graficoStatusGeral.destroy(); graficoStatusGeral = null; }

        const labels = dados.map(d => d.status);
        const totais = dados.map(d => d.total);
        const cores = dados.map(d => d.cor);

        if (graficoStatusGeral) {
            graficoStatusGeral.data.labels = labels;
            graficoStatusGeral.data.datasets[0].data = totais;
            graficoStatusGeral.data.datasets[0].backgroundColor = cores;
            graficoStatusGeral.update();
            return;
        }

        graficoStatusGeral = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{ label: 'Restrições', data: totais, backgroundColor: cores }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } },
            },
        });
    }

    function desenharPorCategoria(dados) {
        const canvas = document.getElementById('grafico-por-categoria');
        if (!canvas) { if (graficoPorCategoria) { graficoPorCategoria.destroy(); graficoPorCategoria = null; } return; }
        if (graficoPorCategoria && graficoPorCategoria.canvas !== canvas) { graficoPorCategoria.destroy(); graficoPorCategoria = null; }

        const labels = dados.map(d => d.categoria);
        const totais = dados.map(d => d.total);
        const abertas = dados.map(d => d.abertas);

        if (graficoPorCategoria) {
            graficoPorCategoria.data.labels = labels;
            graficoPorCategoria.data.datasets[0].data = totais;
            graficoPorCategoria.data.datasets[1].data = abertas;
            graficoPorCategoria.update();
            return;
        }

        graficoPorCategoria = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Total', data: totais, backgroundColor: '#a5a8f5' },
                    { label: 'Abertas', data: abertas, backgroundColor: '#ff3e1d' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    function desenharTempoResolucao(dados) {
        const canvas = document.getElementById('grafico-tempo-resolucao');
        if (!canvas) { if (graficoTempoResolucao) { graficoTempoResolucao.destroy(); graficoTempoResolucao = null; } return; }
        if (graficoTempoResolucao && graficoTempoResolucao.canvas !== canvas) { graficoTempoResolucao.destroy(); graficoTempoResolucao = null; }

        const labels = dados.map(d => d.categoria);
        const medias = dados.map(d => d.mediaDias);

        if (graficoTempoResolucao) {
            graficoTempoResolucao.data.labels = labels;
            graficoTempoResolucao.data.datasets[0].data = medias;
            graficoTempoResolucao.update();
            return;
        }

        graficoTempoResolucao = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{ label: 'Média (dias)', data: medias, backgroundColor: '#ffab00' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: { x: { beginAtZero: true } },
                plugins: { legend: { display: false } },
            },
        });
    }

    function desenharRiscoDistribuicao(dados) {
        const canvas = document.getElementById('grafico-risco-distribuicao');
        if (!canvas) { if (graficoRiscoDistribuicao) { graficoRiscoDistribuicao.destroy(); graficoRiscoDistribuicao = null; } return; }
        if (graficoRiscoDistribuicao && graficoRiscoDistribuicao.canvas !== canvas) { graficoRiscoDistribuicao.destroy(); graficoRiscoDistribuicao = null; }

        const labels = dados.map(d => d.label);
        const totais = dados.map(d => d.total);
        const cores = dados.map(d => d.cor);

        if (graficoRiscoDistribuicao) {
            graficoRiscoDistribuicao.data.labels = labels;
            graficoRiscoDistribuicao.data.datasets[0].data = totais;
            graficoRiscoDistribuicao.data.datasets[0].backgroundColor = cores;
            graficoRiscoDistribuicao.update();
            return;
        }

        graficoRiscoDistribuicao = new Chart(canvas, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: totais, backgroundColor: cores }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    function desenharPorDisciplina(dados) {
        const canvas = document.getElementById('grafico-prontidao-disciplina');
        if (!canvas) { if (graficoPorDisciplina) { graficoPorDisciplina.destroy(); graficoPorDisciplina = null; } return; }
        if (graficoPorDisciplina && graficoPorDisciplina.canvas !== canvas) { graficoPorDisciplina.destroy(); graficoPorDisciplina = null; }

        const labels = dados.map(d => d.disciplina);
        const percentuais = dados.map(d => d.percentual);

        if (graficoPorDisciplina) {
            graficoPorDisciplina.data.labels = labels;
            graficoPorDisciplina.data.datasets[0].data = percentuais;
            graficoPorDisciplina.update();
            return;
        }

        graficoPorDisciplina = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{ label: '% concluído', data: percentuais, backgroundColor: '#71dd37' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: { x: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + '%' } } },
                plugins: { legend: { display: false } },
            },
        });
    }

    function desenharPpc(dados) {
        const canvas = document.getElementById('grafico-ppc');
        if (!canvas) { if (graficoPpc) { graficoPpc.destroy(); graficoPpc = null; } return; }
        if (graficoPpc && graficoPpc.canvas !== canvas) { graficoPpc.destroy(); graficoPpc = null; }

        const labels = dados.map(d => d.semana_label);
        const percentuais = dados.map(d => d.ppc_percentual);
        const cores = dados.map(d => d.cor);

        if (graficoPpc) {
            graficoPpc.data.labels = labels;
            graficoPpc.data.datasets[0].data = percentuais;
            graficoPpc.data.datasets[0].backgroundColor = cores;
            graficoPpc.update();
            return;
        }

        graficoPpc = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{ label: 'PPC (%)', data: percentuais, backgroundColor: cores }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + '%' } } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            afterLabel: (ctx) => {
                                const d = dados[ctx.dataIndex];
                                return `${d.concluidas_no_prazo} de ${d.comprometidas} comprometidas`;
                            },
                        },
                    },
                },
            },
        });
    }

    function desenharTodosGraficos(dados) {
        desenharPorResponsavel(dados.porResponsavel);
        desenharPorPeriodo(dados.porPeriodo);
        desenharPorPilar(dados.porPilar);
        desenharPorDisciplina(dados.prontidaoPorDisciplina);
        desenharStatusGeral(dados.statusGeral);
        desenharPorCategoria(dados.porCategoria);
        desenharTempoResolucao(dados.tempoMedioResolucao);
        desenharRiscoDistribuicao(dados.riscoDistribuicao);
        desenharPpc(dados.ppcPorSemana);
    }

    // Mesmo bug/fix já documentado em ⚡curvas.blade.php: numa navegação
    // wire:navigate o(s) <canvas> podem ainda não existir no DOM no
    // instante em que este bloco roda — requestAnimationFrame com retry
    // limitado espera o morph terminar antes de desistir e desenhar mesmo
    // assim.
    (function aguardarCanvas(tentativas = 10) {
        if (document.getElementById('grafico-por-responsavel') || tentativas <= 0) {
            desenharTodosGraficos(@json($this->dadosGraficos()));
            return;
        }
        requestAnimationFrame(() => aguardarCanvas(tentativas - 1));
    })();

    $wire.on('relatorio-atualizado', ({ dados }) => desenharTodosGraficos(dados));
</script>
@endscript

</div>
