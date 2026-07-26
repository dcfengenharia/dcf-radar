<?php

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Work;
use App\Services\CurvaAvanco;
use App\Services\ReportGerador;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Assistente de criação do Report semanal — 5 passos:
 * 1) período + linha de base; 2) escolha das curvas (pacotes da EAP ou
 * "obra inteira"); 3) pontos de atenção por curva; 4) fotos do período;
 * 5) revisão e salvar rascunho.
 *
 * Quem efetivamente CALCULA os números (curva S, quadro de desvios) é o
 * App\Services\ReportGerador — este componente só monta as OPÇÕES
 * (quais curvas, pontos de atenção, fotos) e delega a ele.
 */
new class extends Component {
    use WithFileUploads;

    /**
     * Limiares do velocímetro de aderência (zonas vermelha/amarela/verde) —
     * espelham as mesmas constantes em ⚡relatorio-detalhe.blade.php (mudar
     * um lado exige mudar o outro, convenção já usada no projeto pra não
     * compartilhar esse tipo de helper pequeno via trait).
     */
    private const ADERENCIA_LIMIAR_OTIMO = 95.0;
    private const ADERENCIA_LIMIAR_ATENCAO = 80.0;

    public Work $obra;

    public string $etapa = '1';

    // Passo 1
    public string  $periodoReferencia = '';
    public ?string $linhaBaseId       = null;
    public ?string $avancoImportacaoId = null;
    public string  $titulo            = '';

    // Passo 2 — seleção de curvas
    public bool  $obraInteiraMarcada = false;
    public array $pacotesMarcados    = []; // [pacoteId => true]
    public array $ordemCurvas        = []; // ['obra', pacoteId, ...] na ordem de exibição

    // Passo 3 — pontos de atenção por curva
    public array $pontosPorCurva = []; // [curveKey => [['categoria'=>.., 'texto'=>..], ...]]

    // Passo 4 — fotos
    public array $novasFotos    = [];
    public array $legendasFotos = [];

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
        $this->authorize('create', [Report::class, $obra->id]);
        $this->periodoReferencia = now()->startOfWeek()->toDateString();
    }

    #[Computed]
    public function linhasBase(): \Illuminate\Support\Collection
    {
        return LinhaBase::where('obra_id', $this->obra->id)
            ->with('importacao:id,data_status,importado_em')
            ->latest()
            ->get(['id', 'nome', 'cronograma_importacao_id']);
    }

    /** Importações elegíveis como fonte de Realizado/Tendência — nunca uma importação puramente Baseline. */
    #[Computed]
    public function avancosDisponiveis(): \Illuminate\Support\Collection
    {
        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->whereIn('tipo', [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value])
            ->orderByDesc('importado_em')
            ->get(['id', 'importado_em', 'data_status']);
    }

    /** Compara códigos de EAP segmento a segmento — mesmo helper usado no Lookahead/Linhas de Base. */
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

    #[Computed]
    public function arvorePacotes(): array
    {
        $todos = PacoteTrabalho::where('obra_id', $this->obra->id)->get(['id', 'nome', 'codigo', 'parent_id']);
        $porPai = $todos->groupBy('parent_id');

        $resultado = [];
        $percorrer = function ($paiId, $nivel) use (&$percorrer, &$resultado, $porPai) {
            $filhos = ($porPai->get($paiId) ?? collect())
                ->sort(fn ($a, $b) => $this->compararCodigos($a->codigo, $b->codigo));

            foreach ($filhos as $p) {
                $resultado[] = ['pacote' => $p, 'nivel' => $nivel];
                $percorrer($p->id, $nivel + 1);
            }
        };
        $percorrer(null, 0);

        return $resultado;
    }

    #[Computed]
    public function pacotesById(): \Illuminate\Support\Collection
    {
        return PacoteTrabalho::where('obra_id', $this->obra->id)->get()->keyBy('id');
    }

    public function tituloCurva(string $curveKey): string
    {
        if ($curveKey === 'obra') {
            return $this->obra->name . ' (obra inteira)';
        }

        $pacote = $this->pacotesById->get($curveKey);

        return $pacote ? "{$pacote->codigo} - {$pacote->nome}" : $curveKey;
    }

    private const MESES_PT = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

    /**
     * Prévia do gráfico de cada curva selecionada, pro passo 3 (Pontos de
     * Atenção) — ajuda o usuário a escrever notas melhores vendo a curva
     * antes de salvar. Como o Report ainda não existe nesse ponto, consulta
     * App\Services\CurvaAvanco AO VIVO (não fere a filosofia de fotografia:
     * nada foi gravado ainda). Espelha a mesma correção de %realizado do
     * App\Services\ReportGerador::gerarCurva() via CurvaAvanco::
     * rebasearPercentual() — senão a prévia mostraria um número diferente
     * do que será salvo. Inclui mensal, semanal (últimas 4 semanas) e a
     * aderência da última semana atualizada — mesmo conteúdo que
     * ⚡relatorio-detalhe.blade.php mostra depois de gerado, só que ao vivo.
     */
    #[Computed]
    public function dadosGraficosPreview(): array
    {
        $curvaAvanco = app(CurvaAvanco::class);

        return collect($this->ordemCurvas)->map(function (string $chave) use ($curvaAvanco) {
            $pacoteId = $chave === 'obra' ? null : $chave;

            $totalHhPrevisto = $pacoteId
                ? (PacoteTrabalho::find($pacoteId)?->totalHhBaseline($this->cronogramaIdPrevisto()) ?? 0.0)
                : $curvaAvanco->totalCalculado($this->obra, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, null, $this->linhaBaseId);

            $mensal = $this->serieParaGraficoPreview($curvaAvanco, $pacoteId, $totalHhPrevisto, GranularidadePeriodo::Mensal);
            $semanal = $this->serieParaGraficoPreview($curvaAvanco, $pacoteId, $totalHhPrevisto, GranularidadePeriodo::Semanal);

            return [
                'chave' => $chave,
                'mensal' => ['labels' => $mensal['labels'], 'barras' => $mensal['barras'], 'linhas' => $mensal['linhas']],
                'semanal' => ['labels' => $semanal['labels'], 'barras' => $semanal['barras'], 'linhas' => $semanal['linhas']],
                // Aderência da última semana ATUALIZADA (com previsto E
                // realizado), não necessariamente a última semana da
                // janela — mesma regra de ⚡relatorio-detalhe.blade.php::
                // aderenciaDaUltimaSemanaAtualizada().
                'aderencia_atual' => $this->ultimaAderenciaNaoNula($semanal['aderencias']),
            ];
        })->all();
    }

    /**
     * Monta labels/barras (% do período)/linhas (% acumulado) de UMA
     * granularidade, ao vivo via CurvaAvanco — usado tanto pra mensal
     * quanto semanal na prévia do assistente. Semanal já recorta pras
     * últimas 4 semanas antes do período de referência, mesma janela que
     * App\Services\ReportGerador::recortarUltimasQuatroSemanas() usa na
     * geração de verdade.
     */
    private function serieParaGraficoPreview(CurvaAvanco $curvaAvanco, ?string $pacoteId, float $totalHhPrevisto, GranularidadePeriodo $gran): array
    {
        $pontosPorSerie = [];
        foreach (SerieAvanco::cases() as $serie) {
            $pontos = $curvaAvanco->calcular($this->obra, $serie, $gran, $pacoteId, $this->linhaBaseId, $this->avancoImportacaoId);
            $pontosPorSerie[$serie->value] = collect($curvaAvanco->rebasearPercentual($pontos, $totalHhPrevisto))
                ->keyBy('periodo_inicio');
        }

        $periodos = collect($pontosPorSerie)->flatMap(fn ($c) => $c->keys())->unique()->sort()->values();

        if ($gran === GranularidadePeriodo::Semanal) {
            $fim = Carbon::parse($this->periodoReferencia ?: now())->startOfWeek();
            $inicio = $fim->copy()->subWeeks(3)->toDateString();
            $periodos = $periodos->filter(fn ($p) => $p >= $inicio && $p <= $fim->toDateString())->values();
        }

        $labels = $periodos->map(fn ($p) => $gran === GranularidadePeriodo::Mensal
            ? self::MESES_PT[Carbon::parse($p)->month - 1] . '/' . Carbon::parse($p)->format('y')
            : sprintf('SEM %02d/%d', Carbon::parse($p)->weekOfYear, Carbon::parse($p)->year))->all();

        // Barras = % que o HH daquele período representa do total de
        // linha de base — mesmo eixo 0-100% das linhas de % acumulado
        // (nunca misturar HH com % no mesmo gráfico).
        $barras = [];
        $linhas = [];
        foreach (['previsto' => 'Previsto', 'tendencia' => 'Tendência', 'realizado' => 'Realizado'] as $serieValue => $label) {
            $porPeriodo = $pontosPorSerie[$serieValue];
            $barras[$serieValue] = [
                'label' => $label,
                'data' => $periodos->map(function ($p) use ($porPeriodo, $totalHhPrevisto) {
                    if (! $porPeriodo->has($p)) {
                        return null;
                    }

                    return $totalHhPrevisto > 0 ? round((float) $porPeriodo[$p]['horas_exibir'] / $totalHhPrevisto * 100, 2) : 0.0;
                })->all(),
            ];
            $linhas[$serieValue] = [
                'label' => $label,
                'data' => $periodos->map(fn ($p) => $porPeriodo->has($p) ? (float) $porPeriodo[$p]['percentual'] : null)->all(),
            ];
        }

        // Aderência DO PERÍODO (%realizado do período ÷ %previsto do
        // período) — só faz sentido pra granularidade semanal, mas
        // calculada aqui pras duas pra reaproveitar o mesmo helper.
        $aderencias = $periodos->map(function ($p, $i) use ($barras) {
            $previstoPct = $barras['previsto']['data'][$i];
            $realizadoPct = $barras['realizado']['data'][$i];

            return ($previstoPct !== null && $previstoPct > 0 && $realizadoPct !== null)
                ? round($realizadoPct / $previstoPct * 100, 2)
                : null;
        })->all();

        return ['labels' => $labels, 'barras' => $barras, 'linhas' => $linhas, 'aderencias' => $aderencias];
    }

    /** Varre de trás pra frente e devolve o primeiro valor não-nulo — última semana com previsto E realizado. */
    private function ultimaAderenciaNaoNula(array $aderencias): ?float
    {
        for ($i = count($aderencias) - 1; $i >= 0; $i--) {
            if ($aderencias[$i] !== null) {
                return $aderencias[$i];
            }
        }

        return null;
    }

    /** Limiares do velocímetro de aderência, expostos pro Blade repassar ao JS (evita duplicar os números). */
    public function limiaresAderencia(): array
    {
        return [self::ADERENCIA_LIMIAR_ATENCAO, self::ADERENCIA_LIMIAR_OTIMO];
    }

    /**
     * Wrapper fino, chamável como ação Livewire pelo JS — métodos
     * decorados com #[Computed] não podem ser invocados diretamente via
     * $wire.metodo() (Livewire lança CannotCallComputedDirectlyException),
     * só acessados como propriedade dentro do próprio componente/Blade.
     * Ver o bloco de script no fim do arquivo pra entender por que a
     * prévia precisa ser buscada ao vivo (não embutida via JSON estático)
     * a cada vez que o passo 3 é alcançado.
     */
    public function dadosGraficosPreviewParaJs(): array
    {
        return $this->dadosGraficosPreview;
    }

    /**
     * Mesma resolução de importação usada pelo ReportGerador/CurvaAvanco —
     * linha de base só vale pra Previsto; sem pin, cai na última importação
     * ELEGÍVEL (baseline/ambos — nunca escolhe uma importação puramente de
     * avanço como fonte de linha de base).
     */
    private function cronogramaIdPrevisto(): ?string
    {
        if ($this->linhaBaseId) {
            return LinhaBase::find($this->linhaBaseId)?->cronograma_importacao_id;
        }

        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->whereIn('tipo', [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value])
            ->orderByDesc('importado_em')
            ->orderByDesc('id')
            ->value('id');
    }

    public function toggleObraInteira(): void
    {
        $this->obraInteiraMarcada = ! $this->obraInteiraMarcada;
        $this->sincronizarOrdemCurvas();
    }

    public function togglePacote(string $pacoteId): void
    {
        if (isset($this->pacotesMarcados[$pacoteId])) {
            unset($this->pacotesMarcados[$pacoteId]);
        } else {
            $this->pacotesMarcados[$pacoteId] = true;
        }
        $this->sincronizarOrdemCurvas();
    }

    private function sincronizarOrdemCurvas(): void
    {
        $desejado = [];
        if ($this->obraInteiraMarcada) {
            $desejado[] = 'obra';
        }
        foreach (array_keys($this->pacotesMarcados) as $pid) {
            $desejado[] = $pid;
        }

        // Preserva a ordem já definida (via ▲/▼) pras chaves que continuam
        // marcadas; só acrescenta as novas no final.
        $preservados = array_values(array_intersect($this->ordemCurvas, $desejado));
        $novos = array_values(array_diff($desejado, $preservados));
        $this->ordemCurvas = [...$preservados, ...$novos];

        foreach ($this->ordemCurvas as $chave) {
            $this->pontosPorCurva[$chave] ??= [];
        }
        foreach (array_keys($this->pontosPorCurva) as $chave) {
            if (! in_array($chave, $this->ordemCurvas, true)) {
                unset($this->pontosPorCurva[$chave]);
            }
        }
    }

    public function moverCurva(string $chave, int $direcao): void
    {
        $idx = array_search($chave, $this->ordemCurvas, true);
        if ($idx === false) {
            return;
        }

        $novoIdx = $idx + $direcao;
        if ($novoIdx < 0 || $novoIdx >= count($this->ordemCurvas)) {
            return;
        }

        [$this->ordemCurvas[$idx], $this->ordemCurvas[$novoIdx]] = [$this->ordemCurvas[$novoIdx], $this->ordemCurvas[$idx]];
    }

    public function adicionarPontoAtencao(string $chave): void
    {
        $this->pontosPorCurva[$chave][] = ['categoria' => '', 'texto' => ''];
    }

    public function removerPontoAtencao(string $chave, int $indice): void
    {
        unset($this->pontosPorCurva[$chave][$indice]);
        $this->pontosPorCurva[$chave] = array_values($this->pontosPorCurva[$chave]);
    }

    public function removerFoto(int $indice): void
    {
        unset($this->novasFotos[$indice], $this->legendasFotos[$indice]);
        $this->novasFotos = array_values($this->novasFotos);
        $this->legendasFotos = array_values($this->legendasFotos);
    }

    public function avancar(): void
    {
        if ($this->etapa === '1') {
            $this->validate([
                'periodoReferencia' => 'required|date',
            ], [], ['periodoReferencia' => 'período de referência']);
        }

        if ($this->etapa === '2' && $this->ordemCurvas === []) {
            $this->addError('ordemCurvas', 'Selecione ao menos uma curva (um pacote da EAP ou "Obra inteira").');
            return;
        }

        $this->resetErrorBag();
        $this->etapa = (string) (((int) $this->etapa) + 1);
    }

    public function voltar(): void
    {
        $this->resetErrorBag();
        $this->etapa = (string) max(1, ((int) $this->etapa) - 1);
    }

    public function salvar(): void
    {
        $this->authorize('create', [Report::class, $this->obra->id]);

        $this->validate([
            'periodoReferencia' => 'required|date',
            'novasFotos.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($this->ordemCurvas === []) {
            $this->addError('ordemCurvas', 'Selecione ao menos uma curva antes de salvar.');
            $this->etapa = '2';
            return;
        }

        $curvas = [];
        foreach ($this->ordemCurvas as $i => $chave) {
            $curvas[] = [
                'pacote_trabalho_id' => $chave === 'obra' ? null : $chave,
                'ordem' => $i,
                'pontos_atencao' => array_values(array_filter(
                    $this->pontosPorCurva[$chave] ?? [],
                    fn ($p) => trim($p['texto'] ?? '') !== ''
                )),
            ];
        }

        $report = app(ReportGerador::class)->gerarRascunho($this->obra, auth()->user(), [
            'periodo_referencia' => $this->periodoReferencia,
            'linha_base_id' => $this->linhaBaseId,
            'avanco_importacao_id' => $this->avancoImportacaoId,
            'titulo' => $this->titulo ?: null,
            'curvas' => $curvas,
        ]);

        foreach ($this->novasFotos as $i => $arquivo) {
            $caminho = $arquivo->store("report-fotos/{$this->obra->id}/{$report->id}", 'public');
            $report->fotos()->create([
                'caminho_arquivo' => $caminho,
                'legenda' => $this->legendasFotos[$i] ?? null,
                'ordem' => $i,
                'enviado_por' => auth()->id(),
            ]);
        }

        $this->dispatch('show-toast', message: 'Rascunho do report salvo com sucesso.');
        $this->redirect(route('radar.relatorios.show', $report));
    }
};

?>

<div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Indicador de progresso --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        @foreach(['1' => 'Período', '2' => 'Curvas', '3' => 'Pontos de Atenção', '4' => 'Fotos', '5' => 'Revisão'] as $n => $rotulo)
        <div class="text-center flex-fill">
            <span class="badge rounded-pill {{ $etapa === $n ? 'bg-primary' : ((int)$etapa > (int)$n ? 'bg-success' : 'bg-label-secondary') }}">
                {{ $n }}
            </span>
            <div class="small mt-1 {{ $etapa === $n ? 'fw-bold' : 'text-muted' }}">{{ $rotulo }}</div>
        </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body">

            {{-- ============================================================ --}}
            {{-- PASSO 1 — Período e linha de base --}}
            {{-- ============================================================ --}}
            @if($etapa === '1')
            <h6 class="mb-3">Período do report</h6>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Semana de referência <span class="text-danger">*</span></label>
                    <input type="date" class="form-control @error('periodoReferencia') is-invalid @enderror"
                           wire:model="periodoReferencia">
                    @error('periodoReferencia')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Título <span class="text-muted">(opcional)</span></label>
                    <input type="text" class="form-control" wire:model="titulo" placeholder="ex: Report Semana 26">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Linha de Base <span class="text-muted">(opcional)</span></label>
                    <select class="form-select" wire:model="linhaBaseId">
                        <option value="">— Usar última importação de linha de base —</option>
                        @foreach($this->linhasBase as $lb)
                        <option value="{{ $lb->id }}">{{ $lb->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Realizado/Tendência <span class="text-muted">(opcional)</span></label>
                    <select class="form-select" wire:model="avancoImportacaoId">
                        <option value="">— Usar última importação de avanço —</option>
                        @foreach($this->avancosDisponiveis as $imp)
                        <option value="{{ $imp->id }}">
                            {{ $imp->importado_em->format('d/m/Y H:i') }}
                            @if($imp->data_status) (status {{ $imp->data_status->format('d/m/Y') }}) @endif
                        </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="alert alert-light border d-flex align-items-center gap-2 mt-3 mb-0 py-2">
                <i class="bx bx-info-circle text-primary"></i>
                @if($this->avancosDisponiveis->isNotEmpty())
                <small>
                    @if($avancoImportacaoId)
                    Tendência e realizado usarão a importação de avanço selecionada acima.
                    @else
                    Tendência e realizado usarão a importação de avanço mais recente
                    (<strong>{{ $this->avancosDisponiveis->first()->importado_em->format('d/m/Y H:i') }}</strong>
                    @if($this->avancosDisponiveis->first()->data_status)
                    — status em {{ $this->avancosDisponiveis->first()->data_status->format('d/m/Y') }}
                    @endif
                    ).
                    @endif
                </small>
                @else
                <small class="text-danger">Esta obra ainda não tem nenhuma importação de avanço (realizado/tendência).</small>
                @endif
            </div>
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 2 — Seleção de curvas --}}
            {{-- ============================================================ --}}
            @if($etapa === '2')
            <h6 class="mb-3">Quais curvas este report vai mostrar?</h6>
            <p class="text-muted small mb-3">Escolha a obra inteira e/ou qualquer nível da EAP — uma curva S será gerada para cada escolha.</p>

            @error('ordemCurvas')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="obraInteira"
                       wire:click="toggleObraInteira" @checked($obraInteiraMarcada)>
                <label class="form-check-label fw-semibold" for="obraInteira">
                    <i class="bx bx-buildings me-1"></i>Obra inteira
                </label>
            </div>

            <div class="border rounded p-2" style="max-height: 400px; overflow-y: auto;">
                @forelse($this->arvorePacotes as $linha)
                @php $p = $linha['pacote']; @endphp
                <div class="form-check" style="padding-left: {{ 24 + $linha['nivel'] * 20 }}px">
                    <input class="form-check-input" type="checkbox" id="pacote_{{ $p->id }}"
                           wire:click="togglePacote('{{ $p->id }}')" @checked(isset($pacotesMarcados[$p->id]))
                           style="margin-left: -20px">
                    <label class="form-check-label" for="pacote_{{ $p->id }}">
                        <i class="bx bx-folder text-warning me-1"></i>{{ $p->codigo }} — {{ $p->nome }}
                    </label>
                </div>
                @empty
                <p class="text-muted small mb-0 p-2">Nenhum pacote de EAP cadastrado nesta obra.</p>
                @endforelse
            </div>
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 3 — Pontos de atenção --}}
            {{-- ============================================================ --}}
            @if($etapa === '3')
            <h6 class="mb-3">Pontos de atenção por curva</h6>
            <p class="text-muted small mb-3">Use as setas para definir a ordem de exibição no report. Os pontos de atenção são digitados manualmente — não vêm do cronograma.</p>

            @foreach($ordemCurvas as $i => $chave)
            <div class="card mb-3 border" wire:key="curva-preview-{{ $chave }}">
                <div class="card-header d-flex align-items-center gap-2 py-2">
                    <strong class="flex-grow-1">{{ $this->tituloCurva($chave) }}</strong>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" wire:click="moverCurva('{{ $chave }}', -1)" @disabled($i === 0)>
                        <i class="bx bx-up-arrow-alt"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" wire:click="moverCurva('{{ $chave }}', 1)" @disabled($i === count($ordemCurvas) - 1)>
                        <i class="bx bx-down-arrow-alt"></i>
                    </button>
                </div>
                <div class="card-body py-2">
                    <p class="text-muted small mb-2">Prévia da curva — use como referência pra escrever o ponto de atenção.</p>

                    <small class="text-muted d-block mb-1">Mensal</small>
                    <div wire:ignore class="mb-3" style="height: 220px;">
                        <canvas id="chart-preview-{{ $chave }}"></canvas>
                    </div>

                    <small class="text-muted d-block mb-1">Semanal (últimas 4 semanas)</small>
                    <div wire:ignore class="mb-3" style="height: 220px;">
                        <canvas id="chart-preview-semanal-{{ $chave }}"></canvas>
                    </div>

                    <small class="text-muted d-block mb-1 text-center">Aderência — última semana atualizada</small>
                    <div class="row justify-content-center mb-3">
                        <div class="col-md-4">
                            <div wire:ignore class="text-center" style="height: 180px;">
                                <canvas id="chart-preview-aderencia-{{ $chave }}"></canvas>
                            </div>
                        </div>
                    </div>

                    @foreach($pontosPorCurva[$chave] ?? [] as $j => $ponto)
                    <div class="row g-2 mb-2 align-items-center">
                        <div class="col-md-3">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="pontosPorCurva.{{ $chave }}.{{ $j }}.categoria"
                                   placeholder="Categoria (opcional)">
                        </div>
                        <div class="col-md-8">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="pontosPorCurva.{{ $chave }}.{{ $j }}.texto"
                                   placeholder="Descreva o ponto de atenção...">
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0"
                                    wire:click="removerPontoAtencao('{{ $chave }}', {{ $j }})">
                                <i class="bx bx-x"></i>
                            </button>
                        </div>
                    </div>
                    @endforeach
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="adicionarPontoAtencao('{{ $chave }}')">
                        <i class="bx bx-plus me-1"></i>Adicionar ponto de atenção
                    </button>
                </div>
            </div>
            @endforeach
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 4 — Fotos --}}
            {{-- ============================================================ --}}
            @if($etapa === '4')
            <h6 class="mb-3">Relatório fotográfico do período</h6>
            <p class="text-muted small mb-3">Fotos com legenda — a galeria fica no report inteiro, não em uma curva específica.</p>

            <div class="mb-3">
                <input type="file" class="form-control @error('novasFotos.*') is-invalid @enderror"
                       wire:model="novasFotos" multiple accept="image/*">
                @error('novasFotos.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div wire:loading wire:target="novasFotos" class="small text-muted mt-1">Enviando fotos...</div>
            </div>

            <div class="row g-3">
                @foreach($novasFotos as $i => $foto)
                <div class="col-md-3">
                    <div class="card h-100">
                        <img src="{{ $foto->temporaryUrl() }}" class="card-img-top" style="height:140px; object-fit:cover">
                        <div class="card-body p-2">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="legendasFotos.{{ $i }}" placeholder="Legenda...">
                        </div>
                        <div class="card-footer p-1 text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0" wire:click="removerFoto({{ $i }})">
                                <i class="bx bx-trash"></i> Remover
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 5 — Revisão --}}
            {{-- ============================================================ --}}
            @if($etapa === '5')
            <h6 class="mb-3">Revisão</h6>

            <dl class="row mb-3">
                <dt class="col-sm-3">Período</dt>
                <dd class="col-sm-9">{{ \Carbon\Carbon::parse($periodoReferencia)->format('d/m/Y') }}</dd>
                <dt class="col-sm-3">Linha de Base</dt>
                <dd class="col-sm-9">{{ $linhaBaseId ? $this->linhasBase->firstWhere('id', $linhaBaseId)?->nome : 'Última importação de linha de base' }}</dd>
                <dt class="col-sm-3">Realizado/Tendência</dt>
                <dd class="col-sm-9">
                    @if($avancoImportacaoId)
                        {{ $this->avancosDisponiveis->firstWhere('id', $avancoImportacaoId)?->importado_em?->format('d/m/Y H:i') }}
                    @else
                        Última importação de avanço
                    @endif
                </dd>
                <dt class="col-sm-3">Curvas</dt>
                <dd class="col-sm-9">
                    <ul class="mb-0 ps-3">
                        @foreach($ordemCurvas as $chave)
                        <li>
                            {{ $this->tituloCurva($chave) }}
                            <span class="text-muted small">({{ count($pontosPorCurva[$chave] ?? []) }} ponto(s) de atenção)</span>
                        </li>
                        @endforeach
                    </ul>
                </dd>
                <dt class="col-sm-3">Fotos</dt>
                <dd class="col-sm-9">{{ count($novasFotos) }} foto(s) anexada(s)</dd>
            </dl>

            <div class="alert alert-warning py-2">
                <i class="bx bx-info-circle me-1"></i>
                O report será salvo como <strong>rascunho</strong> — só o setor de planejamento verá até você clicar em "Emitir" na página de detalhe.
            </div>
            @endif

        </div>

        {{-- ------------------------------------------------------------------ --}}
        {{-- Navegação --}}
        {{-- ------------------------------------------------------------------ --}}
        <div class="card-footer d-flex justify-content-between">
            <button type="button" class="btn btn-outline-secondary" wire:click="voltar" @disabled($etapa === '1')>
                <i class="bx bx-chevron-left me-1"></i>Voltar
            </button>

            @if($etapa !== '5')
            <button type="button" class="btn btn-primary" wire:click="avancar">
                Próximo<i class="bx bx-chevron-right ms-1"></i>
            </button>
            @else
            <button type="button" class="btn btn-success" wire:click="salvar" wire:loading.attr="disabled">
                <i class="bx bx-save me-1"></i>Salvar rascunho
            </button>
            @endif
        </div>
    </div>

</div>

@include('pages.radar._partials.relatorio-grafico-config')

@script
<script>
    $wire.on('show-toast', ({ message }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            toastr.success(message);
        }
    });

    // IMPORTANTE: este bloco de script roda UMA VEZ só, na primeira
    // renderização do componente (ainda no passo 1, com $ordemCurvas
    // vazio) — um JSON estático embutido aqui dentro ficaria CONGELADO
    // nesse estado vazio inicial pra sempre, mesmo depois do usuário
    // escolher curvas no passo 2 (bug real: a prévia nunca aparecia).
    // Por isso os dados são buscados AO VIVO via
    // $wire.dadosGraficosPreviewParaJs() (chamada normal de ação Livewire)
    // toda vez que o passo 3 é alcançado, nunca embutidos como JSON
    // estático no script.
    const [limiarAtencao, limiarOtimo] = @json($this->limiaresAderencia());

    // Guarda defensiva compartilhada: Chart.getChart evita erro de "canvas
    // already in use" em qualquer edge case de re-render (o wrapper de
    // cada canvas tem wire:ignore, então o Livewire não deveria recriá-lo
    // sozinho, mas isso protege contra qualquer cenário residual).
    const desenharDuploEixo = (idCanvas, dados, cfg) => {
        const canvas = document.getElementById(idCanvas);
        if (!canvas || !dados.labels.length) return;

        window.Chart.getChart(canvas)?.destroy();
        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: dados.labels,
                datasets: cfg.construirDatasets(dados.barras, dados.linhas),
            },
            options: cfg.opcoesDuploEixo(),
        });
    };

    const renderizarPreview = (previewData) => {
        const cfg = window.RelatorioGraficoConfig;

        previewData.forEach((curva) => {
            desenharDuploEixo(`chart-preview-${curva.chave}`, curva.mensal, cfg);
            desenharDuploEixo(`chart-preview-semanal-${curva.chave}`, curva.semanal, cfg);

            const canvasAderencia = document.getElementById(`chart-preview-aderencia-${curva.chave}`);
            if (canvasAderencia) {
                window.Chart.getChart(canvasAderencia)?.destroy();
                const config = cfg.gaugeConfig(limiarAtencao, limiarOtimo, 100);
                config.data.datasets[0].needleValue = curva.aderencia_atual;

                new window.Chart(canvasAderencia, {
                    ...config,
                    plugins: [cfg.pluginAgulha, cfg.pluginTextoCentral],
                });
            }
        });
    };

    const buscarEDesenharPreview = () => {
        // Nota: usar requestAnimationFrame (não $wire.$nextTick) — $nextTick
        // não existe como método JS do $wire nesta versão do Livewire
        // instalada, e sendo tratado como uma chamada de ação remota, o que
        // gera MethodNotFoundException no servidor. requestAnimationFrame
        // adia a execução pro próximo frame, depois que o Livewire já
        // atualizou o DOM (canvas já existe quando o Chart.js for criado).
        requestAnimationFrame(() => {
            $wire.dadosGraficosPreviewParaJs().then(renderizarPreview).catch((erro) => {
                // Bug real já visto aqui: window.Chart indefinido (Chart.js
                // não incluído nesta página) fazia a promise falhar em
                // silêncio — nenhum erro visível, nenhuma curva desenhada.
                // Nunca deixar essa falha sumir sem rastro de novo.
                console.error('Falha ao montar a prévia da curva do report:', erro);
            });
        });
    };

    $wire.$watch('etapa', (valor) => {
        if (valor === '3') {
            buscarEDesenharPreview();
        }
    });

    if ($wire.etapa === '3') {
        buscarEDesenharPreview();
    }
</script>
@endscript
