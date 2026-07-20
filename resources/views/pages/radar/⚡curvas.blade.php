<?php

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\CurvaAjuste;
use App\Models\Disciplina;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\Work;
use App\Services\CurvaAvanco;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
    use ExecutaComTransacaoSegura;

    public Work $obra;

    public string  $granularidade = 'mensal';
    public ?string $pacoteId      = null;
    public ?string $etapaId       = null;
    public ?string $disciplinaId  = null;
    public ?string $frenteId      = null;
    public ?string $faturamentoDiretoFiltro = null; // '' = todos | '1' = sim | '0' = não
    public ?string $entregavelId  = null;
    public ?string $equipeResponsavelId = null;
    public ?string $personalizado1Id = null;
    public ?string $personalizado2Id = null;
    public ?string $personalizado3Id = null;
    public ?string $personalizado4Id = null;
    public ?string $personalizado5Id = null;
    public ?string $linhaBaseId   = null;

    // Modal de edição
    public bool    $modalEdicao     = false;
    public ?string $editandoPeriodo = null;
    public string  $valorEdicao     = '';
    public string  $motivoEdicao    = '';

    // Modal de detalhe do período (aberto ao clicar numa barra do gráfico)
    public bool    $modalDetalhePeriodo    = false;
    public ?string $periodoDetalhado       = null;
    public array   $atividadesPeriodo      = [];
    public array   $arvorePeriodo          = [];
    public array   $resumoDisciplinaPeriodo = [];

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        // Esta tela é sempre sobre Previsto (linha de base) — sem uma
        // Linha de Base salva de propósito pelo usuário, não mostra
        // nenhum dado (nunca cai no fallback "última importação" do
        // serviço, que existe pra Dashboard/Report, não pra cá — ver
        // CurvaAvanco::resolverImportacaoId()).
        //
        // Se veio de "Linhas de Base" com uma linha específica solicitada
        // (?linha_base_id=...), usa ela — senão pré-seleciona a mais
        // recente salva. Sem isso, o link "Ver curvas usando esta linha
        // de base" sempre mostrava a MAIS RECENTE, ignorando qual linha o
        // usuário realmente clicou.
        $linhaBaseSolicitadaId = request()->query('linha_base_id');
        $linhaBaseSolicitada = $linhaBaseSolicitadaId
            ? LinhaBase::where('obra_id', $obra->id)->where('id', $linhaBaseSolicitadaId)->value('id')
            : null;

        // Ordena por id (ULID, ordenável cronologicamente) em vez de
        // created_at, que pode empatar quando duas linhas de base são
        // criadas no mesmo segundo.
        $this->linhaBaseId = $linhaBaseSolicitada
            ?? LinhaBase::where('obra_id', $obra->id)->orderByDesc('id')->value('id');
    }

    /** Converte o filtro tri-state ('' | '1' | '0') pro ?bool que o serviço espera. */
    private function faturamentoDiretoBool(): ?bool
    {
        return $this->faturamentoDiretoFiltro === null || $this->faturamentoDiretoFiltro === ''
            ? null
            : $this->faturamentoDiretoFiltro === '1';
    }

    #[Computed]
    public function periodos(): array
    {
        if (!$this->linhaBaseId) {
            return [];
        }

        return app(CurvaAvanco::class)->calcular(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::from($this->granularidade),
            $this->pacoteId,
            $this->linhaBaseId,
            null,
            $this->etapaId,
            $this->disciplinaId,
            $this->frenteId,
            $this->entregavelId,
            $this->equipeResponsavelId,
            $this->personalizado1Id,
            $this->personalizado2Id,
            $this->personalizado3Id,
            $this->personalizado4Id,
            $this->personalizado5Id,
            $this->faturamentoDiretoBool(),
        );
    }

    #[Computed]
    public function dadosGraficoCurvaS(): array
    {
        $meses = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];

        $labels              = [];
        $periodosInicio      = [];
        $percentualPeriodo   = [];
        $percentualAcumulado = [];

        foreach ($this->periodos as $p) {
            $data = \Carbon\Carbon::parse($p['periodo_inicio']);
            $labels[] = $this->granularidade === 'mensal'
                ? strtoupper($meses[$data->month - 1]) . '/' . $data->format('y')
                : 'Sem ' . $data->format('d/m/Y');
            $periodosInicio[]      = $p['periodo_inicio'];
            $percentualPeriodo[]   = round((float) $p['percentual_periodo'], 1);
            $percentualAcumulado[] = round((float) $p['percentual'], 1);
        }

        return [
            'serie'               => 'previsto',
            'labels'              => $labels,
            'periodosInicio'      => $periodosInicio,
            'percentualPeriodo'   => $percentualPeriodo,
            'percentualAcumulado' => $percentualAcumulado,
        ];
    }

    // Reavalia a prévia (periodos + gráfico) e avisa o front — chamado por
    // toda ação que muda o que a tabela/gráfico mostram. O @script roda só
    // uma vez no mount, então o gráfico precisa deste evento pra se
    // redesenhar em cada atualização subsequente (não só na primeira).
    private function recarregarPeriodos(): void
    {
        unset($this->periodos);
        $this->dispatch('curva-atualizada', dados: $this->dadosGraficoCurvaS);
    }

    #[Computed]
    public function linhasBase(): \Illuminate\Support\Collection
    {
        return LinhaBase::where('obra_id', $this->obra->id)
            ->with('importacao:id,data_status,importado_em')
            ->orderByDesc('id')
            ->get(['id', 'nome', 'cronograma_importacao_id']);
    }

    #[Computed]
    public function pacotesRaiz(): \Illuminate\Support\Collection
    {
        return PacoteTrabalho::where('obra_id', $this->obra->id)
            ->whereNull('parent_id')
            ->orderBy('nome')
            ->get(['id', 'nome', 'codigo']);
    }

    #[Computed]
    public function etapas(): \Illuminate\Support\Collection
    {
        return Etapa::where('obra_id', $this->obra->id)
            ->orderBy('nome')
            ->get(['id', 'nome']);
    }

    #[Computed]
    public function disciplinas(): \Illuminate\Support\Collection
    {
        return Disciplina::orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function frentes(): \Illuminate\Support\Collection
    {
        return FrenteTrabalho::where('obra_id', $this->obra->id)
            ->orderBy('nome')
            ->get(['id', 'nome']);
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

    public function linhaBaseSelecionada(): ?LinhaBase
    {
        if (!$this->linhaBaseId) return null;
        return $this->linhasBase->firstWhere('id', $this->linhaBaseId);
    }

    public function limparFiltros(): void
    {
        $this->pacoteId     = null;
        $this->etapaId      = null;
        $this->disciplinaId = null;
        $this->frenteId     = null;
        $this->faturamentoDiretoFiltro = null;
        $this->entregavelId = null;
        $this->equipeResponsavelId = null;
        $this->personalizado1Id = null;
        $this->personalizado2Id = null;
        $this->personalizado3Id = null;
        $this->personalizado4Id = null;
        $this->personalizado5Id = null;
        $this->recarregarPeriodos();
    }

    public function temFiltrosAtivos(): bool
    {
        return (bool) ($this->pacoteId || $this->etapaId || $this->disciplinaId || $this->frenteId
            || $this->faturamentoDiretoBool() !== null
            || $this->entregavelId || $this->equipeResponsavelId
            || $this->personalizado1Id || $this->personalizado2Id || $this->personalizado3Id
            || $this->personalizado4Id || $this->personalizado5Id);
    }

    public function abrirDetalhePeriodo(string $periodo): void
    {
        $curvaAvanco = app(CurvaAvanco::class);

        $this->periodoDetalhado  = $periodo;
        $this->atividadesPeriodo = $curvaAvanco->detalhePeriodo(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::from($this->granularidade),
            $periodo,
            $this->pacoteId,
            $this->linhaBaseId,
            null,
            $this->etapaId,
            $this->disciplinaId,
            $this->frenteId,
            $this->entregavelId,
            $this->equipeResponsavelId,
            $this->personalizado1Id,
            $this->personalizado2Id,
            $this->personalizado3Id,
            $this->personalizado4Id,
            $this->personalizado5Id,
            $this->faturamentoDiretoBool(),
        );
        $this->arvorePeriodo           = $curvaAvanco->hierarquizarAtividades($this->obra, $this->atividadesPeriodo);
        $this->resumoDisciplinaPeriodo = $curvaAvanco->resumoPorDisciplina($this->atividadesPeriodo);
        $this->modalDetalhePeriodo     = true;

        // Dispatch depois de abrir o modal, pra o canvas já existir no
        // DOM (fica dentro do bloco condicional do modal) quando o JS
        // reagir ao evento.
        $this->dispatch('periodo-detalhado', dados: $this->resumoDisciplinaPeriodo);
    }

    /** Linha de $this->periodos que bate com o período aberto no modal — alimenta os cards de resumo. */
    private function periodoAtualResumo(): ?array
    {
        return collect($this->periodos)->firstWhere('periodo_inicio', $this->periodoDetalhado);
    }

    public function exportarDetalhePeriodo(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $resumo = $this->periodoAtualResumo();

        $linhas = [];

        $linhas[] = ['Resumo do Período', '', ''];
        $linhas[] = ['Período', \Carbon\Carbon::parse($this->periodoDetalhado)->format('d/m/Y'), ''];
        $linhas[] = ['Linha de Base', $this->linhaBaseSelecionada()?->nome ?? '—', ''];
        $linhas[] = ['% do Período', number_format($resumo['percentual_periodo'] ?? 0, 1) . '%', ''];
        $linhas[] = ['% Acumulado', number_format($resumo['percentual'] ?? 0, 1) . '%', ''];
        $linhas[] = ['HH do Período', number_format($resumo['horas_exibir'] ?? 0, 2), ''];
        $linhas[] = ['Atividades no Período', count($this->atividadesPeriodo), ''];
        $linhas[] = ['', '', ''];

        $linhas[] = ['Atividades', 'Disciplina/Etapa/Frente', 'HH / %'];
        foreach ($this->arvorePeriodo as $linha) {
            if ($linha['tipo'] === 'pacote') {
                $linhas[] = [str_repeat('  ', $linha['nivel']) . '📁 ' . $linha['nome'], '', ''];
                continue;
            }

            $a = $linha['dados'];
            $linhas[] = [
                str_repeat('  ', $linha['nivel']) . ($a['codigo_cronograma'] ? "[{$a['codigo_cronograma']}] " : '') . $a['nome'],
                trim(collect([$a['disciplina'], $a['etapa'], $a['frente']])->filter()->implode(' / ')),
                number_format($a['horas'], 2) . ' HH (' . number_format($a['percentual'], 1) . '%)',
            ];
        }

        $linhas[] = ['', '', ''];
        $linhas[] = ['Resumo por Disciplina', '', ''];
        foreach ($this->resumoDisciplinaPeriodo as $d) {
            $linhas[] = [$d['disciplina'], '', number_format($d['horas'], 2) . ' HH (' . number_format($d['percentual'], 1) . '%)'];
        }

        $cabecalho = ['Item', 'Classificação', 'Valor'];
        $dados     = array_merge([$cabecalho], $linhas);

        $nomeArquivo = "detalhe-periodo-{$this->obra->id}-{$this->periodoDetalhado}.xlsx";

        return Excel::download(
            new class($dados) implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings {
                public function __construct(private array $rows) {}
                public function array(): array { return array_slice($this->rows, 1); }
                public function headings(): array { return $this->rows[0]; }
            },
            $nomeArquivo
        );
    }

    public function abrirEdicao(string $periodo, float $horasAtual): void
    {
        $this->editandoPeriodo = $periodo;
        $this->valorEdicao     = (string) $horasAtual;
        $this->motivoEdicao    = '';
        $this->modalEdicao     = true;
    }

    public function salvarAjuste(): void
    {
        $this->validate(['valorEdicao' => 'required|numeric|min:0']);

        $periodoAtual   = collect($this->periodos)->firstWhere('periodo_inicio', $this->editandoPeriodo);
        $valorCalculado = $periodoAtual['horas'] ?? 0;

        $this->transacaoSegura(fn () => CurvaAjuste::updateOrCreate(
            [
                'obra_id'            => $this->obra->id,
                'serie'              => 'previsto',
                'granularidade'      => $this->granularidade,
                'periodo_inicio'     => $this->editandoPeriodo,
                'pacote_trabalho_id' => $this->pacoteId,
                'etapa_id'           => $this->etapaId,
                'disciplina_id'      => $this->disciplinaId,
                'frente_trabalho_id' => $this->frenteId,
                'entregavel_id'      => $this->entregavelId,
                'equipe_responsavel_id' => $this->equipeResponsavelId,
                'personalizado_1_id' => $this->personalizado1Id,
                'personalizado_2_id' => $this->personalizado2Id,
                'personalizado_3_id' => $this->personalizado3Id,
                'personalizado_4_id' => $this->personalizado4Id,
                'personalizado_5_id' => $this->personalizado5Id,
                'faturamento_direto' => $this->faturamentoDiretoBool(),
            ],
            [
                'valor_ajustado'            => (float) $this->valorEdicao,
                'valor_calculado_no_ajuste' => $valorCalculado,
                'motivo'                    => $this->motivoEdicao ?: null,
                'ajustado_por'              => auth()->id(),
            ]
        ));

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->modalEdicao = false;
        $this->recarregarPeriodos();
        $this->dispatch('show-toast', message: 'Ajuste salvo.');
    }

    public function removerAjuste(string $ajusteId): void
    {
        $ajuste = CurvaAjuste::findOrFail($ajusteId);

        $this->transacaoSegura(fn () => $ajuste->delete());

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->recarregarPeriodos();
        $this->dispatch('show-toast', message: 'Ajuste removido.');
    }

    public function exportarExcel(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $periodos = $this->periodos;
        $gran     = ['mensal' => 'Mensal', 'semanal' => 'Semanal'][$this->granularidade];

        $cabecalho = ['Período', 'HH Calc.', 'HH Calc. Acum.', 'HH Exibido', 'HH Exib. Acum.', '% Período', '% Acumulado'];

        $linhas = collect($periodos)->map(function ($p) {
            $label = $this->granularidade === 'mensal'
                ? \Carbon\Carbon::parse($p['periodo_inicio'])->locale('pt_BR')->isoFormat('MMM/YY')
                : 'Sem ' . \Carbon\Carbon::parse($p['periodo_inicio'])->format('d/m/Y');
            return [
                strtoupper($label),
                $p['horas'],
                $p['acumulado_calculado'],
                $p['horas_exibir'],
                $p['acumulado'],
                $p['percentual_periodo'],
                $p['percentual'],
            ];
        })->toArray();

        $dados = array_merge([$cabecalho], $linhas);

        $nomeArquivo = "curva-s-{$this->obra->id}-{$this->linhaBaseId}-{$this->granularidade}.xlsx";

        return Excel::download(
            new class($dados) implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings {
                public function __construct(private array $rows) {}
                public function array(): array { return array_slice($this->rows, 1); }
                public function headings(): array { return $this->rows[0]; }
            },
            $nomeArquivo
        );
    }

    public function updatedGranularidade(): void { $this->recarregarPeriodos(); }
    public function updatedPacoteId(): void      { $this->recarregarPeriodos(); }
    public function updatedEtapaId(): void       { $this->recarregarPeriodos(); }
    public function updatedDisciplinaId(): void  { $this->recarregarPeriodos(); }
    public function updatedFrenteId(): void      { $this->recarregarPeriodos(); }
    public function updatedFaturamentoDiretoFiltro(): void { $this->recarregarPeriodos(); }
    public function updatedEntregavelId(): void  { $this->recarregarPeriodos(); }
    public function updatedEquipeResponsavelId(): void { $this->recarregarPeriodos(); }
    public function updatedPersonalizado1Id(): void { $this->recarregarPeriodos(); }
    public function updatedPersonalizado2Id(): void { $this->recarregarPeriodos(); }
    public function updatedPersonalizado3Id(): void { $this->recarregarPeriodos(); }
    public function updatedPersonalizado4Id(): void { $this->recarregarPeriodos(); }
    public function updatedPersonalizado5Id(): void { $this->recarregarPeriodos(); }
    public function updatedLinhaBaseId(): void   { $this->recarregarPeriodos(); }
};

?>

<div class="position-relative">

    {{-- Overlay de carregamento — cobre a tela inteira (filtros, gráfico e
         tabela) durante QUALQUER requisição do componente (troca de filtro,
         salvar/remover ajuste, limpar filtros, exportar), pra deixar claro
         que algo está em andamento e evitar clique duplo/errado nesse meio
         tempo. Sem wire:target de propósito — qualquer ação daqui deve
         travar a tela, não só uma específica. wire:loading.delay usa o
         delay padrão do Livewire (~200ms) pra não piscar em respostas rápidas.

         IMPORTANTE: o CSS que o Livewire injeta só cobre modificadores
         isolados (wire:loading, wire:loading.delay, wire:loading.flex...) —
         a combinação wire:loading.delay.flex NÃO está nessa lista, então
         nunca fica escondida por padrão e o overlay ficava visível pra
         sempre. Por isso aqui é só wire:loading.delay (puro, coberto pela
         regra padrão) no elemento que controla show/hide, com uma div
         INTERNA (que não é alvo do wire:loading) cuidando do d-flex de
         centralização — sem risco de "d-flex !important" atropelar o
         display:none que o Livewire aplica por padrão. --}}
    <div wire:loading.delay
         class="position-absolute top-0 start-0 w-100 h-100"
         style="z-index: 20;">
        <div class="d-flex align-items-center justify-content-center w-100 h-100"
             style="background: rgba(255,255,255,.7);">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Carregando...</span>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Selo de transparência --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="alert alert-light border d-flex align-items-center gap-2 py-2 mb-4">
        <i class="bx bx-info-circle text-primary"></i>
        <small>
            <strong>Valores reconstruídos do cronograma.</strong>
            A distribuição mensal é calculada pelo ponto médio de cada bloco faseado do MSPDI
            e pode ter desvio de fronteira de até <strong>~0,3%/mês</strong> vs. o MS Project.
            Os <strong>totais fecham exatos</strong>. Células marcadas com
            <span class="badge bg-info text-dark">ajustado</span> usam valor manual do MS Project.
        </small>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Sem nenhuma Linha de Base salva ainda — não mostra filtros nem
         gráfico/tabela (evita a inconsistência de exibir uma distribuição
         calculada a partir de uma importação que o usuário nunca chegou
         a "abençoar" explicitamente como linha de base). --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($this->linhasBase->isEmpty())
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bx bx-bookmark-alt fs-1 mb-2 d-block"></i>
            Nenhuma linha de base salva ainda para esta obra.
            <br>Importe um cronograma e depois salve uma Linha de Base para acompanhar a curva S.
            <div class="mt-3">
                <a href="{{ route('radar.linhas-base') }}" class="btn btn-sm btn-primary">
                    <i class="bx bx-bookmark-plus me-1"></i>Ir para Linhas de Base
                </a>
            </div>
        </div>
    </div>
    @else

    {{-- ------------------------------------------------------------------ --}}
    {{-- Tabela de períodos --}}
    {{-- ------------------------------------------------------------------ --}}
    @if(empty($this->periodos))
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bx bx-line-chart fs-1 mb-2 d-block"></i>
            Nenhum dado de avanço disponível. Importe um cronograma primeiro.
        </div>
    </div>
    @else
    {{-- ------------------------------------------------------------------ --}}
    {{-- Gráfico — mesmos dados da tabela abaixo, atualizado junto com ela --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0">Curva S — {{ $this->linhaBaseSelecionada()?->nome }}</h6>
        </div>
        <div class="card-body">
            <div wire:ignore style="height: 320px;">
                <canvas id="grafico-curva-s"></canvas>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <h6 class="mb-0">Curva S — {{ $this->linhaBaseSelecionada()?->nome }}
                / {{ ['mensal'=>'Mensal', 'semanal'=>'Semanal'][$granularidade] }}
            </h6>
            @if($linhaBaseId)
            <span class="badge bg-primary ms-1">
                <i class="bx bx-bookmark me-1"></i>{{ $this->linhaBaseSelecionada()?->nome }}
            </span>
            @endif
            @if($etapaId || $disciplinaId || $frenteId || $pacoteId)
            <span class="badge bg-warning text-dark ms-1">
                <i class="bx bx-filter-alt me-1"></i>Filtrado
            </span>
            @endif
            <span class="badge bg-label-secondary ms-auto">{{ count($this->periodos) }} períodos</span>
            <button class="btn btn-sm btn-outline-success" wire:click="exportarExcel">
                <i class="bx bx-download me-1"></i>Exportar Excel
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Período</th>
                        <th class="text-end">HH Calc.</th>
                        <th class="text-end">HH Calc. Acum.</th>
                        <th class="text-end">HH Exibido</th>
                        <th class="text-end">HH Exib. Acum.</th>
                        <th class="text-end">% Período</th>
                        <th class="text-end">% Acumulado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->periodos as $p)
                    @php
                        $dataCarbon = \Carbon\Carbon::parse($p['periodo_inicio']);
                        $meses = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
                        $labelPeriodo = $granularidade === 'mensal'
                            ? strtoupper($meses[$dataCarbon->month - 1]) . '/' . $dataCarbon->format('y')
                            : 'Sem ' . $dataCarbon->format('d/m/Y');
                    @endphp
                    <tr class="{{ $p['obsoleto'] ? 'table-warning' : '' }}">
                        <td class="fw-semibold">{{ $labelPeriodo }}</td>
                        <td class="text-end font-monospace">{{ number_format($p['horas'], 2) }}</td>
                        <td class="text-end font-monospace text-muted">{{ number_format($p['acumulado_calculado'], 2) }}</td>
                        <td class="text-end font-monospace">
                            {{ number_format($p['horas_exibir'], 2) }}
                            @if($p['ajustado'])
                                <span class="badge bg-info text-dark ms-1"
                                      data-bs-toggle="tooltip"
                                      title="Valor original (calculado): {{ number_format($p['valor_original'], 2) }} HH — ajustado por {{ $p['ajustado_por'] ?? 'n/a' }}">
                                    ajustado
                                </span>
                                @if($p['obsoleto'])
                                    <i class="bx bx-error text-warning ms-1"
                                       data-bs-toggle="tooltip"
                                       title="O valor calculado mudou após reimportação. Revise este ajuste."></i>
                                @endif
                            @endif
                        </td>
                        <td class="text-end font-monospace text-muted">{{ number_format($p['acumulado'], 2) }}</td>
                        <td class="text-end">{{ number_format($p['percentual_periodo'], 1) }}%</td>
                        <td class="text-end">
                            <span class="fw-semibold">{{ number_format($p['percentual'], 1) }}%</span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary py-0"
                                    wire:click="abrirDetalhePeriodo('{{ $p['periodo_inicio'] }}')"
                                    title="Ver atividades deste período">
                                <i class="bx bx-list-ul"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary py-0"
                                    wire:click="abrirEdicao('{{ $p['periodo_inicio'] }}', {{ $p['horas'] }})"
                                    title="{{ $p['ajustado'] ? 'Editar ajuste' : 'Ajustar manualmente' }}">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @if($p['ajustado'])
                            <button class="btn btn-sm btn-outline-danger py-0 ms-1"
                                    wire:click="removerAjuste('{{ $p['ajuste_id'] }}')"
                                    title="Remover ajuste (volta ao valor calculado)"
                                    wire:confirm="Remover o ajuste manual e voltar ao valor calculado?">
                                <i class="bx bx-x"></i>
                            </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal de edição --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($modalEdicao)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Ajustar período</h6>
                    <button type="button" class="btn-close" wire:click="$set('modalEdicao', false)"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Crave aqui o valor que o MS Project exibe para
                        <strong>{{ $editandoPeriodo }}</strong>.
                        O valor calculado fica guardado para auditoria.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">HH ajustados</label>
                        <input type="number" step="0.01" min="0"
                               class="form-control @error('valorEdicao') is-invalid @enderror"
                               wire:model="valorEdicao">
                        @error('valorEdicao') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Motivo <span class="text-muted">(opcional)</span></label>
                        <input type="text" class="form-control" wire:model="motivoEdicao"
                               placeholder="ex: valor do relatório mensal">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary btn-sm"
                            wire:click="$set('modalEdicao', false)">Cancelar</button>
                    <button class="btn btn-primary btn-sm" wire:click="salvarAjuste"
                            wire:loading.attr="disabled" wire:target="salvarAjuste">Salvar</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Modal de detalhe do período (aberto pela barra do gráfico ou pelo
         ícone da tabela) — mostra as atividades que compõem aquele período,
         respeitando os mesmos filtros (pacote/etapa/disciplina/frente) já
         aplicados na tela. --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($modalDetalhePeriodo)
    @php $resumoPeriodo = $this->periodoAtualResumo(); @endphp
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-dialog-scrollable modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">
                        <i class="bx bx-list-ul me-1"></i>Detalhe do período
                        <span class="text-muted">— {{ \Carbon\Carbon::parse($periodoDetalhado)->format('d/m/Y') }}</span>
                    </h6>
                    <button type="button" class="btn-close" wire:click="$set('modalDetalhePeriodo', false)"></button>
                </div>
                <div class="modal-body">
                    @if(empty($atividadesPeriodo))
                    <p class="text-muted text-center py-4 mb-0">
                        Nenhuma atividade com HH lançado neste período (com os filtros atuais).
                    </p>
                    @else

                    {{-- Cards de resumo --}}
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="card h-100 bg-label-primary">
                                <div class="card-body">
                                    <p class="text-muted mb-1">% do Período</p>
                                    <h3 class="mb-0">{{ number_format($resumoPeriodo['percentual_periodo'] ?? 0, 1) }}%</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100 bg-label-success">
                                <div class="card-body">
                                    <p class="text-muted mb-1">% Acumulado</p>
                                    <h3 class="mb-0">{{ number_format($resumoPeriodo['percentual'] ?? 0, 1) }}%</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100 bg-label-secondary">
                                <div class="card-body">
                                    <p class="text-muted mb-1">HH do Período</p>
                                    <h3 class="mb-0">{{ number_format($resumoPeriodo['horas_exibir'] ?? 0, 2) }}</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100 bg-label-warning">
                                <div class="card-body">
                                    <p class="text-muted mb-1">Atividades no Período</p>
                                    <h3 class="mb-0">{{ count($atividadesPeriodo) }}</h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Gráfico de % por disciplina --}}
                    <div class="card mb-3">
                        <div class="card-header py-2">
                            <span class="fw-semibold small">% de HH por Disciplina</span>
                        </div>
                        <div class="card-body">
                            @if(empty($resumoDisciplinaPeriodo))
                            <p class="text-muted text-center mb-0 py-3">Nenhuma disciplina classificada neste período.</p>
                            @else
                            <div wire:ignore style="height: 220px;">
                                <canvas id="grafico-disciplina-periodo"></canvas>
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- Atividades hierarquizadas conforme a EAP --}}
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Atividade</th>
                                    <th>Disciplina</th>
                                    <th>Etapa</th>
                                    <th>Frente</th>
                                    <th class="text-end">HH</th>
                                    <th class="text-end">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($arvorePeriodo as $linha)
                                @if($linha['tipo'] === 'pacote')
                                <tr class="table-light">
                                    <td colspan="6" style="padding-left: {{ $linha['nivel'] * 20 }}px" class="fw-semibold">
                                        <i class="bx bx-folder-open text-muted me-1"></i>
                                        {{ $linha['codigo'] ? "[{$linha['codigo']}] " : '' }}{{ $linha['nome'] }}
                                    </td>
                                </tr>
                                @else
                                @php $a = $linha['dados']; @endphp
                                <tr>
                                    <td style="padding-left: {{ $linha['nivel'] * 20 }}px">
                                        @if($a['codigo_cronograma'])
                                        <span class="text-muted font-monospace small">[{{ $a['codigo_cronograma'] }}]</span>
                                        @endif
                                        {{ $a['nome'] }}
                                    </td>
                                    <td>{{ $a['disciplina'] ?? '—' }}</td>
                                    <td>{{ $a['etapa'] ?? '—' }}</td>
                                    <td>{{ $a['frente'] ?? '—' }}</td>
                                    <td class="text-end font-monospace">{{ number_format($a['horas'], 2) }}</td>
                                    <td class="text-end">{{ number_format($a['percentual'], 1) }}%</td>
                                </tr>
                                @endif
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="4">Total</td>
                                    <td class="text-end font-monospace">{{ number_format(collect($atividadesPeriodo)->sum('horas'), 2) }}</td>
                                    <td class="text-end">100.0%</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary btn-sm"
                            wire:click="$set('modalDetalhePeriodo', false)">Fechar</button>
                    @if(!empty($atividadesPeriodo))
                    <button class="btn btn-outline-success btn-sm" wire:click="exportarDetalhePeriodo">
                        <i class="bx bx-download me-1"></i>Exportar Excel
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Canva lateral de filtros — mesmo padrão de Restrições/Lookahead/
         Plano Semanal/Suprimentos/Minhas Obras: overlay fixo com aba presa
         na borda direita. Só faz sentido com pelo menos 1 Linha de Base
         salva (sem isso não há nada pra filtrar). --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($this->linhasBase->isNotEmpty())
    <div class="canva-filtros-curvas" :class="filtrosAbertos ? 'canva-filtros-aberto' : ''" x-data="{ filtrosAbertos: false }">
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
                    <label class="form-label mb-1 small">Linha de Base</label>
                    <select class="form-select form-select-sm" wire:model.live="linhaBaseId">
                        @foreach($this->linhasBase as $lb)
                        <option value="{{ $lb->id }}">
                            {{ $lb->nome }}
                            @if($lb->importacao?->data_status)
                            ({{ $lb->importacao->data_status->format('d/m/Y') }})
                            @endif
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label mb-1 small">Granularidade</label>
                    <select class="form-select form-select-sm" wire:model.live="granularidade">
                        <option value="mensal">Mensal</option>
                        <option value="semanal">Semanal</option>
                    </select>
                </div>

                @if($this->pacotesRaiz->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Pacote (EAP)</label>
                    <select class="form-select form-select-sm" wire:model.live="pacoteId">
                        <option value="">Toda a obra</option>
                        @foreach($this->pacotesRaiz as $pt)
                        <option value="{{ $pt->id }}">{{ $pt->codigo ? "[$pt->codigo] " : '' }}{{ $pt->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->etapas->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Etapa</label>
                    <select class="form-select form-select-sm" wire:model.live="etapaId">
                        <option value="">Todas</option>
                        @foreach($this->etapas as $e)
                        <option value="{{ $e->id }}">{{ $e->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->disciplinas->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Disciplina</label>
                    <select class="form-select form-select-sm" wire:model.live="disciplinaId">
                        <option value="">Todas</option>
                        @foreach($this->disciplinas as $d)
                        <option value="{{ $d->id }}">{{ $d->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->frentes->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Frente de Trabalho</label>
                    <select class="form-select form-select-sm" wire:model.live="frenteId">
                        <option value="">Todas</option>
                        @foreach($this->frentes as $f)
                        <option value="{{ $f->id }}">{{ $f->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="col-12">
                    <label class="form-label mb-1 small">Faturamento Direto</label>
                    <select class="form-select form-select-sm" wire:model.live="faturamentoDiretoFiltro">
                        <option value="">Todos</option>
                        <option value="1">Sim</option>
                        <option value="0">Não</option>
                    </select>
                </div>
                @if($this->entregaveis->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Entregável</label>
                    <select class="form-select form-select-sm" wire:model.live="entregavelId">
                        <option value="">Todos</option>
                        @foreach($this->entregaveis as $en)
                        <option value="{{ $en->id }}">{{ $en->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->equipesResponsaveis->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Equipe/Responsável</label>
                    <select class="form-select form-select-sm" wire:model.live="equipeResponsavelId">
                        <option value="">Todas</option>
                        @foreach($this->equipesResponsaveis as $eq)
                        <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->personalizados1->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Personalizado 1</label>
                    <select class="form-select form-select-sm" wire:model.live="personalizado1Id">
                        <option value="">Todos</option>
                        @foreach($this->personalizados1 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->personalizados2->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Personalizado 2</label>
                    <select class="form-select form-select-sm" wire:model.live="personalizado2Id">
                        <option value="">Todos</option>
                        @foreach($this->personalizados2 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->personalizados3->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Personalizado 3</label>
                    <select class="form-select form-select-sm" wire:model.live="personalizado3Id">
                        <option value="">Todos</option>
                        @foreach($this->personalizados3 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->personalizados4->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Personalizado 4</label>
                    <select class="form-select form-select-sm" wire:model.live="personalizado4Id">
                        <option value="">Todos</option>
                        @foreach($this->personalizados4 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($this->personalizados5->isNotEmpty())
                <div class="col-12">
                    <label class="form-label mb-1 small">Personalizado 5</label>
                    <select class="form-select form-select-sm" wire:model.live="personalizado5Id">
                        <option value="">Todos</option>
                        @foreach($this->personalizados5 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                @if($this->temFiltrosAtivos())
                <div class="col-12">
                    <button class="btn btn-sm btn-outline-secondary w-100" wire:click="limparFiltros">
                        <i class="bx bx-x me-1"></i>Limpar filtros
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>

    <style>
    /* z-index:1080 fica ACIMA da navbar fixa do template (.layout-navbar,
       z-index:1075) e ABAIXO dos modais do Bootstrap (z-index:1090) —
       mesmo mecanismo já usado em Restrições/Lookahead/Plano Semanal/
       Suprimentos/Minhas Obras. IMPORTANTE: precisa ficar DENTRO da <div>
       raiz do componente Livewire, senão nunca chega no DOM do navegador. */
    .canva-filtros-curvas {
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

    .canva-filtros-curvas.canva-filtros-aberto {
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

    .canva-filtros-curvas.canva-filtros-aberto .canva-filtros-aba {
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
        .canva-filtros-curvas {
            width: 300px;
            right: -300px;
        }

        .canva-filtros-curvas.canva-filtros-aberto {
            right: 0;
        }
    }
    </style>
    @endif

</div>

@include('pages.radar._partials.relatorio-grafico-config')

@script
<script>
    let graficoCurvaS = null;
    let periodosInicioAtual = [];

    function desenharGraficoCurvaS(dados) {
        const canvas = document.getElementById('grafico-curva-s');
        periodosInicioAtual = dados.periodosInicio || [];

        if (!canvas) {
            if (graficoCurvaS) { graficoCurvaS.destroy(); graficoCurvaS = null; }
            return;
        }

        // A cada filtro trocado, o card (e o canvas dentro dele) pode ter
        // sumido e voltado (a tabela/gráfico ficam ocultos quando não há
        // períodos) — se o canvas é um nó novo, o Chart.js antigo, preso
        // ao nó antigo, não vale mais.
        if (graficoCurvaS && graficoCurvaS.canvas !== canvas) {
            graficoCurvaS.destroy();
            graficoCurvaS = null;
        }

        const cfg = window.RelatorioGraficoConfig;
        const cor = cfg.cores[dados.serie] ?? cfg.cores.previsto;

        // Ponto da linha "% acumulado": preenchimento branco, contorno na
        // mesma cor/espessura da linha (destaca o ponto sobre a linha).
        const espessuraLinha = 2;

        if (graficoCurvaS) {
            graficoCurvaS.data.labels = dados.labels;
            graficoCurvaS.data.datasets[0].data = dados.percentualPeriodo;
            graficoCurvaS.data.datasets[0].backgroundColor = cor.barra;
            graficoCurvaS.data.datasets[1].data = dados.percentualAcumulado;
            graficoCurvaS.data.datasets[1].borderColor = cor.linha;
            graficoCurvaS.data.datasets[1].backgroundColor = cor.linha;
            graficoCurvaS.data.datasets[1].pointBorderColor = cor.linha;
            graficoCurvaS.update();
            return;
        }

        // Clicar numa barra (% do período) abre o popup de detalhe daquele
        // período, respeitando os mesmos filtros já aplicados na tela —
        // periodosInicioAtual é a mesma ordem de dados.labels/percentualPeriodo,
        // então o índice do elemento clicado indexa direto pra data real.
        const opcoes = cfg.opcoesDuploEixo();
        opcoes.onClick = (event, elements) => {
            const barraClicada = elements.find((el) => graficoCurvaS.data.datasets[el.datasetIndex].type === 'bar');
            if (!barraClicada) return;

            const periodo = periodosInicioAtual[barraClicada.index];
            if (periodo) $wire.call('abrirDetalhePeriodo', periodo);
        };
        opcoes.onHover = (event, elements) => {
            const sobreBarra = elements.some((el) => graficoCurvaS.data.datasets[el.datasetIndex].type === 'bar');
            event.native.target.style.cursor = sobreBarra ? 'pointer' : 'default';
        };

        graficoCurvaS = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: dados.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: '% do período',
                        data: dados.percentualPeriodo,
                        backgroundColor: cor.barra,
                        yAxisID: 'yMensal',
                        order: 2,
                    },
                    {
                        type: 'line',
                        label: '% acumulado',
                        data: dados.percentualAcumulado,
                        borderColor: cor.linha,
                        backgroundColor: cor.linha,
                        borderWidth: espessuraLinha,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: cor.linha,
                        pointBorderWidth: espessuraLinha,
                        tension: 0.3,
                        spanGaps: true,
                        yAxisID: 'yAcumulado',
                        order: 1,
                    },
                ],
            },
            options: opcoes,
            plugins: [cfg.pluginRotulosDados],
        });
    }

    // Bug real: numa navegação wire:navigate (diferente de um full page
    // load), o <canvas> ainda não está no DOM no instante em que este
    // bloco roda — nem um queueMicrotask é suficiente (testado e
    // confirmado: o gap é maior que "esvaziar a call stack atual", só
    // fecha depois que o navigate.js do Livewire termina o morph e o
    // browser processa layout). requestAnimationFrame (mesmo padrão já
    // usado em relatorio-novo.blade.php) espera o próximo ciclo de paint
    // de verdade, não só a call stack — tenta por até 10 frames (~160ms)
    // antes de desistir, caso um único frame não seja suficiente num
    // dispositivo mais lento.
    (function aguardarCanvas(tentativas = 10) {
        if (document.getElementById('grafico-curva-s') || tentativas <= 0) {
            desenharGraficoCurvaS(@json($this->dadosGraficoCurvaS));
            return;
        }
        requestAnimationFrame(() => aguardarCanvas(tentativas - 1));
    })();

    $wire.on('curva-atualizada', ({ dados }) => desenharGraficoCurvaS(dados));

    // Bug pré-existente corrigido: esta página já disparava 'show-toast'
    // (salvarAjuste/removerAjuste) mas nunca teve um listener — os toasts
    // nunca apareciam.
    $wire.on('show-toast', ({ message, type = 'success' }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            (toastr[type] || toastr.success)(message);
        }
    });

    // Gráfico de % de HH por disciplina, dentro do popup de detalhe do
    // período — canvas só existe no DOM enquanto o modal está aberto
    // (bloco condicional do modal), então sempre destrói e recria (nunca
    // dá pra só fazer .update() num canvas que pode nem existir mais).
    let graficoDisciplinaPeriodo = null;

    function desenharGraficoDisciplinaPeriodo(dados) {
        if (graficoDisciplinaPeriodo) {
            graficoDisciplinaPeriodo.destroy();
            graficoDisciplinaPeriodo = null;
        }

        const canvas = document.getElementById('grafico-disciplina-periodo');
        if (!canvas || !dados.length) return;

        const cfg = window.RelatorioGraficoConfig;
        const paleta = cfg.paletaCategorias;

        graficoDisciplinaPeriodo = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: dados.map((d) => d.disciplina),
                datasets: [{
                    label: '% do período',
                    data: dados.map((d) => d.percentual),
                    backgroundColor: dados.map((_, i) => paleta[i % paleta.length]),
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { min: 0, max: 100, ticks: { callback: (v) => v + '%' } },
                },
                plugins: { legend: { display: false } },
            },
        });
    }

    $wire.on('periodo-detalhado', ({ dados }) => {
        // O canvas só existe depois do próximo paint (o modal acabou de
        // abrir nesta mesma resposta) — um microtask garante que o DOM já
        // foi atualizado antes de procurar o elemento.
        queueMicrotask(() => desenharGraficoDisciplinaPeriodo(dados));
    });
</script>
@endscript
