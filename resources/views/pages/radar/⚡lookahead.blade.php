<?php

use App\Actions\Atividade\AnexarArquivoAtividade;
use App\Actions\Atividade\RemoverAnexoAtividade;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemAtividade;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Exports\LookaheadExport;
use App\Models\Atividade;
use App\Notifications\PlanoSemanalGeradoNotification;
use App\Models\AtividadeAnexo;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\AvancoPeriodo;
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
use App\Services\AvancoAtividade;
use App\Services\CurvaAvanco;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
  use ExecutaComTransacaoSegura, WithFileUploads, \App\Support\Concerns\LidaComReaplicacaoLicao;

  // Mesmo formato "MÊS/AA" já usado em ⚡curvas.blade.php::dadosGraficoCurvaS()
  // e ⚡dashboard.blade.php::formatarPeriodoPt() — duplicado aqui por
  // convenção do projeto (helper pequeno por arquivo, não compartilhado via trait).
  private const MESES_PT = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

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
  public ?string $modalBaselineId = null; // Baseline selecionada só pra Curva S do popup — independente do $linhaBaseId da página
  public string $modalGranularidade = 'semanal'; // Escala da Curva S do popup: 'semanal' | 'mensal'
  public ?string $modalTendenciaImportacaoId = null; // Tendência/Avanço selecionada só pra Curva S do popup — independente do $tendenciaImportacaoId da página (Ciclo 17, A.4)
  public $novoAnexo = null; // A.7.2 — upload de anexo PDF do popup, via WithFileUploads

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

  /**
   * Importações elegíveis pra "Tendência" — só as que gravam
   * Realizado/Tendência (seção Relatórios → Importar Avanço), nunca uma
   * importação Baseline pura (essa é escopo exclusivo do seletor de Linha
   * de Base). Mesmo filtro por tipo de `CurvaAvanco::resolverImportacaoId()`.
   */
  #[Computed]
  public function importacoesDisponiveis()
  {
    return CronogramaImportacao::where('obra_id', $this->obra->id)
      ->whereIn('tipo', [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value])
      ->orderByDesc('importado_em')
      ->orderByDesc('id')
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

  /**
   * Correção pós-QA (Ciclo 17) — fonte ÚNICA de verdade pra "o Lookahead
   * está operacional nesta obra": uma CronogramaImportacao (baseline,
   * avanço, ou qualquer outro histórico) NUNCA é, sozinha, uma Linha de
   * Base operacional — só uma LinhaBase salva/ativa (ver App\Models\LinhaBase,
   * unique(obra_id, cronograma_importacao_id), SoftDeletes) representa a
   * referência de planejamento formal escolhida pelo usuário. Todo ponto do
   * componente que decide se mostra tabela/datas/%Peso/Previsto/Realizado/
   * Tendência/Curva S consulta ESTA condição, nunca uma checagem paralela
   * (`count()`/`exists()` duplicado) nem um fallback pra última importação/
   * campos ao vivo da Atividade/último Report.
   */
  #[Computed]
  public function temLinhaBaseAtiva(): bool
  {
    return $this->linhasBase->isNotEmpty();
  }

  /**
   * Ciclo 17, A.8.HARDENING — resolução central e ÚNICA de "qual
   * CronogramaImportacao um ID cru de tendência representa de verdade".
   * $tendenciaImportacaoId/$modalTendenciaImportacaoId são propriedades
   * Livewire PÚBLICAS (client-controlled) — nunca podem virar referência
   * temporal sem antes provar pertencer a importacoesDisponiveis() (já
   * obra-scoped E tipo Avanco/Ambos-scoped, 0 query nova — mesmo princípio
   * já aplicado em cronogramaImportacaoIdDaBaseline() pra LinhaBase).
   *
   * ID vazio/null → fallback legítimo (mais recente elegível da obra).
   * ID truthy mas inválido (importação de outra obra, de outro tenant,
   * Baseline pura, ou inexistente) → NEUTRALIZA pra null — nunca cai
   * silenciosamente pro fallback (isso esconderia a manipulação atrás de
   * um resultado "normal") nem usa a importação alheia. Mesma decisão já
   * tomada por cronogramaImportacaoIdDaBaseline(): um ID inválido nunca é
   * tratado como "sem seleção".
   */
  private function resolverImportacaoTendencia(?string $idSelecionado): ?CronogramaImportacao
  {
    if ($idSelecionado) {
      return $this->importacoesDisponiveis->firstWhere('id', $idSelecionado);
    }

    return $this->importacoesDisponiveis->first();
  }

  /** Importação sendo tratada como "tendência" DA PÁGINA — a selecionada (validada), ou a mais recente (Avanço/Ambos). */
  #[Computed]
  public function importacaoTendenciaAtual(): ?CronogramaImportacao
  {
    return $this->resolverImportacaoTendencia($this->tendenciaImportacaoId);
  }

  /**
   * ID da importação efetivamente usada como "Tendência" DA PÁGINA — mesma
   * semântica de sempre (seleção explícita válida > mais recente Avanço/
   * Ambos > nenhuma), usada por atividades() (tabela principal) e, a partir
   * do Ciclo 17 A.4, só como DEFAULT inicial do popup (verAtividade()) —
   * nunca lida ao vivo pelo popup depois de aberto (ver
   * modalTendenciaIdEfetiva()). Ciclo 17, A.8.HARDENING: nunca mais lê
   * $this->tendenciaImportacaoId bruto — delega 100% pra
   * importacaoTendenciaAtual(), que já valida contra importacoesDisponiveis().
   */
  private function tendenciaIdEfetiva(): ?string
  {
    return $this->importacaoTendenciaAtual?->id;
  }

  /**
   * Ciclo 17, A.4 — equivalente a tendenciaIdEfetiva(), mas para a seleção
   * MODAL: seleção explícita do popup ($modalTendenciaImportacaoId) > mais
   * recente Avanço/Ambos da obra > nenhuma. Deliberadamente NÃO usa
   * $tendenciaImportacaoId/importacaoTendenciaAtual() (que são da página) —
   * trocar a tendência dentro do popup nunca deve depender, nem indiretamente,
   * da seleção da tabela (independência exigida pelo produto) — por isso
   * chama resolverImportacaoTendencia() de novo, com o ID do popup, em vez
   * de reaproveitar o computed da página. Ciclo 17, A.8.HARDENING: agora
   * passa pela MESMA validação central que a página usa (nunca mais aceita
   * $modalTendenciaImportacaoId bruto).
   */
  private function modalTendenciaIdEfetiva(): ?string
  {
    return $this->resolverImportacaoTendencia($this->modalTendenciaImportacaoId)?->id;
  }

  /**
   * Sem nenhuma importação de Avanço (seção Relatórios → Importar Avanço)
   * pra esta obra, "Início"/"Término" (tendência) não tem de onde vir —
   * mostrado como N/A na tabela, em vez de cair silenciosamente pros
   * campos ao vivo (que na real refletem só a última importação de
   * QUALQUER tipo, não necessariamente uma de avanço).
   */
  #[Computed]
  public function temImportacaoAvanco(): bool
  {
    return $this->importacoesDisponiveis->isNotEmpty();
  }

  #[Computed]
  public function linhaBaseSelecionada(): ?LinhaBase
  {
    if (!$this->linhaBaseId) {
      return null;
    }

    return $this->linhasBase->firstWhere('id', $this->linhaBaseId);
  }

  /**
   * Ciclo 17, A.6 — resolve um `LinhaBase.id` (seleção da tabela OU do
   * popup, cada chamador passa a sua) pro `cronograma_importacao_id` que
   * de fato alimenta `avanco_periodos` — mesma resolução já feita inline
   * em modalCurvaAtividade() (linha ~1250), extraída aqui só pra reuso
   * pelo % Peso (tabela e popup), sem duplicar a leitura de
   * `$this->linhasBase` (já em memória, 0 query nova). Sem seleção
   * explícita, cai pra `linhasBase->first()` — MESMO fallback que o
   * popup já usa (Ciclo 17, A.4) — decisão deliberada de reaproveitar
   * essa convenção já estabelecida em vez de inventar uma segunda regra
   * de fallback só pro % Peso.
   */
  private function cronogramaImportacaoIdDaBaseline(?string $linhaBaseIdSelecionada): ?string
  {
    $linhaBaseId = $linhaBaseIdSelecionada ?: $this->linhasBase->first()?->id;

    return $linhaBaseId ? $this->linhasBase->firstWhere('id', $linhaBaseId)?->cronograma_importacao_id : null;
  }

  /**
   * Ciclo 17, A.6 — HH Previsto total do projeto inteiro (obra toda, não
   * escopado por pacote — diferente de PacoteTrabalho::totalHhBaseline(),
   * que exclui atividades órfãs sem pacote) na baseline efetiva da
   * TABELA ($linhaBaseId). Fonte: avanco_periodos, série Previsto,
   * granularidade Mensal (evita contar em dobro — a importação grava o
   * mesmo HH faseado em Semanal E Mensal simultaneamente). Denominador
   * do % Peso de cada atividade da tabela.
   */
  #[Computed]
  public function totalHhPrevistoProjeto(): float
  {
    $importacaoId = $this->cronogramaImportacaoIdDaBaseline($this->linhaBaseId);
    if (!$importacaoId) {
      return 0.0;
    }

    return (float) AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
      ->where('serie', SerieAvanco::Previsto->value)
      ->where('granularidade', GranularidadePeriodo::Mensal->value)
      ->sum('horas');
  }

  /** Equivalente a totalHhPrevistoProjeto(), mas pra baseline efetiva do POPUP ($modalBaselineId) — independente da tabela. */
  #[Computed]
  public function modalTotalHhPrevistoProjeto(): float
  {
    $importacaoId = $this->cronogramaImportacaoIdDaBaseline($this->modalBaselineId);
    if (!$importacaoId) {
      return 0.0;
    }

    return (float) AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
      ->where('serie', SerieAvanco::Previsto->value)
      ->where('granularidade', GranularidadePeriodo::Mensal->value)
      ->sum('horas');
  }

  /**
   * Ciclo 17, A.6 — % Peso da atividade aberta no popup: HH Previsto da
   * própria atividade ÷ HH Previsto do projeto inteiro, ambos na mesma
   * baseline efetiva do popup. Computed SEPARADO de modalCurvaAtividade()
   * de propósito — nunca invalidado por updatedModalTendenciaImportacaoId()
   * nem updatedModalGranularidade(), então trocar tendência/escala do
   * gráfico nunca recalcula nem afeta o Peso (só a curva/datas mudam).
   * Distingue "sem nenhum registro de HH Previsto pra essa atividade"
   * (COUNT=0 → null → exibido "—") de "registro(s) somando zero" (COUNT>0,
   * SUM=0 → 0,0% de verdade) via COUNT explícito, nunca `?? 0`.
   */
  #[Computed]
  public function modalPeso(): ?float
  {
    // Correção pós-QA (Ciclo 17) — defesa em profundidade: mesma regra
    // central de temLinhaBaseAtiva(), reforçada aqui porque este computed
    // não depende só de atividades()/verAtividade() (que já bloqueiam o
    // caminho normal) — $modalBaselineId/$modalAtividadeId são propriedades
    // públicas Livewire, manipuláveis direto.
    if (!$this->temLinhaBaseAtiva) {
      return null;
    }

    $atividade = $this->atividadeDetalhe['atividade'] ?? null;
    $importacaoId = $this->cronogramaImportacaoIdDaBaseline($this->modalBaselineId);

    if (!$atividade || !$importacaoId) {
      return null;
    }

    $agregado = AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
      ->where('serie', SerieAvanco::Previsto->value)
      ->where('granularidade', GranularidadePeriodo::Mensal->value)
      ->where('atividade_id', $atividade->id)
      ->selectRaw('COUNT(*) as total_registros, SUM(horas) as total_horas')
      ->first();

    if (!$agregado || (int) $agregado->total_registros === 0) {
      return null;
    }

    $totalProjeto = $this->modalTotalHhPrevistoProjeto;

    return $totalProjeto > 0 ? round((float) $agregado->total_horas / $totalProjeto * 100, 1) : null;
  }

  // =========================================================================
  // COMPUTED — LISTAGEM PRINCIPAL
  // =========================================================================

  #[Computed]
  public function atividades()
  {
    // Correção pós-QA (Ciclo 17) — regra central: Lookahead operacional
    // exige pelo menos 1 LinhaBase ativa da obra (temLinhaBaseAtiva()).
    // Retorno cedo ANTES de qualquer query (atividades, snapshots,
    // AvancoPeriodo, itens de prontidão) — sem isso, os fallbacks de baixo
    // (campos ao vivo da Atividade, tendência resolvida só por
    // CronogramaImportacao) deixavam o Lookahead "parecer configurado"
    // mesmo sem nenhuma LinhaBase salva.
    if (!$this->temLinhaBaseAtiva) {
      return collect();
    }

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
        'anexos',
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

    // Ciclo 18, Etapa 18.4.CORREÇÃO — "pronta" deixou de ser reimplementada
    // inline aqui (restrições==0 + checklist, sem nenhuma noção de GED) e
    // passou a ler o conjunto canônico de `Atividade::scopeProntas()` — a
    // MESMA regra usada por ⚡plano-semanal.blade.php/Central de Prontidão/
    // `Atividade::estaPronta()` (achado C da auditoria: esta reimplementação
    // permitia a tabela/popup/"Gerar Plano Semanal" considerarem prontas
    // atividades que a Central já classificava como bloqueadas por GED).
    // 1 única query em lote sobre o conjunto já filtrado — nunca por linha.
    $idsProntos = Atividade::query()
      ->where('obra_id', $this->obra->id)
      ->whereIn('id', $atividades->pluck('id'))
      ->prontas()
      ->pluck('id');

    // Importação de Avanço efetiva: a escolhida no filtro, ou por padrão a
    // mais recente elegível (ver importacaoTendenciaAtual() — já filtrada
    // por tipo Avanço/Ambos). Só busca snapshot quando existe alguma —
    // sem nenhuma importação de Avanço pra obra, não há de onde vir
    // "Início"/"Término" (tendência): fica null (exibido como N/A),
    // nunca cai pros campos ao vivo da Atividade (que podem ter sido só
    // a última importação de Linha de Base, nunca de Avanço).
    $tendenciaIdEfetivo = $this->tendenciaIdEfetiva();
    $snapshotsTendencia = $tendenciaIdEfetivo
      ? AtividadeSnapshot::where('cronograma_importacao_id', $tendenciaIdEfetivo)
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

    // Ciclo 17, A.6 — % Peso: mesma baseline efetiva da tabela
    // ($linhaBaseId, com o fallback de cronogramaImportacaoIdDaBaseline()),
    // 1 query em lote (nunca por linha) pra HH Previsto de cada atividade
    // visível, cruzada com totalHhPrevistoProjeto() (já cacheado). Ausência
    // de chave no map = sem nenhum registro de HH Previsto pra essa
    // atividade (peso null/"—"); presença com soma 0 = 0,0% de verdade.
    //
    // Ciclo 17, A.8 — a partir desta correção, a mesma query em lote
    // (Previsto) e a de Realizado passam por App\Services\AvancoAtividade,
    // fonte canônica ÚNICA reaproveitada também por modalCurvaAtividade()
    // — elimina a duas-implementações-da-mesma-regra que fazia tabela e
    // popup poderem divergir pra mesma atividade/Baseline/Avanço (ver
    // docblock do serviço: granularidade sempre Mensal — nunca a escolha
    // de visualização do gráfico — e nunca aplica CurvaAjuste, que não tem
    // nenhum conceito de escopo por atividade no modelo). Nenhuma mudança
    // de comportamento pro % Peso: mesmo SQL, só relocado.
    $importacaoIdBaselineTabela = $this->cronogramaImportacaoIdDaBaseline($this->linhaBaseId);
    $totalHhProjeto = $this->totalHhPrevistoProjeto;
    $avancoAtividade = app(AvancoAtividade::class);
    $hhPrevistoPorAtividade = $avancoAtividade->hhPrevistoEmLote($atividades->pluck('id'), $importacaoIdBaselineTabela);
    $hhRealizadoPorAtividade = $avancoAtividade->hhRealizadoEmLote($atividades->pluck('id'), $tendenciaIdEfetivo);

    $hoje = now()->startOfDay();
    $fimJanela = now()
      ->startOfDay()
      ->addDays($this->janelaDias);

    return $atividades
      ->map(function ($at) use ($totalItens, $itensOkMap, $snapshotsTendencia, $snapshotsBaseline, $tendenciaIdEfetivo, $hhPrevistoPorAtividade, $hhRealizadoPorAtividade, $totalHhProjeto, $avancoAtividade, $hoje, $fimJanela, $idsProntos) {
        if ($tendenciaIdEfetivo) {
          $snap = $snapshotsTendencia->get($at->id);
          $inicioTend = $snap?->inicio_planejado;
          $terminoTend = $snap?->data_termino;
        } else {
          // Nenhuma importação de Avanço pra obra — nada de onde vir
          // "tendência" de verdade (ver temImportacaoAvanco()). N/A na
          // tabela; o filtro de janela abaixo cai pra baseline sozinho.
          $inicioTend = null;
          $terminoTend = null;
        }

        if ($this->linhaBaseId) {
          $snapB = $snapshotsBaseline->get($at->id);
          $inicioBase = $snapB?->baseline_inicio;
          $terminoBase = $snapB?->baseline_termino;
        } else {
          $inicioBase = $at->baseline_inicio;
          $terminoBase = $at->baseline_termino;
        }

        // Fonte "Tendência" sem nenhuma importação de Avanço disponível:
        // o filtro de janela cai automaticamente pra Linha de Base (a
        // única fonte de data que sempre existe) — decisão explícita do
        // usuário, pra não esconder atividades de obras que nunca
        // fizeram Importar Avanço. A coluna exibida continua N/A.
        $inicioJanela = $this->fonteData === 'baseline' ? $inicioBase : ($inicioTend ?? $inicioBase);
        $terminoJanela = $this->fonteData === 'baseline' ? $terminoBase : ($terminoTend ?? $terminoBase);

        // janelaDias = 0 significa "todo o cronograma" — sem filtro de data.
        $dentroDaJanela =
          $this->janelaDias === 0 ||
          ($inicioJanela && $inicioJanela->between($hoje, $fimJanela)) ||
          ($terminoJanela && $terminoJanela->between($hoje, $fimJanela));

        if (!$dentroDaJanela) {
          return null;
        }

        $itensOk = $totalItens > 0 ? (int) ($itensOkMap->get($at->id) ?? 0) : $totalItens;
        $pronta = $idsProntos->contains($at->id);

        $peso = $hhPrevistoPorAtividade->has($at->id) && $totalHhProjeto > 0
          ? round((float) $hhPrevistoPorAtividade->get($at->id) / $totalHhProjeto * 100, 1)
          : null;

        // Ciclo 17, A.8 — fonte canônica única (App\Services\AvancoAtividade),
        // mesma usada pelo popup (modalCurvaAtividade()) — nunca mais uma
        // fórmula própria da tabela. null quando: sem importação de
        // Avanço/Ambos efetiva; sem HH Previsto > 0 na baseline efetiva
        // (denominador); ou sem nenhum registro Realizado pra essa
        // atividade nessa importação (sem dado, nunca 0% inventado).
        $percentualRealizado = $avancoAtividade->percentualDoMapa($at->id, $hhPrevistoPorAtividade, $hhRealizadoPorAtividade);

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
          'peso' => $peso,
          'percentualRealizado' => $percentualRealizado,
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
    unset($this->totalHhPrevistoProjeto);
    $this->invalidarListagem();
  }
  /**
   * Ciclo 17, A.3 — troca de Baseline no popup é "Tipo B" (a curva muda de
   * verdade): invalida o cache do computed e dispara o redesenho explícito
   * do Chart.js (o <canvas> vive num wire:ignore, então nada nele reage
   * sozinho a uma mudança de propriedade — ver Blade).
   */
  public function updatedModalBaselineId(): void
  {
    unset($this->modalCurvaAtividade, $this->modalTotalHhPrevistoProjeto, $this->modalPeso);
    $this->dispatch('curva-atividade-atualizada', dados: $this->modalCurvaAtividade);
  }
  /** Mesmo motivo de updatedModalBaselineId() — troca de escala também é "Tipo B". */
  public function updatedModalGranularidade(): void
  {
    unset($this->modalCurvaAtividade);
    $this->dispatch('curva-atividade-atualizada', dados: $this->modalCurvaAtividade);
  }
  /**
   * Ciclo 17, A.4 — trocar a tendência/avanço dentro do popup também é
   * "Tipo B": Realizado/Tendência do gráfico E as datas de Tendência do
   * cabeçalho (modalTendenciaSnapshot) mudam juntas, sempre pela MESMA
   * importação selecionada — nunca duas fontes divergentes. NUNCA escreve
   * em $tendenciaImportacaoId (seleção da página) — 100% local ao popup.
   */
  public function updatedModalTendenciaImportacaoId(): void
  {
    unset($this->modalCurvaAtividade, $this->modalTendenciaSnapshot);
    $this->dispatch('curva-atividade-atualizada', dados: $this->modalCurvaAtividade);
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
    // o usuário achando que "sumiu" sem explicação. Correção pós-QA (Ciclo
    // 17, ressalva da auditoria): a criação manual é estruturalmente
    // independente de LinhaBase (nunca bloqueada), mas sem nenhuma
    // LinhaBase ativa a tabela operacional está SEMPRE vazia por definição
    // — "ajuste os filtros" seria enganoso nesse estado, já que a causa
    // real não tem nada a ver com janela/etapa/frente. Checa
    // temLinhaBaseAtiva() PRIMEIRO, antes de checar visibilidade nos
    // filtros — a lógica antiga (visível vs. fora do filtro) só se aplica
    // quando o Lookahead está de fato operacional.
    if (!$this->temLinhaBaseAtiva) {
      $this->dispatch(
        'show-toast',
        message: 'Atividade criada com sucesso. Ela ficará disponível no Lookahead assim que esta obra possuir uma linha de base ativa.',
        type: 'warning'
      );

      return;
    }

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
    // Correção pós-QA (Ciclo 17) — defesa em profundidade: a tabela nunca
    // oferece nenhuma atividade pra clicar quando não há LinhaBase ativa
    // (atividades() já retorna vazio), mas verAtividade() é um método
    // Livewire público, acionável direto (ex.: manipulação via DevTools),
    // sem passar pela tabela. Sem essa guarda, o popup abriria
    // normalmente (só os computeds de curva/peso ficariam vazios, já
    // gatekeepados acima) — mais coerente simplesmente não abrir o popup
    // operacional nesse estado, mesmo raciocínio de "o Lookahead não
    // existe" pra quem tenta contornar a UI.
    if (!$this->temLinhaBaseAtiva) {
      return;
    }

    // Ciclo 17, A.7.2.CORREÇÃO — isolamento contextual: o popup só abre
    // atividades da MESMA obra do componente ($this->obra), nunca de outra
    // obra do tenant — mesmo que o usuário tenha acesso/permissão lá
    // também (tenant != obra). Guarda de ESCRITA aqui; atividadeDetalhe()
    // e modalAnexos() têm a MESMA guarda na LEITURA, porque
    // $modalAtividadeId é propriedade pública Livewire e pode ser setada
    // diretamente (sem passar por este método).
    Atividade::where('obra_id', $this->obra->id)->findOrFail($atividadeId);

    $this->modalAtividadeId = $atividadeId;
    // Ciclo 17, A.4.CORREÇÃO — o popup HERDA o contexto temporal da página
    // ao abrir, pros dois seletores. Baseline: $linhaBaseId quando a
    // página tem seleção explícita; null (mesmo comportamento default já
    // existente — modalCurvaAtividade() cai pra $this->linhasBase->first())
    // quando não tem — nenhuma lógica de resolução duplicada aqui, só
    // repassa o valor bruto da página. Tendência: mesmo fallback de sempre
    // (tendenciaIdEfetiva(): seleção explícita da página > mais recente
    // Avanço/Ambos > nenhuma). A partir daqui a seleção é 100% local a
    // cada popup: trocar dentro dele nunca escreve de volta em
    // $linhaBaseId/$tendenciaImportacaoId, e reabrir noutra atividade
    // sempre volta a herdar a página, nunca a escolha feita na atividade
    // anterior.
    $this->modalBaselineId = $this->linhaBaseId;
    $this->modalGranularidade = 'semanal';
    $this->modalTendenciaImportacaoId = $this->tendenciaIdEfetiva();
    $this->reset('novoAnexo');
    unset($this->atividadeDetalhe, $this->modalCurvaAtividade, $this->modalTendenciaSnapshot, $this->modalTotalHhPrevistoProjeto, $this->modalPeso, $this->modalAnexos, $this->modalLicoesContextuais);
    // Ciclo 17, A.3 — o <canvas> da Curva S vive dentro de um wire:ignore
    // (ver Blade), então o Livewire nunca mais o desenha sozinho: todo
    // (re)desenho, inclusive o da primeira abertura do popup, passa por
    // este evento explícito. O JS lê o canvas do DOM só depois que este
    // dispatch dispara (Livewire processa dispatches depois do morph do
    // HTML), então o <canvas> já existe quando o listener roda.
    $this->dispatch('curva-atividade-atualizada', dados: $this->modalCurvaAtividade);
  }

  #[Computed]
  public function atividadeDetalhe()
  {
    // Correção pós-QA (Ciclo 17, ressalva da auditoria) — mesma regra
    // central de temLinhaBaseAtiva() aplicada aqui: $modalAtividadeId é
    // propriedade pública Livewire e pode ser manipulada diretamente
    // (bypass de verAtividade(), que já bloqueia no caminho normal). Sem
    // LinhaBase ativa, o popup inteiro (atividade/restrições/comentários/
    // checklist) fica indisponível — não só a curva/%Peso. Guarda ANTES
    // de qualquer query, reaproveitando temLinhaBaseAtiva() (nunca uma
    // checagem paralela).
    if (!$this->temLinhaBaseAtiva) {
      return null;
    }

    if (!$this->modalAtividadeId) {
      return null;
    }

    // Ciclo 17, A.7.2.CORREÇÃO — defesa em profundidade: mesma guarda de
    // obra de verAtividade(), reaplicada aqui porque $modalAtividadeId é
    // propriedade pública Livewire e pode chegar setada por outro caminho.
    $at = Atividade::with([
      // Ciclo 24.CORREÇÃO — 'atividade' precisa vir junto: @can('resolver', $r)
      // no popup (abaixo) chama RestricaoPolicy::resolver(), que sempre lê
      // $restricao->atividade->obra_id como primeira linha — sem essa relação
      // inversa eager-carregada aqui, isso disparava
      // LazyLoadingViolationException (Model::preventLazyLoading() ativo fora
      // de produção) sempre que a atividade tivesse ao menos 1 restrição
      // aberta/em_tratamento/aguardando_terceiros.
      'restricoes' => fn($q) => $q
        ->with([
          'categoria:id,nome',
          'responsavel:id,first_name,last_name',
          'atividade:id,obra_id',
        ])
        ->orderByRaw("FIELD(status,'aberta','em_tratamento','aguardando_terceiros','resolvida')"),
      'frenteTrabalho:id,nome',
      'disciplina:id,nome',
      'comentarios' => fn($q) => $q->with('autor:id,first_name,last_name')->latest(),
      // Ciclo 18, Etapa 18.4.CORREÇÃO — pra exibir quais Documentos de
      // Engenharia bloqueiam esta atividade (mesma fonte canônica de
      // scopeProntas(), nunca uma leitura paralela).
      'documentosEngenharia.latestRevisao.ultimaLiberacao',
      'documentosEngenharia.latestRevisao.statusDocumento',
    ])->where('obra_id', $this->obra->id)->find($this->modalAtividadeId);

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

    // Ciclo 18, Etapa 18.4.CORREÇÃO — mesma fonte canônica de
    // `DocumentoEngenharia::estaLiberadoParaConstrucao()`/`motivoLiberacao()`
    // já usada por `Atividade::scopeProntas()`/Central de Prontidão — nunca
    // uma terceira leitura paralela. Guarda cross-obra (mesma defesa da
    // Central e do scope): documento de outra obra nunca aparece aqui.
    $documentosBloqueantes = $at->documentosEngenharia
      ->filter(fn ($documento) => $documento->obra_id === $at->obra_id)
      ->reject(fn ($documento) => $documento->estaLiberadoParaConstrucao())
      ->map(fn ($documento) => [
        'codigo' => $documento->codigo,
        'revisaoVigente' => $documento->revisaoVigente()?->revisao,
        'motivo' => $documento->motivoLiberacao(),
      ])
      ->values();

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
      'documentosBloqueantes' => $documentosBloqueantes,
    ];
  }

  /**
   * Snapshot de Tendência da atividade do popup — fonte ÚNICA e correta pro
   * bloco "Início/Término (Tendência)" do cabeçalho, nunca
   * `$at->inicio_planejado`/`$at->data_termino` direto (campos AO VIVO,
   * escritos por QUALQUER importação, não necessariamente uma de tendência
   * real).
   *
   * Ciclo 17, A.4: resolvida pela importação MODAL
   * (modalTendenciaIdEfetiva()), não mais pela tendência da página — trocar
   * $modalTendenciaImportacaoId dentro do popup atualiza este snapshot (e as
   * datas do cabeçalho) junto com a curva, sempre pela MESMA importação. Sem
   * nenhuma importação Avanço/Ambos pra obra, `modalTendenciaIdEfetiva()`
   * retorna null e este computed retorna null sem consultar o banco — nunca
   * cai pra baseline. 1 query pontual (por atividade aberta no modal, nunca
   * em lote) quando existe importação de tendência efetiva.
   */
  #[Computed]
  public function modalTendenciaSnapshot(): ?AtividadeSnapshot
  {
    // Correção pós-QA (Ciclo 17) — defesa em profundidade: mesma regra
    // central de temLinhaBaseAtiva() (ver comentário em modalPeso()).
    // Tendência é uma fonte de dado independente de LinhaBase por
    // natureza (modalTendenciaIdEfetiva() só olha CronogramaImportacao),
    // mas o produto exige que ela SUMA junto com o resto do Lookahead
    // quando não há nenhuma LinhaBase ativa — nunca aparecer como se o
    // Lookahead estivesse configurado.
    if (!$this->temLinhaBaseAtiva) {
      return null;
    }

    $atividade = $this->atividadeDetalhe['atividade'] ?? null;
    $tendenciaIdEfetivo = $this->modalTendenciaIdEfetiva();

    if (!$atividade || !$tendenciaIdEfetivo) {
      return null;
    }

    return AtividadeSnapshot::where('cronograma_importacao_id', $tendenciaIdEfetivo)
      ->where('atividade_id', $atividade->id)
      ->first();
  }

  /**
   * Curva S Previsto x Realizado x Tendência da atividade do popup de
   * detalhe — duas referências temporais INDEPENDENTES (Ciclo 17, A.4):
   *
   * Previsto vem da Baseline selecionada no popup ($modalBaselineId, ou a
   * mais recente da obra por padrão) — reaproveita CurvaAvanco::calcular()
   * escopado a UMA atividade (parâmetro atividadeId), mesma lógica de
   * resolução de importação já usada em toda a Curva S da obra/pacote.
   *
   * Realizado e Tendência vêm AMBOS da importação de avanço/tendência
   * selecionada no popup ($modalTendenciaImportacaoId, resolvida via
   * modalTendenciaIdEfetiva() — herda a tendência efetiva da PÁGINA só no
   * momento em que o popup abre, ver verAtividade()) — nunca mais do
   * cronograma_importacao_id do último Report emitido, que governa o
   * Report semanal, não o gráfico temporal do Lookahead (o texto/fluxo de
   * Report em si continua intocado em todo o resto do sistema). Cada série
   * é lida da coluna `serie` de avanco_periodos sem conversão entre elas —
   * uma importação com só Realizado nunca inventa Tendência, e vice-versa
   * (CurvaAvanco::calcular() já retorna [] quando a série pedida não tem
   * linha pra essa importação, nenhuma mudança precisou ser feita nesse
   * serviço).
   *
   * %Previsto/%Realizado exibidos no resumo e o indicador visual (farol)
   * são derivados DESSAS MESMAS séries (último ponto <= hoje / último
   * ponto da série), nunca de um cálculo paralelo — evita divergência
   * entre a curva e os números do resumo.
   */
  /**
   * Ciclo 23, Etapa 23.4 — "Experiência de obras anteriores" desta
   * atividade: lições `Publicada` de OUTRAS obras do tenant relacionadas
   * ao mesmo Material corporativo (via Pacote/Take Off, cadeia
   * determinística do Ciclo 21.1) ou à mesma Disciplina (tenant-wide).
   * Nunca abre sozinho — só computado quando o popup de UMA atividade já
   * está aberto (mesmo padrão de escopo de `modalCurvaAtividade()`, zero
   * risco de N+1 na listagem — `LicoesContextuaisQuery::porAtividade()`
   * já é batch-safe por baixo, testado a 10/100 atividades).
   */
  #[Computed]
  public function modalLicoesContextuais()
  {
    $atividade = $this->atividadeDetalhe['atividade'] ?? null;

    // Checagem via temPermissaoNaObra() (Eloquent), nunca
    // LicaoAprendidaPolicy::viewAny() (HasObraPapel::
    // temPermissaoEmAlgumaObraDoTenant(), que usa DB::table() cru) — mesma
    // decisão/motivo documentado em ⚡estoque.blade.php::
    // licoesContextuaisPorMaterial() desta mesma etapa.
    if (!$atividade || !Auth::user()->temPermissaoNaObra($this->obra->id, 'gestao.licoes-aprendidas', 'ver')) {
      return collect();
    }

    return \App\Support\LicoesAprendidas\LicoesContextuaisQuery::porAtividade($this->obra, $atividade);
  }

  /**
   * Ciclo 23, Etapa 23.5.B (Seção 28) — "quais destas lições já foram
   * reaplicadas NESTA obra?", em lote (1 query, nunca 1 por card) —
   * usado pelo CTA "Registrar reaplicação"/"Reaplicada nesta obra" de
   * cada sugestão do popup.
   */
  #[Computed]
  public function reaplicacoesLicoesContextuais()
  {
    return \App\Support\LicoesAprendidas\ReaplicacaoLicaoQuery::porObraELicoes(
      $this->obra,
      $this->modalLicoesContextuais->pluck('licaoId')
    );
  }

  /**
   * Registra a reaplicação com a obra ATIVA da própria página como
   * destino (nunca um seletor — Seção 20: "não transformar o card em
   * formulário pesado") e a Atividade do popup como contexto opcional
   * (Seção 5).
   */
  public function registrarReaplicacaoAqui(string $licaoId): void
  {
    $atividade = $this->atividadeDetalhe['atividade'] ?? null;
    $contextos = $atividade ? [['tipo' => \App\Enums\TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade->id]] : [];

    if ($this->registrarReaplicacaoLicao($licaoId, $this->obra->id, null, $contextos)) {
      unset($this->reaplicacoesLicoesContextuais);
    }
  }

  /** Wrapper local — chama o método do trait e invalida o computed certo desta tela. */
  public function confirmarAvaliar(): void
  {
    if ($this->confirmarAvaliarReaplicacao()) {
      unset($this->reaplicacoesLicoesContextuais);
    }
  }

  #[Computed]
  public function modalCurvaAtividade(): array
  {
    $atividade = $this->atividadeDetalhe['atividade'] ?? null;

    if (!$atividade) {
      return [];
    }

    $curvaAvanco = app(CurvaAvanco::class);

    // Correção pós-QA (Ciclo 17) — defesa em profundidade: mesma regra
    // central de temLinhaBaseAtiva() (ver comentário em modalPeso()). Sem
    // isso, o Previsto ficaria corretamente vazio (guardado abaixo por
    // $baselineId), mas Realizado/Tendência (resolvidos só por
    // CronogramaImportacao, independente de LinhaBase) continuariam
    // aparecendo — exatamente o bug relatado em QA. Forçar os dois IDs a
    // null (em vez de um `return []` cedo) preserva o FORMATO do array
    // esperado pelo Blade (baseline_id/tem_baseline/tem_tendencia_selecionada/
    // etc. sempre presentes) — o resto do método já trata "sem baseline"/
    // "sem tendência" corretamente através desses dois IDs.
    $baselineId = $this->temLinhaBaseAtiva
      ? ($this->modalBaselineId ?? $this->linhasBase->first()?->id)
      : null;

    // Datas de Início/Término (Linha de Base) exibidas junto do %Previsto
    // PRECISAM vir da MESMA baseline usada pra calcular a curva — nunca do
    // campo "ao vivo" ($at->baseline_inicio), que reflete a importação de
    // baseline mais recente, não necessariamente a LinhaBase selecionada
    // aqui. Sem isso, dava pra ver "% Previsto" > 0 com uma data de início
    // no futuro (datas de uma baseline, % de outra) — mesmo padrão de
    // resolução via snapshot já usado em ⚡linhas-base.blade.php.
    // Correção pós-QA (Ciclo 17) — sem LinhaBase ativa, nem o fallback pro
    // campo ao vivo da Atividade pode aparecer aqui (mesma regra central:
    // Início/Término de Linha de Base nunca aparecem sem uma LinhaBase
    // formal salva).
    $baselineInicio = $this->temLinhaBaseAtiva ? $atividade->baseline_inicio : null;
    $baselineTermino = $this->temLinhaBaseAtiva ? $atividade->baseline_termino : null;
    if ($baselineId) {
      $linhaBaseSelecionada = $this->linhasBase->firstWhere('id', $baselineId);
      $snapshotBaseline = $linhaBaseSelecionada
        ? AtividadeSnapshot::where('cronograma_importacao_id', $linhaBaseSelecionada->cronograma_importacao_id)
          ->where('atividade_id', $atividade->id)
          ->first()
        : null;
      $baselineInicio = $snapshotBaseline?->baseline_inicio ?? $baselineInicio;
      $baselineTermino = $snapshotBaseline?->baseline_termino ?? $baselineTermino;
    }

    $granularidade = GranularidadePeriodo::from($this->modalGranularidade);

    $previsto = $baselineId
      ? $curvaAvanco->calcular(
        $this->obra,
        SerieAvanco::Previsto,
        $granularidade,
        linhaBaseId: $baselineId,
        atividadeId: $atividade->id,
      )
      : [];

    $totalPrevisto = array_sum(array_column($previsto, 'horas'));

    // Correção pós-QA (Ciclo 17) — Tendência/Realizado são resolvidos só
    // por CronogramaImportacao (modalTendenciaIdEfetiva() nunca olha
    // LinhaBase) — é exatamente essa independência que fazia "informações
    // de avanço" aparecerem sem nenhuma LinhaBase salva. Forçar null aqui
    // é o que faz a regra central valer pras duas séries também.
    $tendenciaIdEfetivoModal = $this->temLinhaBaseAtiva ? $this->modalTendenciaIdEfetiva() : null;

    $realizado = $tendenciaIdEfetivoModal
      ? $curvaAvanco->calcular(
        $this->obra,
        SerieAvanco::Realizado,
        $granularidade,
        avancoImportacaoId: $tendenciaIdEfetivoModal,
        atividadeId: $atividade->id,
      )
      : [];
    $realizado = $realizado && $totalPrevisto > 0 ? $curvaAvanco->rebasearPercentual($realizado, $totalPrevisto) : $realizado;

    $tendencia = $tendenciaIdEfetivoModal
      ? $curvaAvanco->calcular(
        $this->obra,
        SerieAvanco::Tendencia,
        $granularidade,
        avancoImportacaoId: $tendenciaIdEfetivoModal,
        atividadeId: $atividade->id,
      )
      : [];
    $tendencia = $tendencia && $totalPrevisto > 0 ? $curvaAvanco->rebasearPercentual($tendencia, $totalPrevisto) : $tendencia;

    $hoje = now()->toDateString();
    $percentualPrevisto = collect($previsto)
      ->filter(fn($p) => $p['periodo_inicio'] <= $hoje)
      ->last()['percentual'] ?? null;

    // Ciclo 17, A.8 — % Realizado do resumo (indicador) usa a MESMA fonte
    // canônica da tabela (App\Services\AvancoAtividade), nunca mais
    // derivado do último ponto da curva Chart.js. Isso corrige a
    // divergência encontrada em auditoria: a curva (`$realizado` acima)
    // continua exatamente como estava — respeitando `$granularidade`
    // (seletor Semanal/Mensal do popup) e aplicando CurvaAjuste via
    // `CurvaAvanco::calcular()`, porque ela é uma REPRESENTAÇÃO VISUAL,
    // não o indicador factual — mas o número resumido no card de %Realizado
    // é sempre Mensal e nunca reflete ajuste manual, exatamente igual à
    // tabela. `$avancoAtividadeModal` reaproveita a mesma classe, chamada
    // aqui com um lote de 1 atividade (nenhuma query nova é necessária pro
    // caso comum — mesmo custo de antes, 2 queries pequenas escopadas a
    // esta única atividade).
    // $baselineId é o id da LinhaBase (linhas_base.id) — o serviço precisa
    // do cronograma_importacao_id que ela aponta, mesma resolução que
    // cronogramaImportacaoIdDaBaseline() já faz pra tabela (aqui inline,
    // porque $baselineId já veio com o fallback de linhasBase->first()
    // aplicado acima, diferente do parâmetro dessa outra função).
    $baselineImportacaoIdModal = $baselineId
      ? $this->linhasBase->firstWhere('id', $baselineId)?->cronograma_importacao_id
      : null;
    $avancoAtividadeModal = app(\App\Services\AvancoAtividade::class);
    $hhPrevistoModal = $avancoAtividadeModal->hhPrevistoEmLote([$atividade->id], $baselineImportacaoIdModal);
    $hhRealizadoModal = $avancoAtividadeModal->hhRealizadoEmLote([$atividade->id], $tendenciaIdEfetivoModal);
    $percentualRealizado = $avancoAtividadeModal->percentualDoMapa($atividade->id, $hhPrevistoModal, $hhRealizadoModal);

    // Achado da investigação (Ciclo 17, A.8): CurvaAjuste não tem nenhum
    // conceito de escopo por atividade — quando a curva acima é calculada
    // com atividadeId (e nenhum outro filtro), CurvaAvanco::calcular()
    // casa incorretamente com ajustes GLOBAIS/obra-inteira feitos na tela
    // Curvas S, aplicando esse valor à curva de QUALQUER atividade
    // individual (bug pré-existente, fora do escopo desta correção — ver
    // docblock de AvancoAtividade). Isso pode fazer a CURVA visual mostrar
    // um valor diferente do indicador factual acima. `$curvaDivergeDoIndicador`
    // sinaliza esse cenário raro pro Blade poder deixar isso explícito ao
    // usuário, em vez de uma divergência silenciosa.
    $percentualRealizadoCurva = collect($realizado)->last()['percentual'] ?? null;
    $curvaDivergeDoIndicador = $percentualRealizado !== null
      && $percentualRealizadoCurva !== null
      && abs($percentualRealizado - $percentualRealizadoCurva) > 0.05;

    // Rótulos formatados por período (chave = periodo_inicio, mesma usada
    // pra casar as 3 séries no gráfico) — mesmo padrão "MÊS/AA" já usado em
    // ⚡curvas.blade.php::dadosGraficoCurvaS()/⚡dashboard.blade.php::formatarPeriodoPt().
    $labels = [];
    foreach ([...$previsto, ...$realizado, ...$tendencia] as $ponto) {
      $labels[$ponto['periodo_inicio']] = $this->formatarLabelPeriodoAtividade($ponto['periodo_inicio'], $granularidade);
    }

    return [
      'baseline_id' => $baselineId,
      'baseline_inicio' => $baselineInicio,
      'baseline_termino' => $baselineTermino,
      'tem_baseline' => (bool) $baselineId,
      // Importação efetivamente usada pra Realizado/Tendência (seleção
      // explícita do popup, ou default) — exposta aqui pro Blade poder
      // marcar a option selecionada no <select> sem chamar
      // modalTendenciaIdEfetiva() (privado) de dentro da view, mesmo
      // padrão já usado por baseline_id acima.
      'tendencia_id' => $tendenciaIdEfetivoModal,
      // Existe uma importação de avanço/tendência selecionada (explícita
      // ou default) — nunca confundir com "essa importação TEM dados de
      // Realizado/Tendência pra esta atividade" (tem_realizado/tem_tendencia
      // abaixo): uma importação Avanco pode ter só uma das duas séries.
      'tem_tendencia_selecionada' => (bool) $tendenciaIdEfetivoModal,
      'tem_realizado' => !empty($realizado),
      'tem_tendencia' => !empty($tendencia),
      'previsto' => $previsto,
      'realizado' => $realizado,
      'tendencia' => $tendencia,
      'labels' => $labels,
      'percentual_previsto' => $percentualPrevisto,
      'percentual_realizado' => $percentualRealizado,
      // Ciclo 17, A.8 — true só no cenário raro documentado acima (ajuste
      // GLOBAL da curva geral do empreendimento coincidindo com período/
      // série/granularidade da curva desta atividade). O Blade usa isso
      // pra deixar explícito que o número do card é o valor canônico
      // (sem ajuste), podendo diferir do formato visual da curva.
      'curva_diverge_do_indicador' => $curvaDivergeDoIndicador,
      'indicador' => is_null($percentualRealizado)
        ? 'neutro'
        : ($percentualRealizado >= ($percentualPrevisto ?? 0) ? 'favoravel' : 'desfavoravel'),
    ];
  }

  /** Mesmo padrão "MÊS/AA" (mensal) / "Sem dd/mm/aaaa" (semanal) já usado em ⚡curvas.blade.php. */
  private function formatarLabelPeriodoAtividade(string $periodoInicio, GranularidadePeriodo $gran): string
  {
    $data = Carbon::parse($periodoInicio);

    return $gran === GranularidadePeriodo::Mensal
      ? self::MESES_PT[$data->month - 1] . '/' . $data->format('y')
      : 'Sem ' . $data->format('d/m/Y');
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
  // ANEXOS (Ciclo 17, A.7.2) — orquestração só: toda regra de storage,
  // validação de conteúdo real e persistência vive em AnexarArquivoAtividade/
  // RemoverAnexoAtividade (A.7.1), nunca duplicada aqui.
  // =========================================================================

  #[Computed]
  public function modalAnexos()
  {
    // Correção pós-QA (Ciclo 17, ressalva da auditoria) — mesma regra
    // central de temLinhaBaseAtiva(), mesmo raciocínio de atividadeDetalhe()
    // logo acima: sem LinhaBase ativa, a EXPOSIÇÃO dos anexos dentro do
    // Lookahead fica bloqueada (nunca o registro/arquivo em si — download
    // protegido por rota própria continua intocado, ver AtividadeAnexoController).
    if (!$this->temLinhaBaseAtiva) {
      return collect();
    }

    if (!$this->modalAtividadeId) {
      return collect();
    }

    // Ciclo 17, A.7.2.CORREÇÃO — mesma guarda de obra de verAtividade()/
    // atividadeDetalhe(): não depende de $modalAtividadeId ter vindo de
    // verAtividade() (propriedade pública, pode ser setada por outro
    // caminho), então filtra a atividade dona do anexo pela obra atual
    // diretamente na query, dentro da MESMA consulta (whereHas vira um
    // WHERE EXISTS — continua sendo 2 queries fixas, nunca N+1).
    return AtividadeAnexo::where('atividade_id', $this->modalAtividadeId)
      ->whereHas('atividade', fn($q) => $q->where('obra_id', $this->obra->id))
      ->with('enviadoPor:id,first_name,last_name')
      ->latest()
      ->get();
  }

  /**
   * Ciclo 17, A.7.2.CORREÇÃO — substitui transacaoSegura() SÓ pra anexos:
   * a auditoria da A.7.2 encontrou que envolver AnexarArquivoAtividade/
   * RemoverAnexoAtividade num DB::transaction() externo introduzia uma
   * janela nova de inconsistência (arquivo já gravado/apagado no
   * filesystem, fora de qualquer controle transacional, enquanto o
   * DELETE/INSERT do banco ainda dependia de um commit que podia falhar
   * DEPOIS que a Action já tinha terminado) — pior ainda no caso da
   * exclusão, onde o resultado possível virava uma row ativa apontando
   * pra um arquivo já apagado. As duas Actions da A.7.1 já têm sua
   * própria estratégia de consistência filesystem/banco (documentada nos
   * respectivos docblocks); este helper preserva só o comportamento de
   * ERRO de transacaoSegura() (autorização/validação sobem normais, erro
   * inesperado vira report()+toast), sem nenhum DB::transaction().
   */
  private function executarAcaoDeAnexo(\Closure $callback): bool
  {
    try {
      $callback();

      return true;
    } catch (AuthorizationException|ValidationException $e) {
      throw $e;
    } catch (\Throwable $e) {
      report($e);
      $this->dispatch('show-toast', message: 'Não foi possível concluir a ação. Tente novamente em instantes.', type: 'error');

      return false;
    }
  }

  public function anexarArquivoAtividade(string $atividadeId): void
  {
    // Validação rápida de UI, sempre igual à validação interna (definitiva)
    // de AnexarArquivoAtividade — nunca diverge, reaproveita a mesma constante.
    $this->validate(
      ['novoAnexo' => 'required|file|mimes:pdf|max:' . AtividadeAnexo::TAMANHO_MAXIMO_KB],
      [],
      ['novoAnexo' => 'arquivo']
    );

    // Ciclo 17, A.7.2.CORREÇÃO — atividade precisa pertencer à obra ATUAL
    // do componente antes de qualquer outra checagem (isolamento
    // contextual: mesmo usuário com 'editar' em outra obra do tenant não
    // pode anexar a uma atividade de lá através do componente desta obra).
    $atividade = Atividade::where('obra_id', $this->obra->id)->findOrFail($atividadeId);
    // CRÍTICO: autorização real aqui, nunca só a UI escondida — mesma
    // permissão (restricoes.lookahead|editar) que já esconde o botão de upload.
    $this->authorize('update', $atividade);

    if (!$this->executarAcaoDeAnexo(fn () => app(AnexarArquivoAtividade::class)->execute($atividade, $this->novoAnexo, Auth::user()))) {
      return;
    }

    $this->reset('novoAnexo');
    unset($this->modalAnexos);
    $this->invalidarListagem();
    $this->dispatch('show-toast', message: 'Anexo adicionado com sucesso.');
  }

  public function removerAnexoAtividade(string $anexoId): void
  {
    // Ciclo 17, A.7.2.CORREÇÃO — o anexo precisa pertencer a uma atividade
    // da obra ATUAL do componente (mesmo raciocínio do upload acima).
    $anexo = AtividadeAnexo::with('atividade')
      ->whereHas('atividade', fn($q) => $q->where('obra_id', $this->obra->id))
      ->findOrFail($anexoId);
    // CRÍTICO: autorização real aqui, nunca só a UI escondida — mesma
    // permissão (restricoes.lookahead|excluir) que já esconde o botão de exclusão.
    $this->authorize('delete', $anexo->atividade);

    if (!$this->executarAcaoDeAnexo(fn () => app(RemoverAnexoAtividade::class)->execute($anexo))) {
      return;
    }

    unset($this->modalAnexos);
    $this->invalidarListagem();
    $this->dispatch('show-toast', message: 'Anexo removido com sucesso.');
  }

  private function formatarTamanhoArquivo(int $bytes): string
  {
    if ($bytes < 1024) {
      return $bytes . ' B';
    }
    $kb = $bytes / 1024;
    if ($kb < 1024) {
      return number_format($kb, 0, ',', '.') . ' KB';
    }
    return number_format($kb / 1024, 1, ',', '.') . ' MB';
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
      'temImportacaoAvanco' => $this->temImportacaoAvanco,
    ]);

    return response()->streamDownload(
      fn() => print $pdf->output(),
      "lookahead-{$this->obra->id}-{$this->janelaDias}dias.pdf"
    );
  }

  public function exportarExcel()
  {
    return Excel::download(
      new LookaheadExport($this->atividades, $this->temImportacaoAvanco),
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
      'temImportacaoAvanco' => $this->temImportacaoAvanco,
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
@if (!$this->temLinhaBaseAtiva)
{{-- Correção pós-QA (Ciclo 17) — regra central: sem nenhuma LinhaBase
     ativa da obra, o Lookahead não é operacional. Importações e todo o
     histórico continuam intactos no banco (nada é apagado por esta tela),
     só a apresentação fica indisponível até o usuário salvar uma Linha de
     Base. Nunca mostrar a tabela vazia/parcial nesse estado — sempre este
     aviso didático. --}}
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bx bx-bookmark display-3 text-muted"></i>
        <h5 class="fw-bold mt-3">Esta obra ainda não possui uma linha de base ativa.</h5>
        <p class="text-muted mb-3">
            Crie uma linha de base a partir de uma importação de cronograma para começar a usar o Lookahead.
        </p>
        @if (\Illuminate\Support\Facades\Auth::user()->temPermissaoNaObra($obra->id, 'obras.linhas_base', 'criar'))
        <a href="{{ route('radar.linhas-base') }}" class="btn btn-primary">
            <i class="bx bx-bookmark-plus me-1"></i>Criar linha de base
        </a>
        @endif
    </div>
</div>
@elseif ($this->atividades->count() > 0)
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
                wire:key="nivel-btn-{{ $nv }}"
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
                <th class="text-center" style="width: 5%;" title="HH Previsto da atividade ÷ HH Previsto do projeto, na Linha de Base selecionada">% Peso</th>
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
                <td colspan="13" style="padding-left: {{ $linha['nivel'] * 24 }}px">
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
                    @if ($at->anexos_count > 0)
                    <span class="badge bg-label-secondary ms-1" style="font-size:.65rem" title="{{ $at->anexos_count }} anexo(s)">
                        <i class="bx bx-paperclip"></i> {{ $at->anexos_count }}
                    </span>
                    @endif
                </td>
                <td><small>{{ $at->disciplina?->nome ?? '—' }}</small></td>
                <td><small>{{ $at->frenteTrabalho?->nome ?? '—' }}</small></td>
                <td class="text-center"><small>{{ !is_null($row['peso']) ? number_format($row['peso'], 1, ',', '.') . '%' : '—' }}</small></td>
                <td class="text-center"><small>{{ $row['inicioBaseline']?->format('d/m/y') ?? '—' }}</small></td>
                <td class="text-center"><small>{{ $row['terminoBaseline']?->format('d/m/y') ?? '—' }}</small></td>
                <td class="text-center"><small>{{ $this->temImportacaoAvanco ? ($row['inicioTendencia']?->format('d/m/y') ?? '—') : 'N/A' }}</small></td>
                <td class="text-center"><small>{{ $this->temImportacaoAvanco ? ($row['terminoTendencia']?->format('d/m/y') ?? '—') : 'N/A' }}</small></td>
                <td class="text-center">
                    @php
                        // Correção pós-QA (Ciclo 17) — NUNCA $at->percentual_concluido (campo
                        // ao vivo, gravado pelo importador mesmo em importação Baseline-only,
                        // sem nenhuma importação de Avanço/Ambos por trás). Fonte única:
                        // $row['percentualRealizado'], calculada em atividades() a partir da
                        // fotografia de avanço efetiva da página — null vira "—", nunca 0%/85%.
                        $pctVal = $row['percentualRealizado'] !== null ? (int) round($row['percentualRealizado']) : null;
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
                    {{-- Ciclo 24 — atividade fisicamente concluída no cronograma
                         tem precedência visual sobre a prontidão prospectiva:
                         nunca mostrar "Não pronta" pra algo que já foi executado,
                         mesmo que ainda existam restrições/checklist pendentes
                         de revisão (ver Central de Prontidão/detalhe da atividade
                         pra essas pendências). --}}
                    @if ($at->status === \App\Enums\StatusAtividade::Concluido)
                        <span class="badge bg-primary">Concluída</span>
                    @else
                        <span class="badge {{ $row['pronta'] ? 'bg-success' : 'bg-warning text-dark' }}">
                            {{ $row['pronta'] ? 'Pronta' : 'Não pronta' }}
                        </span>
                    @endif
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
                // Ciclo 18, Etapa 18.4.CORREÇÃO — deixou de reimplementar a
                // regra (restrições+checklist, sem GED) e passou a chamar a
                // fonte canônica única (Atividade::estaPronta(), que delega
                // pra scopeProntas()) — 1 query extra, aceitável aqui: é uma
                // única atividade (o popup nunca renderiza em loop).
                $atividadePronta = $at->estaPronta();
                $documentosBloqueantesPopup = $detalhe['documentosBloqueantes'];
                $curvaAtividade = $this->modalCurvaAtividade;
                // Ciclo 17, A.2 — snapshot da importação de tendência
                // EFETIVA da página (mesma regra de atividades()), nunca
                // os campos ao vivo de $at.
                $tendenciaSnap = $this->modalTendenciaSnapshot;
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
                        @if ($at->status === \App\Enums\StatusAtividade::Concluido)
                            <span class="badge bg-primary ms-2">Concluída</span>
                        @else
                            <span class="badge {{ $atividadePronta ? 'bg-success' : 'bg-warning text-dark' }} ms-2">
                                {{ $atividadePronta ? 'Pronta' : 'Não pronta' }}
                            </span>
                        @endif
                    </small>
                </div>
                <button type="button" class="btn-close btn-close-white" wire:click="$set('modalAtividadeId', null)"></button>
            </div>

            <div class="px-4 py-3 bg-light border-bottom">
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3 col-lg">
                        <div class="small text-muted">Início (Linha de Base)</div>
                        <h4 class="fw-semibold">{{ $curvaAtividade['baseline_inicio']?->format('d/m/Y') ?? '—' }}</h4>
                    </div>
                    <div class="col-6 col-md-3 col-lg">
                        <div class="small text-muted">Término (Linha de Base)</div>
                        <h4 class="fw-semibold">{{ $curvaAtividade['baseline_termino']?->format('d/m/Y') ?? '—' }}</h4>
                    </div>
                    <div class="col-6 col-md-3 col-lg">
                        <div class="small text-muted">Início (Tendência)</div>
                        <h4 class="fw-semibold">{{ $curvaAtividade['tem_tendencia_selecionada'] ? ($tendenciaSnap?->inicio_planejado?->format('d/m/Y') ?? '—') : 'N/A' }}</h4>
                    </div>
                    <div class="col-6 col-md-3 col-lg">
                        <div class="small text-muted">Término (Tendência)</div>
                        <h4 class="fw-semibold">{{ $curvaAtividade['tem_tendencia_selecionada'] ? ($tendenciaSnap?->data_termino?->format('d/m/Y') ?? '—') : 'N/A' }}</h4>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="small text-muted">% Previsto</div>
                        <h4 class="fw-semibold">
                            {{ $curvaAtividade['percentual_previsto'] !== null ? number_format($curvaAtividade['percentual_previsto'], 0) . '%' : '—' }}
                        </h4>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="small text-muted">% Realizado</div>
                        <h4 class="fw-semibold"
                            @if (! $curvaAtividade['tem_realizado']) title="{{ $curvaAtividade['tem_tendencia_selecionada'] ? 'A importação de avanço selecionada não possui dados de Realizado para esta atividade.' : 'Nenhuma importação de avanço/tendência selecionada.' }}"
                            @elseif ($curvaAtividade['curva_diverge_do_indicador']) title="Valor exato (sem ajustes manuais da curva geral do empreendimento) — pode diferir do formato visual do gráfico abaixo, que reflete ajustes quando existentes."
                            @endif>
                            {{ $curvaAtividade['percentual_realizado'] !== null ? number_format($curvaAtividade['percentual_realizado'], 0) . '%' : '—' }}
                            @if ($curvaAtividade['tem_realizado'] && $curvaAtividade['curva_diverge_do_indicador'])
                            <i class="bx bx-info-circle text-muted" style="font-size: .75rem" title="Valor exato (sem ajustes manuais da curva geral do empreendimento) — pode diferir do formato visual do gráfico abaixo, que reflete ajustes quando existentes."></i>
                            @endif
                        </h4>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="small text-muted">% Peso</div>
                        <h4 class="fw-semibold" title="HH Previsto da atividade ÷ HH Previsto do projeto, na Linha de Base selecionada acima">
                            {{ $this->modalPeso !== null ? number_format($this->modalPeso, 1, ',', '.') . '%' : '—' }}
                        </h4>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="small text-muted">Desempenho</div>
                        <h4 class="fw-semibold" title="{{ match($curvaAtividade['indicador']) {
                            'favoravel' => 'Realizado dentro ou acima do previsto',
                            'desfavoravel' => 'Realizado abaixo do previsto',
                            default => 'Sem dados de realizado suficientes pra avaliar',
                        } }}">
                            @if ($curvaAtividade['indicador'] === 'favoravel') 😊
                            @elseif ($curvaAtividade['indicador'] === 'desfavoravel') 😟
                            @else ⚪
                            @endif
                        </h4>
                    </div>
                </div>
            </div>

            <div class="px-4 py-3 bg-light border-bottom">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <h6 class="fw-bold mb-0"><i class="bx bx-trending-up me-2 text-primary"></i>Curva S da Atividade</h6>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <label class="small text-muted mb-0">Escala:</label>
                        <select class="form-select form-select-sm" style="width:auto" wire:model.live="modalGranularidade">
                            <option value="semanal">Semanal</option>
                            <option value="mensal">Mensal</option>
                        </select>
                        @if ($this->linhasBase->isNotEmpty())
                        <label class="small text-muted mb-0">Baseline:</label>
                        <select class="form-select form-select-sm" style="width:auto" wire:model.live="modalBaselineId">
                            @foreach ($this->linhasBase as $lb)
                            <option value="{{ $lb->id }}" @selected($curvaAtividade['baseline_id'] === $lb->id)>{{ $lb->nome }}</option>
                            @endforeach
                        </select>
                        @endif
                        {{-- Ciclo 17, A.4 — seleção de tendência/avanço 100% local ao
                             popup, independente de $tendenciaImportacaoId (tabela
                             principal): trocar aqui nunca altera a tabela, e
                             fechar/reabrir noutra atividade volta a herdar o
                             contexto da página (ver verAtividade()). Mesma fonte
                             (importacoesDisponiveis(), só Avanço/Ambos) e MESMO
                             rótulo do seletor da página — nunca uma nomenclatura
                             nova. Oculto quando a obra não tem nenhuma importação
                             de avanço/tendência. --}}
                        @if ($this->importacoesDisponiveis->isNotEmpty())
                        <label class="small text-muted mb-0">Tendência:</label>
                        <select class="form-select form-select-sm" style="width:auto" wire:model.live="modalTendenciaImportacaoId">
                            <option value="">Tendência: mais recente</option>
                            @foreach ($this->importacoesDisponiveis as $imp)
                            <option value="{{ $imp->id }}" @selected($curvaAtividade['tendencia_id'] === $imp->id)>
                                Tendência: {{ $imp->importado_em->format('d/m/Y H:i') }} — {{ $imp->arquivo }}
                            </option>
                            @endforeach
                        </select>
                        @endif
                    </div>
                </div>

                @if (! $curvaAtividade['tem_baseline'])
                <div class="alert alert-warning mb-0 py-2">
                    <i class="bx bx-info-circle me-1"></i>Nenhuma Baseline disponível para esta atividade.
                </div>
                @elseif (empty($curvaAtividade['previsto']))
                <div class="alert alert-secondary mb-0 py-2">
                    <i class="bx bx-info-circle me-1"></i>Não há dados suficientes para gerar a Curva S desta atividade.
                </div>
                @else
                {{-- Ciclo 17, A.3 — wire:ignore no menor contêiner possível (só o
                     canvas): Chart.js escreve width/height no <canvas> depois do
                     desenho; sem wire:ignore, qualquer morph do Livewire (mesmo
                     de uma ação Tipo A, tipo marcar checklist/comentário, que
                     nem muda a curva) reconcilia esses atributos contra o HTML
                     recém-renderizado do servidor — que nunca os tem — e ZERA o
                     canvas, apagando o gráfico visualmente. Esse é a causa raiz
                     confirmada do bug relatado. Redesenho passa a ser 100%
                     explícito via evento 'curva-atividade-atualizada' (mesmo
                     padrão já usado em ⚡curvas.blade.php::recarregarPeriodos()),
                     nunca mais implícito via troca de wire:key/x-init. --}}
                <div wire:ignore>
                    <div style="height: 260px">
                        <canvas id="grafico-curva-atividade-popup"></canvas>
                    </div>
                </div>
                @unless ($curvaAtividade['tem_realizado'] || $curvaAtividade['tem_tendencia'])
                <div class="small text-muted mt-2">
                    <i class="bx bx-info-circle me-1"></i>
                    @if (! $curvaAtividade['tem_tendencia_selecionada'])
                    Nenhuma importação de avanço/tendência disponível para esta obra.
                    @else
                    A importação de avanço selecionada não possui dados de Realizado/Tendência para esta atividade.
                    @endif
                </div>
                @endunless
                @endif
            </div>

            {{-- Ciclo 23, Etapa 23.4 — Experiência de obras anteriores (Memória
                 Corporativa). Discreto e informativo, nunca um alerta de risco
                 (nunca aumenta badge/cor de alerta da atividade). Alpine puro
                 pra abrir/fechar (nunca data-bs-toggle="collapse" dentro de
                 conteúdo remontado pelo Livewire, mesmo motivo já documentado
                 em health-check-findings.blade.php). --}}
            @if ($this->modalLicoesContextuais->isNotEmpty())
            <div class="px-4 py-3 border-bottom bg-light" x-data="{ licoesAbertas: false }">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bx bx-bulb-line text-info fs-4"></i>
                        <div>
                            <div class="fw-semibold">Experiência de obras anteriores</div>
                            <div class="small text-muted">
                                @if ($this->modalLicoesContextuais->count() === 1)
                                    1 lição publicada de outra obra pode ser útil neste contexto.
                                @else
                                    {{ $this->modalLicoesContextuais->count() }} lições publicadas de outras obras podem ser úteis neste contexto.
                                @endif
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-info" @click="licoesAbertas = !licoesAbertas">
                        <span x-text="licoesAbertas ? 'Ocultar' : 'Consultar experiências'"></span>
                    </button>
                </div>
                <div x-show="licoesAbertas" x-cloak x-transition class="mt-3">
                    @foreach ($this->modalLicoesContextuais as $sugestao)
                        <div class="border rounded p-3 mb-2 bg-white" wire:key="sugestao-atividade-{{ $sugestao->licaoId }}">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <strong>{{ $sugestao->titulo }}</strong>
                                <span class="badge bg-label-{{ $sugestao->criticidade->cor() }}">{{ $sugestao->criticidade->label() }}</span>
                            </div>
                            <div class="d-flex gap-2 flex-wrap mb-2">
                                <span class="badge bg-label-{{ $sugestao->tipo->cor() }}"><i class="bx {{ $sugestao->tipo->icone() }} me-1"></i>{{ $sugestao->tipo->label() }}</span>
                                <span class="badge bg-label-secondary">{{ $sugestao->areaFuncional->label() }}</span>
                                <span class="badge bg-label-secondary"><i class="bx bx-buildings me-1"></i>{{ $sugestao->obraOrigemNome ?? 'Obra não identificada' }}</span>
                            </div>
                            <p class="mb-2 small">{{ Str::limit($sugestao->situacaoObservada, 220) }}</p>
                            <div class="alert alert-primary py-2 px-3 mb-2 small"><strong>Recomendação:</strong> {{ $sugestao->recomendacaoFutura }}</div>
                            <p class="text-muted small mb-0">
                                <i class="bx bx-info-circle me-1"></i>Por que esta lição apareceu?
                                {{ collect($sugestao->motivos)->map(fn ($m) => $m['motivo']->label().($m['contexto'] ? ": {$m['contexto']}" : ''))->implode(' · ') }}
                            </p>

                            {{-- Ciclo 23, Etapa 23.5.B (Seção 20) --}}
                            @php $reaplicacaoAqui = $this->reaplicacoesLicoesContextuais->get($sugestao->licaoId); @endphp
                            <div class="d-flex align-items-center gap-2 mt-2 pt-2 border-top">
                                @if ($reaplicacaoAqui)
                                    <span class="badge bg-label-success"><i class="bx bx-check me-1"></i>Reaplicada nesta obra</span>
                                    @can('avaliar', $reaplicacaoAqui)
                                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" wire:click="abrirAvaliarReaplicacao('{{ $reaplicacaoAqui->id }}')">
                                            Avaliar resultado
                                        </button>
                                    @endcan
                                @else
                                    @can('registrar', [\App\Models\LicaoAprendidaReaplicacao::class, $obra])
                                        <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" wire:click="registrarReaplicacaoAqui('{{ $sugestao->licaoId }}')">
                                            <i class="bx bx-repost me-1"></i>Registrar reaplicação nesta obra
                                        </button>
                                    @endcan
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

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

                {{-- Ciclo 18, Etapa 18.4.CORREÇÃO — Documentos de Engenharia
                     bloqueantes (vínculo direto, Ciclo 18.1). Mesma fonte
                     canônica de scopeProntas()/Central de Prontidão — só
                     leitura, nenhuma ação aqui (liberar/emitir/desvincular
                     continuam exclusivamente na Lista de Documentos). --}}
                @if ($documentosBloqueantesPopup->isNotEmpty())
                <div class="border-top p-4">
                    <h6 class="fw-bold mb-3 text-danger">
                        <i class="bx bx-file-blank me-2"></i>Documentos de Engenharia Pendentes
                        <span class="badge bg-label-danger ms-1">{{ $documentosBloqueantesPopup->count() }}</span>
                    </h6>
                    <ul class="mb-0 small">
                        @foreach ($documentosBloqueantesPopup as $docBloq)
                        <li>
                            <strong>{{ $docBloq['codigo'] ?? '—' }}</strong>
                            @if ($docBloq['revisaoVigente'])
                            (Rev. {{ $docBloq['revisaoVigente'] }})
                            @endif
                            —
                            {{ $docBloq['motivo'] === 'sem_revisao' ? 'ainda não emitido' : 'revisão vigente não liberada para construção' }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

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

                <div class="border-top p-4">
                    <h6 class="fw-bold mb-3">
                        <i class="bx bx-paperclip me-2 text-secondary"></i>Anexos
                        <span class="badge bg-secondary">{{ $this->modalAnexos->count() }}</span>
                    </h6>

                    @if ($this->modalAnexos->isEmpty())
                    <p class="text-muted small mb-3">Nenhum anexo ainda.</p>
                    @else
                    <div class="mb-3" style="max-height:220px; overflow-y:auto">
                        @foreach ($this->modalAnexos as $anexo)
                        <div class="d-flex align-items-start gap-2 mb-2" wire:key="anexo-{{ $anexo->id }}">
                            <i class="bx bxs-file-pdf text-danger flex-shrink-0" style="font-size:1.1rem"></i>
                            <div class="flex-grow-1">
                                <small class="d-block">{{ $anexo->nome_original }}</small>
                                <small class="text-muted d-block">
                                    {{ $this->formatarTamanhoArquivo($anexo->tamanho_bytes) }}
                                    — {{ $anexo->enviadoPor ? $anexo->enviadoPor->first_name . ' ' . $anexo->enviadoPor->last_name : 'Usuário removido' }}
                                    — {{ $anexo->created_at->format('d/m/Y H:i') }}
                                </small>
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                <a href="{{ route('atividade-anexos.download', $anexo) }}" class="btn btn-xs btn-outline-secondary py-0 px-1" title="Baixar">
                                    <i class="bx bx-download"></i>
                                </a>
                                @can('delete', $at)
                                <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" title="Excluir"
                                        onclick="confirmarAcao(this, {
                                            mensagem: 'Remover este anexo? A ação não pode ser desfeita.',
                                            metodo: 'removerAnexoAtividade',
                                            args: ['{{ $anexo->id }}'],
                                            icone: 'bx-trash',
                                        })">
                                    <i class="bx bx-trash"></i>
                                </button>
                                @endcan
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @endif

                    @can('update', $at)
                    <div class="d-flex gap-2 align-items-start">
                        <div class="flex-grow-1">
                            <input type="file" wire:model="novoAnexo" accept="application/pdf"
                                   class="form-control form-control-sm @error('novoAnexo') is-invalid @enderror">
                            @error('novoAnexo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <button class="btn btn-sm btn-primary flex-shrink-0" style="height:fit-content"
                                wire:click="anexarArquivoAtividade('{{ $at->id }}')"
                                wire:loading.attr="disabled" wire:target="novoAnexo,anexarArquivoAtividade">
                            <i class="bx bx-upload"></i>
                        </button>
                    </div>
                    <small class="text-muted d-block mt-1">Somente PDF, até 10MB.</small>
                    @endcan
                </div>
            </div>

            <div class="modal-footer flex-wrap">
                <small class="text-muted me-auto">
                    @if ($at->status === \App\Enums\StatusAtividade::Concluido)
                        ✅ Já concluída no cronograma importado
                    @else
                        {{ $atividadePronta ? '✅ Pode ser comprometida no Plano Semanal' : '⚠ Pendências impedem o comprometimento' }}
                    @endif
                </small>
                @can('create', [\App\Models\LicaoAprendida::class, $obra])
                    <a href="{{ route('gestao.licoes-aprendidas', ['origem_tipo' => 'atividade', 'origem_id' => $at->id]) }}"
                       wire:navigate
                       class="btn btn-outline-warning btn-sm">
                        <i class="bx bx-bulb me-1"></i>Registrar como lição aprendida
                    </a>
                @endcan
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
            @if (!$this->temLinhaBaseAtiva)
            {{-- Correção pós-QA (Ciclo 17) — os controles de janela, fonte de
                 dados, seletor de Tendência e seletor de Linha de Base só
                 fazem sentido com o Lookahead operacional (a tabela já fica
                 100% indisponível nesse estado, ver bloco da TABELA acima) —
                 evita deixar filtro "funcionando" sobre um Lookahead que
                 oficialmente não existe. --}}
            <div class="col-12">
                <div class="alert alert-light border py-2 mb-0 small">
                    <i class="bx bx-info-circle me-1"></i>
                    Filtros de janela, fonte de dados, Tendência e Linha de Base
                    ficam disponíveis assim que esta obra tiver uma linha de
                    base ativa.
                </div>
            </div>
            @else
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
            @endif
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
            @if ($this->temLinhaBaseAtiva)
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
            @endif
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
    /* transform:translateX (não right/left) de propósito — animar `right`
       força recálculo de layout do documento inteiro a cada frame; o
       painel podia ficar "grudado" fora da tela em navegadores/condições
       que não repaginam a tempo. `transform` é resolvido só pelo
       compositor (GPU), nunca depende de reflow — mesmo motivo pelo qual
       o comentário original deste bloco (replicado de
       ⚡restricoes.blade.php) já dizia "transform:translateX" mesmo a
       implementação antiga não usando de fato.
       Bug de teste manual, 2026-09-02 — `top: 0` fazia o canva cobrir a
       faixa da navbar fixa (0 a 3.875rem, = $navbar-height do tema); como
       o z-index do canva é maior, um clique no sino/perfil/app-grid nessa
       faixa era engolido pelo canva aberto (mesmo bug nos 8 arquivos que
       usam este padrão — ver ⚡restricoes.blade.php pro diagnóstico
       completo). Corrigido começando o canva abaixo da navbar — nunca
       interfere com o translateX (eixos independentes). */
    .canva-filtros-lookahead {
        position: fixed;
        top: 3.875rem;
        right: 0;
        height: calc(100% - 3.875rem);
        z-index: 1080;
        display: flex;
        flex-direction: column;
        width: 360px;
        max-width: 90vw;
        background: var(--bs-body-bg, #fff);
        box-shadow: 0 0 20px 0 rgba(0, 0, 0, .2);
        transform: translateX(100%);
        transition: transform .25s ease-in-out;
    }

    .canva-filtros-lookahead.canva-filtros-lookahead-aberto {
        transform: translateX(0);
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
        }
    }
    </style>
</div>

@include('components.licoes-aprendidas.reaplicacao-avaliar-modal')

</div>

@script
<script>
    $wire.on('show-toast', ({ message, type = 'success' }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            (toastr[type] || toastr.success)(message);
        }
    });

    // Ciclo 17, A.3 — Curva S da Atividade (popup de detalhe). O <canvas>
    // vive dentro de um wire:ignore (ver Blade), então o Livewire nunca o
    // toca de novo sozinho — todo redesenho é 100% explícito, disparado
    // pelo servidor via dispatch('curva-atividade-atualizada'), mesmo
    // padrão já usado em ⚡curvas.blade.php::desenharGraficoCurvaS(). Uma
    // única instância de Chart.js (só existe 1 popup por vez): abrir o
    // popup, trocar de atividade, trocar Baseline ou trocar a escala
    // disparam o evento; marcar checklist, comentar ou mexer em restrição
    // nunca disparam — o gráfico fica intocado nesses casos.
    let graficoCurvaAtividade = null;

    function desenharGraficoCurvaAtividade(dados) {
        const canvas = document.getElementById('grafico-curva-atividade-popup');

        if (!canvas) {
            // Popup fechado, ou aberto numa atividade/baseline sem dado
            // suficiente (branch de alerta no Blade, sem canvas nenhum).
            if (graficoCurvaAtividade) { graficoCurvaAtividade.destroy(); graficoCurvaAtividade = null; }
            return;
        }

        // O popup fica fora do DOM enquanto fechado (bloco condicional do modal) — cada
        // reabertura é um <canvas> novo. Se o Chart.js antigo está preso a
        // um nó que não é mais este, ele não vale mais.
        if (graficoCurvaAtividade && graficoCurvaAtividade.canvas !== canvas) {
            graficoCurvaAtividade.destroy();
            graficoCurvaAtividade = null;
        }

        // Ciclo 17, A.4 — Tendência é uma 3ª série independente (mesma
        // importação de avanço selecionada que Realizado, mas nunca
        // derivada dela — dados.tendencia vem vazio quando essa importação
        // não tem linha 'tendencia' em avanco_periodos, nunca inventado).
        const mapaPrevisto = Object.fromEntries(dados.previsto.map((p) => [p.periodo_inicio, p.percentual]));
        const mapaRealizado = Object.fromEntries(dados.realizado.map((p) => [p.periodo_inicio, p.percentual]));
        const mapaTendencia = Object.fromEntries(dados.tendencia.map((p) => [p.periodo_inicio, p.percentual]));
        const periodos = [...new Set([
            ...dados.previsto.map((p) => p.periodo_inicio),
            ...dados.realizado.map((p) => p.periodo_inicio),
            ...dados.tendencia.map((p) => p.periodo_inicio),
        ])].sort();
        const labels = periodos.map((p) => dados.labels[p] ?? p);
        const dadosPrevisto = periodos.map((p) => mapaPrevisto[p] ?? null);
        const dadosRealizado = periodos.map((p) => mapaRealizado[p] ?? null);
        const dadosTendencia = periodos.map((p) => mapaTendencia[p] ?? null);

        if (graficoCurvaAtividade) {
            graficoCurvaAtividade.data.labels = labels;
            graficoCurvaAtividade.data.datasets[0].data = dadosPrevisto;
            graficoCurvaAtividade.data.datasets[1].data = dadosRealizado;
            graficoCurvaAtividade.data.datasets[2].data = dadosTendencia;
            graficoCurvaAtividade.update();
            return;
        }

        graficoCurvaAtividade = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Previsto (acum.)',
                        data: dadosPrevisto,
                        borderColor: '#3C79E8',
                        backgroundColor: '#3C79E8',
                        tension: 0.3,
                        spanGaps: true,
                    },
                    {
                        label: 'Realizado (acum.)',
                        data: dadosRealizado,
                        borderColor: '#71dd37',
                        backgroundColor: '#71dd37',
                        tension: 0.3,
                        spanGaps: true,
                    },
                    {
                        label: 'Tendência (acum.)',
                        data: dadosTendencia,
                        borderColor: '#ffab00',
                        backgroundColor: '#ffab00',
                        borderDash: [4, 3],
                        tension: 0.3,
                        spanGaps: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { min: 0, max: 100, ticks: { callback: (v) => v + '%' } } },
                plugins: {
                    // Ciclo 17, A.5.CORREÇÃO — os 3 datasets (Previsto/
                    // Realizado/Tendência) continuam SEMPRE declarados,
                    // por índice fixo (datasets[0]/[1]/[2]), pro .update()
                    // da A.3/A.4 continuar funcionando sem mudança de
                    // estrutura. O que muda é só a LEGENDA: uma série sem
                    // nenhum ponto real (o backend já manda a série
                    // inteira como null quando não há avanço/tendência —
                    // nunca 0, nunca dado inventado) não pode aparecer,
                    // senão parece existir avanço mesmo sem nenhuma
                    // importação Avanço/Ambos selecionada. 0 é dado
                    // válido — só null/undefined contam como ausência.
                    // Reavaliado pelo Chart.js a cada render/update, então
                    // acompanha os dados atuais mesmo quando o mesmo
                    // canvas é reaproveitado entre trocas de baseline/
                    // tendência/granularidade.
                    legend: {
                        labels: {
                            filter: (legendItem, data) => {
                                const dataset = data.datasets[legendItem.datasetIndex];
                                return dataset.data.some((v) => v !== null && v !== undefined);
                            },
                        },
                    },
                },
            },
        });
    }

    $wire.on('curva-atividade-atualizada', ({ dados }) => desenharGraficoCurvaAtividade(dados));
</script>
@endscript
