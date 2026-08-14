<?php

use App\Exports\CentralProntidaoExport;
use App\Models\Atividade;
use App\Models\Disciplina;
use App\Models\FrenteTrabalho;
use App\Models\PacoteTrabalho;
use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use App\Support\CentralProntidao\StatusOperacionalProntidao;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Central de Prontidão (Ciclo 15, Etapa B.2) — tela PRÓPRIA (não uma aba
 * do Lookahead, decisão do Ciclo 14) de leitura/triagem consolidada antes
 * do compromisso no Plano Semanal. SOMENTE LEITURA: nenhum método deste
 * componente cria, altera ou apaga Restricao/PlanoAcao/checklist/
 * Suprimento/Engenharia — toda ação de escrita continua exclusivamente
 * nas telas onde já vive hoje (Quadro de Restrições, Plano de Ação,
 * Lookahead), alcançadas daqui só por deep-link (navegação).
 *
 * Consome CentralProntidaoQuery::paraObra() (Ciclo 15, Etapa B.1,
 * aprovada) como ÚNICA fonte de dado consolidado — nenhuma lógica de
 * classificação de prontidão é reimplementada aqui. `pronta`/
 * `statusOperacional` de cada AtividadeProntidaoView são exibidos tal e
 * qual retornados pelo serviço (Ciclo 14, princípio 2).
 */
new class extends Component {
    public Work $obra;

    // ---- Filtros (todos aditivos sobre CentralProntidaoQuery::paraObra(),
    // nenhum recalcula prontidão — só reduzem QUAIS atividades entram no
    // conjunto avaliado) ----
    public string $horizonte = '30'; // 15 | 30 | 60 | 0 (0 = todo o cronograma, mesmo idioma do Lookahead)
    public string $filtroPacoteId = '';
    public string $filtroDisciplinaId = '';
    public string $filtroFrenteId = '';
    public string $filtroResponsavelId = '';
    public string $busca = '';

    // ---- Ordenação (Ciclo 15, Etapa B.5) — mesmo padrão de propriedades/
    // método já usado em ⚡restricoes.blade.php (sortField/sortDir/
    // ordenarPor), mas aqui NUNCA vira SQL: atua só sobre a collection já
    // materializada por $this->views, dentro de gruposPorPacote(). Default
    // reproduz exatamente a ordem que já existia antes da B.5 (início
    // planejado, com código como desempate). ----
    public string $sortField = 'inicioPlanejado'; // inicioPlanejado | codigoCronograma | status
    public string $sortDir = 'asc';

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        // Mesmo padrão de ⚡plano-acao.blade.php::mount() — só 'ver', sem
        // Policy dedicada (não há nenhuma ação de escrita nesta tela pra
        // autorizar separadamente).
        abort_unless(
            Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.central_prontidao', 'ver'),
            403
        );
    }

    public function temFiltrosAtivos(): bool
    {
        return (bool) ($this->filtroPacoteId
            || $this->filtroDisciplinaId
            || $this->filtroFrenteId
            || $this->filtroResponsavelId
            || $this->busca);
    }

    public function limparFiltros(): void
    {
        $this->filtroPacoteId = '';
        $this->filtroDisciplinaId = '';
        $this->filtroFrenteId = '';
        $this->filtroResponsavelId = '';
        $this->busca = '';
    }

    /**
     * Alterna a ordenação da tabela (Ciclo 15, Etapa B.5) — mesmo mecanismo
     * de ⚡restricoes.blade.php::ordenarPor(): clicar na mesma coluna
     * inverte a direção, clicar numa coluna diferente reseta pra 'asc'.
     * Nunca toca CentralProntidaoQuery/filtros — só a ORDEM de exibição do
     * conjunto já filtrado. `unset($this->gruposPorPacote)` força o
     * `#[Computed]` a recalcular com o novo critério (mesmo padrão já usado
     * em ⚡lookahead.blade.php::updatedModalBaselineId()).
     */
    public function ordenarPor(string $campo): void
    {
        $this->sortDir = $this->sortField === $campo && $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->sortField = $campo;
        unset($this->gruposPorPacote);
    }

    /**
     * Rank de exibição pra ordenação por status (Ciclo 15, Etapa B.5) —
     * puramente visual, vive só aqui, nunca em StatusOperacionalProntidao.
     * Não é a precedência de CLASSIFICAÇÃO (CONCLUIDA > NAO_PRONTA >
     * ATENCAO > PRONTA, que decide qual estado uma atividade tem e
     * continua 100% intocada em CentralProntidaoQuery) — é a ordem que
     * coloca primeiro quem exige atenção quando o usuário pede pra
     * ordenar por status, ASC: Não Pronta, Atenção, Pronta, Concluída.
     */
    private function rankStatusParaOrdenacao(StatusOperacionalProntidao $status): int
    {
        return match ($status) {
            StatusOperacionalProntidao::NaoPronta => 1,
            StatusOperacionalProntidao::Atencao => 2,
            StatusOperacionalProntidao::Pronta => 3,
            StatusOperacionalProntidao::Concluida => 4,
        };
    }

    /**
     * Comparação NATURAL de código WBS (Ciclo 15, Etapa B.5.CORREÇÃO) —
     * mesma semântica exata de ⚡lookahead.blade.php::compararCodigos():
     * explode por ponto, cada segmento comparado como inteiro (0 quando
     * ausente/não-numérico), sem exception pra código nulo/vazio/atípico.
     * Deliberadamente NÃO compartilhado com o Lookahead via trait/service
     * (mesma convenção já documentada no projeto: "um comparador por
     * arquivo") — duplicação pequena, registrada como dívida técnica no
     * relatório desta etapa, não corrigida agora por instrução explícita.
     */
    private function compararCodigosWbs(?string $a, ?string $b): int
    {
        $segA = explode('.', $a ?? '');
        $segB = explode('.', $b ?? '');

        foreach (range(0, max(count($segA), count($segB)) - 1) as $i) {
            $x = (int) ($segA[$i] ?? 0);
            $y = (int) ($segB[$i] ?? 0);
            if ($x !== $y) {
                return $x <=> $y;
            }
        }

        return 0;
    }

    /**
     * Comparação de data pro desempate — mesmo sentinela de data nula
     * ('9999-99-99', nulos por último em ASC) já usado antes da B.5.
     */
    private function compararDatas(?\Carbon\Carbon $a, ?\Carbon\Carbon $b): int
    {
        $chaveA = $a?->format('Y-m-d') ?? '9999-99-99';
        $chaveB = $b?->format('Y-m-d') ?? '9999-99-99';

        return $chaveA <=> $chaveB;
    }

    /**
     * Comparador de duas linhas pra gruposPorPacote() (Ciclo 15, Etapa
     * B.5.CORREÇÃO) — substitui a antiga chaveOrdenacao() baseada em
     * string (que comparava código lexicograficamente). Agora SEMPRE que
     * código entra na comparação — seja como critério PRIMÁRIO ("Ordenar
     * por Código") ou como DESEMPATE da ordem padrão/por status — passa
     * por compararCodigosWbs(), nunca por comparação de string crua.
     * Única semântica de código dentro da Central, como pedido. DESC
     * inverte o resultado COMPOSTO inteiro (primário + desempates), mesmo
     * comportamento holístico que sortByDesc() já produzia antes.
     */
    private function compararViews(\App\Support\CentralProntidao\AtividadeProntidaoView $a, \App\Support\CentralProntidao\AtividadeProntidaoView $b): int
    {
        $resultado = match ($this->sortField) {
            'codigoCronograma' => $this->compararCodigosWbs($a->codigoCronograma, $b->codigoCronograma)
                ?: $this->compararDatas($a->inicioPlanejado, $b->inicioPlanejado),
            'status' => $this->rankStatusParaOrdenacao($a->statusOperacional) <=> $this->rankStatusParaOrdenacao($b->statusOperacional)
                ?: $this->compararDatas($a->inicioPlanejado, $b->inicioPlanejado)
                ?: $this->compararCodigosWbs($a->codigoCronograma, $b->codigoCronograma),
            default => $this->compararDatas($a->inicioPlanejado, $b->inicioPlanejado)
                ?: $this->compararCodigosWbs($a->codigoCronograma, $b->codigoCronograma),
        };

        return $this->sortDir === 'desc' ? -$resultado : $resultado;
    }

    /**
     * Indicador textual de proximidade temporal (Ciclo 15, Etapa B.5) —
     * SOMENTE apresentação, derivado de `inicioPlanejado` já existente no
     * DTO. Nunca um novo campo, nunca uma nova classificação, nunca
     * chamado de "status". Comparação normalizada pro INÍCIO DO DIA dos
     * dois lados (nunca hora/minuto) e calculada por subtração de
     * timestamps ÷ 86400 (mesmo cuidado documentado em
     * ⚡relatorio-detalhe.blade.php::variacaoDias() — não confiar no sinal
     * de Carbon::diffInDays() entre versões). Atividade CONCLUIDA nunca
     * mostra a frase de passado ("há N dias") — decisão explícita: uma
     * atividade já concluída não deve parecer uma pendência em aberto,
     * então o indicador simplesmente não é exibido para ela.
     */
    private function proximidadeTemporal(?\Carbon\Carbon $inicioPlanejado, bool $concluida): ?string
    {
        if ($inicioPlanejado === null || $concluida) {
            return null;
        }

        $hoje = now()->startOfDay();
        $data = $inicioPlanejado->copy()->startOfDay();
        $dias = (int) round(($data->timestamp - $hoje->timestamp) / 86400);

        return match (true) {
            $dias === 0 => 'hoje',
            $dias === 1 => 'amanhã',
            $dias > 1 => "em {$dias} dias",
            default => 'há ' . abs($dias) . ' dias',
        };
    }

    /**
     * Único ponto de chamada ao serviço da B.1 — resolve o horizonte
     * (dias -> data limite) e repassa os filtros tal qual, sem nenhuma
     * lógica de prontidão própria. `0` = "Todo o cronograma" (sentinela
     * já usado pelo Lookahead pra `janelaDias`), traduzido aqui pra
     * `null` (sem filtro de horizonte), nunca pra uma data mágica.
     *
     * @return \Illuminate\Support\Collection<int, \App\Support\CentralProntidao\AtividadeProntidaoView>
     */
    #[Computed]
    public function views()
    {
        $dias = (int) $this->horizonte;
        $horizonteAte = $dias > 0 ? now()->addDays($dias) : null;

        return (new CentralProntidaoQuery())->paraObra(
            $this->obra,
            $horizonteAte,
            $this->filtroPacoteId ?: null,
            $this->filtroDisciplinaId ?: null,
            $this->filtroFrenteId ?: null,
            $this->filtroResponsavelId ?: null,
            $this->busca ?: null,
        );
    }

    /**
     * Contagem dos 4 estados — puramente `countBy` sobre o que o serviço
     * já retornou, nunca um recálculo (Ciclo 15, seção "RESUMO").
     *
     * @return array<string, int>
     */
    #[Computed]
    public function resumo(): array
    {
        $contagem = $this->views->countBy(fn ($v) => $v->statusOperacional->value);

        return [
            'pronta' => $contagem->get(StatusOperacionalProntidao::Pronta->value, 0),
            'atencao' => $contagem->get(StatusOperacionalProntidao::Atencao->value, 0),
            'nao_pronta' => $contagem->get(StatusOperacionalProntidao::NaoPronta->value, 0),
            'concluida' => $contagem->get(StatusOperacionalProntidao::Concluida->value, 0),
        ];
    }

    /**
     * Agrupamento por Pacote/EAP — mesma lógica CONCEITUAL do Lookahead
     * (organizar por pacote), sem copiar a árvore EAP recursiva/
     * expansível dele (Ciclo 15, seção "TABELA": "sem copiar a
     * implementação inteira do Lookahead"). Agrupamento simples e raso.
     *
     * Ciclo 15, Etapa B.5 — o critério de ordenação passou a ser
     * parametrizável ($this->sortField/$this->sortDir, via compararViews()),
     * mas o MECANISMO continua sendo exatamente o de antes da B.5: ordena a
     * collection inteira primeiro (ordenação "global", atravessando
     * pacotes), depois agrupa — groupBy() do Laravel é uma partição
     * estável, então a ordem relativa dentro de cada pacote segue essa
     * ordenação global, e a ordem dos PRÓPRIOS pacotes (qual aparece
     * primeiro) continua sendo definida pela primeira ocorrência de cada
     * pacote na lista já ordenada — mesmo efeito colateral que já existia
     * antes da B.5, só que agora generalizado pro campo escolhido em vez
     * de fixo em início planejado (achado já registrado na auditoria da
     * B.5 como risco aceitável, não corrigido nesta etapa). O agrupamento
     * em si nunca é desmontado/refeito.
     *
     * Etapa B.5.CORREÇÃO: sortBy()/sortByDesc() (que comparavam uma
     * string-chave pré-computada) viraram sort() com comparador de 2
     * argumentos (compararViews()) — necessário pra código WBS poder usar
     * comparação NATURAL (segmento a segmento) em vez de lexicográfica,
     * tanto como critério primário quanto como desempate.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection>
     */
    #[Computed]
    public function gruposPorPacote()
    {
        $ordenados = $this->views->sort(fn ($a, $b) => $this->compararViews($a, $b));

        return $ordenados->groupBy(fn ($v) => $v->pacoteNome ?? 'Sem pacote');
    }

    #[Computed]
    public function pacotesDisponiveis()
    {
        return PacoteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    /**
     * Disciplina não tem `obra_id` (é tenant-scoped) — restrito às
     * disciplinas efetivamente usadas por alguma atividade desta obra,
     * pra não listar opção nenhuma atividade usa.
     */
    #[Computed]
    public function disciplinasDisponiveis()
    {
        $idsUsados = Atividade::where('obra_id', $this->obra->id)
            ->whereNotNull('disciplina_id')
            ->distinct()
            ->pluck('disciplina_id');

        return Disciplina::whereIn('id', $idsUsados)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function frentesDisponiveis()
    {
        return FrenteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function responsaveis()
    {
        return Cache::remember(
            "obra_{$this->obra->id}_usuarios",
            90,
            fn () => $this->obra->users()->orderBy('users.first_name')->get(['users.id', 'users.first_name', 'users.last_name'])
        );
    }

    // =========================================================================
    // EXPORTAÇÃO (PDF / EXCEL) — Ciclo 15, Etapa B.4
    // =========================================================================

    /**
     * Fonte única pros dois formatos (mesmo padrão de
     * ⚡relatorios-restricoes.blade.php::dadosExport()) — reflete EXATAMENTE
     * o horizonte/filtros aplicados na tela no momento do clique, porque lê
     * só `$this->views`/`$this->resumo` (já `#[Computed]`, mesmos dados já
     * renderizados na tabela) e as coleções de filtro já carregadas
     * (`pacotesDisponiveis`/etc, sem nenhuma query nova). Nenhuma regra de
     * prontidão é recalculada aqui — `pronta`/`statusOperacional` de cada
     * AtividadeProntidaoView já vêm prontos do serviço da B.1.
     *
     * @return array{obra: Work, geradoEm: \Carbon\Carbon, horizonteLabel: string, filtros: array, resumo: array, views: \Illuminate\Support\Collection}
     */
    private function dadosExport(): array
    {
        $horizonteLabel = match ($this->horizonte) {
            '15' => '15 dias',
            '30' => '30 dias',
            '60' => '60 dias',
            default => 'Todo o cronograma',
        };

        return [
            'obra' => $this->obra,
            'geradoEm' => now(),
            'horizonteLabel' => $horizonteLabel,
            'filtros' => [
                'pacote' => $this->filtroPacoteId ? $this->pacotesDisponiveis->firstWhere('id', $this->filtroPacoteId)?->nome : null,
                'disciplina' => $this->filtroDisciplinaId ? $this->disciplinasDisponiveis->firstWhere('id', $this->filtroDisciplinaId)?->nome : null,
                'frente' => $this->filtroFrenteId ? $this->frentesDisponiveis->firstWhere('id', $this->filtroFrenteId)?->nome : null,
                'responsavel' => $this->filtroResponsavelId
                    ? optional($this->responsaveis->firstWhere('id', $this->filtroResponsavelId), fn ($u) => trim($u->first_name . ' ' . $u->last_name))
                    : null,
                'busca' => $this->busca ?: null,
            ],
            'resumo' => array_merge($this->resumo, ['total' => $this->views->count()]),
            'views' => $this->views,
        ];
    }

    public function exportarExcel()
    {
        return Excel::download(
            new CentralProntidaoExport($this->dadosExport()),
            "central-prontidao-{$this->obra->id}.xlsx"
        );
    }

    public function exportarPdf()
    {
        $pdf = Pdf::loadView('exports.central-prontidao-pdf', $this->dadosExport());

        return response()->streamDownload(
            fn () => print $pdf->output(),
            "central-prontidao-{$this->obra->id}.pdf"
        );
    }
}; ?>

<div>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bx bx-check-shield me-1"></i>Central de Prontidão</h5>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm" wire:click="exportarExcel">
                    <i class="bx bxs-file-export me-1"></i>Excel
                </button>
                <button class="btn btn-outline-danger btn-sm" wire:click="exportarPdf">
                    <i class="bx bxs-file-pdf me-1"></i>PDF
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Horizonte</label>
                    <select class="form-select form-select-sm" wire:model.live="horizonte">
                        <option value="15">15 dias</option>
                        <option value="30">30 dias</option>
                        <option value="60">60 dias</option>
                        <option value="0">Todo o cronograma</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Pacote/EAP</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroPacoteId">
                        <option value="">Todos</option>
                        @foreach ($this->pacotesDisponiveis as $pacote)
                        <option value="{{ $pacote->id }}">{{ $pacote->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Disciplina</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroDisciplinaId">
                        <option value="">Todas</option>
                        @foreach ($this->disciplinasDisponiveis as $disciplina)
                        <option value="{{ $disciplina->id }}">{{ $disciplina->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Frente</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroFrenteId">
                        <option value="">Todas</option>
                        @foreach ($this->frentesDisponiveis as $frente)
                        <option value="{{ $frente->id }}">{{ $frente->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Responsável</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroResponsavelId">
                        <option value="">Todos</option>
                        @foreach ($this->responsaveis as $u)
                        <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Buscar atividade</label>
                    <input type="text" class="form-control form-control-sm"
                           placeholder="Nome ou código..."
                           wire:model.live.debounce.300ms="busca">
                </div>
            </div>
            @if ($this->temFiltrosAtivos())
            <div class="mt-2">
                <button class="btn btn-sm btn-outline-secondary" wire:click="limparFiltros">
                    <i class="bx bx-x me-1"></i>Limpar filtros
                </button>
            </div>
            @endif
        </div>
    </div>

    {{-- RESUMO --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-success">{{ $this->resumo['pronta'] }}</div>
                    <div class="text-muted small">🟢 Prontas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-warning">{{ $this->resumo['atencao'] }}</div>
                    <div class="text-muted small">🟡 Atenção</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-danger">{{ $this->resumo['nao_pronta'] }}</div>
                    <div class="text-muted small">🔴 Não prontas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-secondary">{{ $this->resumo['concluida'] }}</div>
                    <div class="text-muted small">⚪ Concluídas</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if ($this->views->isEmpty())
                <div class="text-center py-5">
                    <i class="bx bx-check-shield display-3 text-muted"></i>
                    @if ($this->temFiltrosAtivos())
                    <h5 class="fw-bold mt-3">Nenhuma atividade encontrada</h5>
                    <p class="text-muted">
                        <button class="btn btn-sm btn-outline-secondary mt-1" wire:click="limparFiltros">
                            Limpar filtros
                        </button>
                    </p>
                    @else
                    <h5 class="fw-bold mt-3">Nenhuma atividade planejada para este horizonte.</h5>
                    @endif
                </div>
            @elseif ($this->resumo['nao_pronta'] === 0 && $this->resumo['atencao'] === 0)
                <div class="alert alert-success m-3 mb-0">
                    <i class="bx bx-check-circle me-1"></i>
                    Todas as atividades deste horizonte estão prontas.
                </div>
            @endif

            @if ($this->views->isNotEmpty())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:2.5rem"></th>
                            <th style="cursor:pointer" wire:click="ordenarPor('codigoCronograma')">
                                Código
                                <i class="bx {{ $sortField === 'codigoCronograma' ? ($sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort' }} ms-1"></i>
                            </th>
                            <th>Atividade</th>
                            <th style="cursor:pointer" wire:click="ordenarPor('inicioPlanejado')">
                                Início
                                <i class="bx {{ $sortField === 'inicioPlanejado' ? ($sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort' }} ms-1"></i>
                            </th>
                            <th style="cursor:pointer" wire:click="ordenarPor('status')">
                                Status
                                <i class="bx {{ $sortField === 'status' ? ($sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort' }} ms-1"></i>
                            </th>
                            <th>Motivos</th>
                        </tr>
                    </thead>
                    @foreach ($this->gruposPorPacote as $nomePacote => $viewsDoGrupo)
                    <tbody>
                        <tr class="table-light" wire:key="central-prontidao-grupo-{{ Str::slug($nomePacote) }}">
                            <td colspan="6" class="fw-semibold">
                                <i class="bx bx-folder me-1"></i>{{ $nomePacote }}
                            </td>
                        </tr>
                    </tbody>
                    @foreach ($viewsDoGrupo as $view)
                    @php
                        $corStatus = match ($view->statusOperacional) {
                            StatusOperacionalProntidao::Pronta => 'success',
                            StatusOperacionalProntidao::Atencao => 'warning',
                            StatusOperacionalProntidao::NaoPronta => 'danger',
                            StatusOperacionalProntidao::Concluida => 'secondary',
                        };
                        $emojiStatus = match ($view->statusOperacional) {
                            StatusOperacionalProntidao::Pronta => '🟢',
                            StatusOperacionalProntidao::Atencao => '🟡',
                            StatusOperacionalProntidao::NaoPronta => '🔴',
                            StatusOperacionalProntidao::Concluida => '⚪',
                        };
                    @endphp
                    {{-- Múltiplos <tbody> por <table>, mesmo padrão de ⚡plano-acao.blade.php
                         — cada atividade tem seu próprio escopo Alpine independente. --}}
                    <tbody x-data="{ aberto: false }" wire:key="central-prontidao-tbody-{{ $view->atividadeId }}">
                        <tr style="cursor:pointer" @click="aberto = !aberto">
                            <td><i class="bx" :class="aberto ? 'bx-chevron-up' : 'bx-chevron-down'"></i></td>
                            <td><code>{{ $view->codigoCronograma ?? '—' }}</code></td>
                            <td>{{ $view->nome }}</td>
                            <td>
                                {{ $view->inicioPlanejado?->format('d/m/y') ?? '—' }}
                                @php
                                    $proximidade = $this->proximidadeTemporal($view->inicioPlanejado, $view->statusOperacional === StatusOperacionalProntidao::Concluida);
                                @endphp
                                @if ($proximidade)
                                <br><small class="text-muted">{{ $proximidade }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-label-{{ $corStatus }}">
                                    {{ $emojiStatus }} {{ $view->statusOperacional->label() }}
                                </span>
                            </td>
                            <td>
                                @if (empty($view->resumoMotivos))
                                <span class="text-muted">—</span>
                                @else
                                @foreach ($view->resumoMotivos as $motivo)
                                <span class="badge bg-label-{{ $corStatus }} me-1 mb-1">{{ $motivo }}</span>
                                @endforeach
                                @endif
                            </td>
                        </tr>
                        <tr x-show="aberto" x-transition x-cloak>
                            <td></td>
                            <td colspan="5">
                                @include('pages.radar._partials.central-prontidao-detalhe', ['view' => $view])
                            </td>
                        </tr>
                    </tbody>
                    @endforeach
                    @endforeach
                </table>
            </div>
            @endif
        </div>
    </div>
</div>
