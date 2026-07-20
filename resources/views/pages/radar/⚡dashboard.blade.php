<?php

use App\Enums\GranularidadePeriodo;
use App\Enums\PilarLean;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\StatusReport;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CausaNaoCumprimento;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Restricao;
use App\Models\Work;
use App\Services\CurvaAvanco;
use App\Support\ObraContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dashboard gerencial — visão executiva de uma obra, cruzando dados do
 * Radar (Restrições/Atividades, ao vivo) com o último Report EMITIDO
 * (fotografia congelada, nunca recalculada — mesma filosofia do resto
 * do Report) e com a curva S calculada AO VIVO via App\Services\
 * CurvaAvanco (mesmo serviço usado na prévia do assistente de Report —
 * ver ⚡relatorio-novo.blade.php::dadosGraficosPreview()). v1 exploratória,
 * a ser refinada com o usuário nas próximas rodadas.
 */
new class extends Component {
    public Work $obra;

    /** 'obra' = curva geral (obra inteira); senão, id de um PacoteTrabalho até o 2º nível da EAP. */
    public string $curvaSelecionada = 'obra';

    private const ADERENCIA_LIMIAR_OTIMO = 95.0;
    private const ADERENCIA_LIMIAR_ATENCAO = 80.0;

    private const MESES_PT = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
    }

    #[Computed]
    public function obrasDisponiveis()
    {
        return Auth::user()->works()->orderBy('name')->get(['works.id', 'works.name']);
    }

    /**
     * Trocar de obra aqui é o mesmo "entrar na obra" usado no resto do
     * Radar (ObraContext::set) — troca o contexto da SESSÃO inteira, não
     * só um filtro local desta página, pra não divergir do que o navbar/
     * menu mostram como "obra atual". SEM navigate:true de propósito: a
     * URL de destino é a MESMA URL atual (não há obra na querystring), e
     * o wire:navigate do Livewire trata navegação pra URL idêntica como
     * no-op (não refaz o fetch) — por isso os dados não trocavam. Redirect
     * "cru" força o browser a recarregar de verdade mesmo com a URL igual.
     */
    public function trocarObra(string $obraId): void
    {
        $novaObra = Work::findOrFail($obraId);
        abort_unless(Auth::user()->is_platform_admin || Auth::user()->temAcessoAObra($novaObra), 403);

        ObraContext::set($novaObra);

        $this->redirect(route('radar.dashboard'));
    }

    #[Computed]
    public function totalAtividades(): int
    {
        return Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->count();
    }

    #[Computed]
    public function atividadesPorStatus(): array
    {
        $contagem = Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = ['planejado' => 'Planejado', 'comprometido' => 'Comprometido', 'em_execucao' => 'Em Execução', 'concluido' => 'Concluído', 'nao_concluido' => 'Não Concluído'];
        $cores = ['planejado' => '#a1acb8', 'comprometido' => '#696cff', 'em_execucao' => '#03c3ec', 'concluido' => '#71dd37', 'nao_concluido' => '#ff3e1d'];

        $dados = [];
        foreach ($labels as $valor => $label) {
            $qtd = (int) ($contagem[$valor] ?? 0);
            if ($qtd > 0) {
                $dados[] = ['label' => $label, 'valor' => $qtd, 'cor' => $cores[$valor]];
            }
        }

        return $dados;
    }

    #[Computed]
    public function atividadesProntasPercentual(): ?int
    {
        $base = Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->whereIn('status', [StatusAtividade::Planejado->value, StatusAtividade::Comprometido->value]);

        $totalPendentes = (clone $base)->count();
        if ($totalPendentes === 0) {
            return null;
        }

        $prontas = (clone $base)->prontas()->count();

        return (int) round($prontas / $totalPendentes * 100);
    }

    #[Computed]
    public function atividadesAtrasadas(): int
    {
        return Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->whereNotIn('status', [StatusAtividade::Concluido->value])
            ->whereNotNull('data_termino')
            ->where('data_termino', '<', now())
            ->count();
    }

    #[Computed]
    public function restricoesAbertas(): int
    {
        return Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->where('status', '!=', StatusRestricao::Resolvida->value)
            ->count();
    }

    #[Computed]
    public function restricoesBloqueantesAbertas(): int
    {
        return Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->where('status', '!=', StatusRestricao::Resolvida->value)
            ->where('bloqueante', true)
            ->count();
    }

    #[Computed]
    public function restricoesPorPilar(): array
    {
        $cores = [
            'materiais' => '#696cff',
            'mao_de_obra' => '#03c3ec',
            'equipamentos' => '#ffab00',
            'informacoes' => '#71dd37',
            'condicoes_precedentes' => '#ff3e1d',
        ];

        $contagem = Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->where('status', '!=', StatusRestricao::Resolvida->value)
            ->join('categorias_restricao', 'categorias_restricao.id', '=', 'restricoes.categoria_id')
            ->selectRaw('categorias_restricao.pilar_lean, count(*) as total')
            ->groupBy('categorias_restricao.pilar_lean')
            ->pluck('total', 'pilar_lean');

        $dados = [];
        foreach (PilarLean::cases() as $pilar) {
            $qtd = (int) ($contagem[$pilar->value] ?? 0);
            if ($qtd > 0) {
                $dados[] = ['label' => $this->labelPilar($pilar), 'valor' => $qtd, 'cor' => $cores[$pilar->value]];
            }
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
    public function restricoesMaisAntigas()
    {
        return Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->where('status', '!=', StatusRestricao::Resolvida->value)
            ->with('atividade:id,nome')
            ->orderBy('aberta_em')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'restricao' => $r,
                'diasAberta' => $r->aberta_em ? $r->aberta_em->diffInDays(now()) : null,
            ]);
    }

    #[Computed]
    public function causasTop5()
    {
        return CausaNaoCumprimento::with('atividade')
            ->whereHas('atividade', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->whereNull('deleted_at')
            ->get()
            ->groupBy(fn ($c) => mb_strtolower(trim($c->descricao)))
            ->map(fn ($grupo) => ['descricao' => $grupo->first()->descricao, 'total' => $grupo->count()])
            ->sortByDesc('total')
            ->take(5)
            ->values();
    }

    #[Computed]
    public function ultimoReportEmitido(): ?Report
    {
        return Report::where('obra_id', $this->obra->id)
            ->where('status', StatusReport::Emitido->value)
            ->with('curvas.datapoints')
            ->orderByDesc('periodo_referencia')
            ->first();
    }

    /**
     * Aderência da última semana atualizada — sempre a curva RAIZ
     * (pacote_trabalho_id null) do último report emitido, mesma
     * matemática de ⚡relatorio-detalhe.blade.php. Fica de fora do
     * seletor de curvas de propósito: aderência mede "como fomos vs. o
     * combinado" — só faz sentido comparado a um Report de verdade, não
     * dá pra calcular "ao vivo" sem um período de referência fechado.
     */
    #[Computed]
    public function resumoAderencia(): ?array
    {
        $report = $this->ultimoReportEmitido;
        if (! $report) {
            return null;
        }

        $curva = $report->curvas->firstWhere('pacote_trabalho_id', null);
        if (! $curva) {
            return null;
        }

        $semanais = $curva->datapoints->where('granularidade', GranularidadePeriodo::Semanal);
        $periodos = $semanais->pluck('periodo_inicio')->unique(fn ($d) => $d->toDateString())->sort()->values();
        $porSerie = $semanais->groupBy(fn ($d) => $d->serie->value);

        $porData = [];
        foreach (['previsto', 'realizado'] as $serieValue) {
            $porData[$serieValue] = ($porSerie->get($serieValue) ?? collect())->keyBy(fn ($d) => $d->periodo_inicio->toDateString());
        }

        $aderenciaAtual = null;
        $avancoRealAtual = null;

        foreach ($periodos->reverse() as $p) {
            $chave = $p->toDateString();
            $prev = $porData['previsto']->has($chave) ? (float) $porData['previsto']->get($chave)->percentual_acumulado : null;
            $real = $porData['realizado']->has($chave) ? (float) $porData['realizado']->get($chave)->percentual_acumulado : null;

            if ($avancoRealAtual === null && $real !== null) {
                $avancoRealAtual = $real;
            }
            if ($aderenciaAtual === null && $prev !== null && $prev > 0 && $real !== null) {
                $aderenciaAtual = round($real / $prev * 100, 2);
            }
        }

        return [
            'aderenciaAtual' => $aderenciaAtual,
            'avancoRealAtual' => $avancoRealAtual,
        ];
    }

    #[Computed]
    public function limiaresAderencia(): array
    {
        return [self::ADERENCIA_LIMIAR_ATENCAO, self::ADERENCIA_LIMIAR_OTIMO];
    }

    /**
     * Compara dois códigos de EAP (ex: "5.1.10" vs "5.1.3") segmento a
     * segmento como números — mesmo helper usado em Lookahead/Linhas de
     * Base/assistente de Report.
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

    /** Árvore de pacotes limitada aos 2 primeiros níveis da EAP (nivel 0 e 1). */
    #[Computed]
    public function arvorePacotesAteNivel2(): array
    {
        $todos = PacoteTrabalho::where('obra_id', $this->obra->id)->get(['id', 'nome', 'codigo', 'parent_id']);
        $porPai = $todos->groupBy('parent_id');

        $resultado = [];
        $percorrer = function ($paiId, $nivel) use (&$percorrer, &$resultado, $porPai) {
            if ($nivel > 1) {
                return;
            }

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
    public function curvasDisponiveis(): array
    {
        $opcoes = [['chave' => 'obra', 'label' => $this->obra->name . ' (Geral)']];

        foreach ($this->arvorePacotesAteNivel2 as $linha) {
            $prefixo = str_repeat('— ', $linha['nivel']);
            $codigo = $linha['pacote']->codigo ? $linha['pacote']->codigo . ' - ' : '';
            $opcoes[] = ['chave' => $linha['pacote']->id, 'label' => $prefixo . $codigo . $linha['pacote']->nome];
        }

        return $opcoes;
    }

    private function tituloCurvaSelecionada(): string
    {
        foreach ($this->curvasDisponiveis as $opcao) {
            if ($opcao['chave'] === $this->curvaSelecionada) {
                return $opcao['label'];
            }
        }

        return $this->obra->name;
    }

    /** Mesmo formato de rótulo de período usado no Report (⚡relatorio-detalhe.blade.php::formatarPeriodoPt). */
    private function formatarPeriodoPt(Carbon $data, GranularidadePeriodo $gran): string
    {
        if ($gran === GranularidadePeriodo::Mensal) {
            return self::MESES_PT[$data->month - 1] . '/' . $data->format('y');
        }

        return sprintf('SEM %02d/%d', $data->weekOfYear, $data->year);
    }

    /**
     * Curva S calculada AO VIVO (dados de tendência atuais, não uma
     * fotografia de Report) via App\Services\CurvaAvanco — mesmo serviço
     * e mesma correção de %realizado (rebasearPercentual) usados na
     * prévia do assistente de Report. Monta as DUAS granularidades
     * (mensal e semanal) — mensal pra visão geral do projeto inteiro,
     * semanal (já existia antes) continua disponível lado a lado.
     */
    #[Computed]
    public function curvaSGeral(): array
    {
        return [
            'titulo' => $this->tituloCurvaSelecionada(),
            'mensal' => $this->curvaSGeralPorGranularidade(GranularidadePeriodo::Mensal),
            'semanal' => $this->curvaSGeralPorGranularidade(GranularidadePeriodo::Semanal),
        ];
    }

    private function curvaSGeralPorGranularidade(GranularidadePeriodo $gran): array
    {
        $curvaAvanco = app(CurvaAvanco::class);
        $pacoteId = $this->curvaSelecionada !== 'obra' ? $this->curvaSelecionada : null;

        $totalHhPrevisto = $pacoteId
            ? (PacoteTrabalho::find($pacoteId)?->totalHhBaseline() ?? 0.0)
            : $curvaAvanco->totalCalculado($this->obra, SerieAvanco::Previsto, $gran, null);

        $pontosPorSerie = [];
        foreach (SerieAvanco::cases() as $serie) {
            $pontos = $curvaAvanco->calcular($this->obra, $serie, $gran, $pacoteId);
            $pontosPorSerie[$serie->value] = collect($curvaAvanco->rebasearPercentual($pontos, $totalHhPrevisto))
                ->keyBy('periodo_inicio');
        }

        $periodos = collect($pontosPorSerie)->flatMap(fn ($c) => $c->keys())->unique()->sort()->values();

        $labels = $periodos->map(fn ($p) => $this->formatarPeriodoPt(Carbon::parse($p), $gran))->all();

        $barras = [];
        $linhas = [];
        foreach (['previsto', 'tendencia', 'realizado'] as $chave) {
            $barras[$chave] = $periodos->map(fn ($p) => $pontosPorSerie[$chave]->has($p) ? (float) $pontosPorSerie[$chave]->get($p)['percentual_periodo'] : null)->all();
            $linhas[$chave] = $periodos->map(fn ($p) => $pontosPorSerie[$chave]->has($p) ? (float) $pontosPorSerie[$chave]->get($p)['percentual'] : null)->all();
        }

        return [
            'labels' => $labels,
            'barras' => $barras,
            'linhas' => $linhas,
        ];
    }

    public function curvaSGeralParaJs(): array
    {
        return $this->curvaSGeral;
    }

    public function updatedCurvaSelecionada(): void
    {
        $this->dispatch('curva-geral-atualizada', dados: $this->curvaSGeralParaJs());
    }

    public function dadosGraficosParaJs(): array
    {
        return [
            'atividadesPorStatus' => $this->atividadesPorStatus,
            'restricoesPorPilar' => $this->restricoesPorPilar,
            'resumoAderencia' => $this->resumoAderencia,
            'limiares' => $this->limiaresAderencia,
            'curvaSGeral' => $this->curvaSGeral,
        ];
    }
};
?>

@include('pages.radar._partials.relatorio-grafico-config')

<div>
    <div class="row g-3 mb-3 align-items-center">
        <div class="col-12 col-md-4">
            <label class="form-label mb-1 small text-muted">Obra</label>
            <select class="form-select" wire:change="trocarObra($event.target.value)">
                @foreach ($this->obrasDisponiveis as $o)
                <option value="{{ $o->id }}" @selected($o->id === $this->obra->id)>{{ $o->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @php $resumo = $this->resumoAderencia; @endphp
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 border-primary">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-primary">
                        {{ ($resumo['aderenciaAtual'] ?? null) !== null ? round($resumo['aderenciaAtual']) . '%' : '—' }}
                    </div>
                    <small class="text-muted">Aderência (última semana)</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 border-success">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-success">
                        {{ ($resumo['avancoRealAtual'] ?? null) !== null ? round($resumo['avancoRealAtual']) . '%' : '—' }}
                    </div>
                    <small class="text-muted">Avanço Físico Acumulado</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 {{ $this->restricoesBloqueantesAbertas > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold {{ $this->restricoesBloqueantesAbertas > 0 ? 'text-danger' : '' }}">
                        {{ $this->restricoesAbertas }}
                    </div>
                    <small class="text-muted">
                        Restrições Abertas
                        @if ($this->restricoesBloqueantesAbertas > 0)
                            <span class="badge bg-label-danger ms-1">{{ $this->restricoesBloqueantesAbertas }} bloq.</span>
                        @endif
                    </small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold">
                        {{ $this->atividadesProntasPercentual !== null ? $this->atividadesProntasPercentual . '%' : '—' }}
                    </div>
                    <small class="text-muted">Atividades Prontas</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 {{ $this->atividadesAtrasadas > 0 ? 'border-warning' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold {{ $this->atividadesAtrasadas > 0 ? 'text-warning' : '' }}">
                        {{ $this->atividadesAtrasadas }}
                    </div>
                    <small class="text-muted">Atividades em Atraso</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold">{{ $this->totalAtividades }}</div>
                    <small class="text-muted">Total de Atividades</small>
                </div>
            </div>
        </div>
    </div>

    @if (! $this->ultimoReportEmitido)
    <div class="alert alert-secondary mb-3">
        <i class="bx bx-info-circle me-1"></i>
        Ainda não há nenhum Report emitido para esta obra — a aderência aparece aqui assim que o primeiro Report for emitido. A curva S abaixo já funciona normalmente, calculada com os dados atuais do cronograma.
    </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-3">
            <div class="card h-100">
                <div class="card-header">
                    <h6 class="mb-0">Velocímetro de Aderência</h6>
                    <small class="text-muted">Conforme último Report emitido</small>
                </div>
                <div class="card-body">
                    <div style="height: 220px;">
                        <canvas id="graficoVelocimetro"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-9">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0">Curva S — {{ $this->curvaSGeral['titulo'] }}</h6>
                    <select class="form-select form-select-sm" style="width: auto" wire:model.live="curvaSelecionada">
                        @foreach ($this->curvasDisponiveis as $opcao)
                        <option value="{{ $opcao['chave'] }}">{{ $opcao['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-xl-6">
                            <small class="text-muted d-block mb-1">Mensal</small>
                            <div style="height: 260px;">
                                <canvas id="graficoCurvaSMensal"></canvas>
                            </div>
                        </div>
                        <div class="col-12 col-xl-6">
                            <small class="text-muted d-block mb-1">Semanal</small>
                            <div style="height: 260px;">
                                <canvas id="graficoCurvaSSemanal"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Restrições em Aberto por Pilar Lean</h6></div>
                <div class="card-body">
                    @if (count($this->restricoesPorPilar) > 0)
                    <div style="height: 240px;">
                        <canvas id="graficoPilarLean"></canvas>
                    </div>
                    @else
                    <p class="text-muted text-center py-5 mb-0">Nenhuma restrição em aberto.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Atividades por Status</h6></div>
                <div class="card-body">
                    @if (count($this->atividadesPorStatus) > 0)
                    <div style="height: 240px;">
                        <canvas id="graficoStatusAtividades"></canvas>
                    </div>
                    @else
                    <p class="text-muted text-center py-5 mb-0">Nenhuma atividade cadastrada.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Causas de Não Cumprimento — Top 5</h6></div>
                <div class="card-body p-0">
                    @if ($this->causasTop5->isNotEmpty())
                    <table class="table table-sm mb-0">
                        <tbody>
                            @foreach ($this->causasTop5 as $causa)
                            <tr>
                                <td>{{ $causa['descricao'] }}</td>
                                <td class="text-end fw-bold" style="width: 60px">{{ $causa['total'] }}x</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="text-center py-2">
                        <a href="{{ route('radar.causas') }}" wire:navigate class="small">Ver análise completa &rarr;</a>
                    </div>
                    @else
                    <p class="text-muted text-center py-5 mb-0">Nenhuma causa registrada ainda.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Restrições Abertas Há Mais Tempo</h6></div>
                <div class="card-body p-0">
                    @if ($this->restricoesMaisAntigas->isNotEmpty())
                    <table class="table table-sm mb-0">
                        <tbody>
                            @foreach ($this->restricoesMaisAntigas as $item)
                            <tr>
                                <td>
                                    <div class="text-truncate" style="max-width: 260px" title="{{ $item['restricao']->descricao }}">
                                        {{ $item['restricao']->descricao }}
                                    </div>
                                    <small class="text-muted">{{ $item['restricao']->atividade?->nome }}</small>
                                </td>
                                <td class="text-end" style="width: 90px">
                                    <span class="badge {{ $item['diasAberta'] > 14 ? 'bg-label-danger' : 'bg-label-warning' }}">
                                        {{ $item['diasAberta'] }}d
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="text-center py-2">
                        <a href="{{ route('radar.restricoes') }}" wire:navigate class="small">Ver quadro completo &rarr;</a>
                        <span class="text-muted mx-1">|</span>
                        <a href="{{ route('radar.relatorios-restricoes') }}" wire:navigate class="small">Ver relatório completo &rarr;</a>
                    </div>
                    @else
                    <p class="text-muted text-center py-5 mb-0">Nenhuma restrição em aberto.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@script
<script>
    const dados = @json($this->dadosGraficosParaJs());
    let graficoCurvaSMensal = null;
    let graficoCurvaSSemanal = null;

    if (dados.resumoAderencia && dados.resumoAderencia.aderenciaAtual !== null) {
        const [limiarAtencao, limiarOtimo] = dados.limiares;
        new Chart(document.getElementById('graficoVelocimetro'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [limiarAtencao, limiarOtimo - limiarAtencao, 100 - limiarOtimo],
                    backgroundColor: ['#ff6b6b', '#ffd166', '#51cf66'],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                circumference: 180,
                rotation: -90,
                cutout: '70%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
            },
            plugins: [{
                id: 'textoCentral',
                afterDraw(chart) {
                    const { ctx } = chart;
                    const meta = chart.getDatasetMeta(0);
                    const { x, y } = meta.data[0];
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#37424a';
                    ctx.font = 'bold 26px sans-serif';
                    ctx.fillText(Math.round(dados.resumoAderencia.aderenciaAtual) + '%', x, y - 6);
                    ctx.font = '12px sans-serif';
                    ctx.fillStyle = '#8592a3';
                    ctx.fillText('aderência', x, y + 16);
                    ctx.restore();
                },
            }],
        });
    } else if (document.getElementById('graficoVelocimetro')) {
        document.getElementById('graficoVelocimetro').closest('.card-body').innerHTML =
            '<p class="text-muted text-center py-5 mb-0">Sem dado de aderência ainda.</p>';
    }

    function desenharCurvaS(canvasId, chartRef, curva) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return chartRef;
        }

        const datasets = window.RelatorioGraficoConfig.construirDatasets(
            {
                previsto: { label: 'Previsto', data: curva.barras.previsto },
                tendencia: { label: 'Tendência', data: curva.barras.tendencia },
                realizado: { label: 'Realizado', data: curva.barras.realizado },
            },
            {
                previsto: { label: 'Previsto', data: curva.linhas.previsto },
                tendencia: { label: 'Tendência', data: curva.linhas.tendencia },
                realizado: { label: 'Realizado', data: curva.linhas.realizado },
            }
        );

        if (chartRef) {
            chartRef.data.labels = curva.labels;
            chartRef.data.datasets = datasets;
            chartRef.update();
            return chartRef;
        }

        return new Chart(canvas, {
            type: 'bar',
            data: { labels: curva.labels, datasets },
            options: window.RelatorioGraficoConfig.opcoesDuploEixo(),
        });
    }

    graficoCurvaSMensal = desenharCurvaS('graficoCurvaSMensal', graficoCurvaSMensal, dados.curvaSGeral.mensal);
    graficoCurvaSSemanal = desenharCurvaS('graficoCurvaSSemanal', graficoCurvaSSemanal, dados.curvaSGeral.semanal);

    $wire.on('curva-geral-atualizada', ({ dados: curva }) => {
        graficoCurvaSMensal = desenharCurvaS('graficoCurvaSMensal', graficoCurvaSMensal, curva.mensal);
        graficoCurvaSSemanal = desenharCurvaS('graficoCurvaSSemanal', graficoCurvaSSemanal, curva.semanal);
    });

    if (dados.restricoesPorPilar.length > 0) {
        new Chart(document.getElementById('graficoPilarLean'), {
            type: 'bar',
            data: {
                labels: dados.restricoesPorPilar.map(d => d.label),
                datasets: [{
                    data: dados.restricoesPorPilar.map(d => d.valor),
                    backgroundColor: dados.restricoesPorPilar.map(d => d.cor),
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { precision: 0 } } },
            },
        });
    }

    if (dados.atividadesPorStatus.length > 0) {
        new Chart(document.getElementById('graficoStatusAtividades'), {
            type: 'doughnut',
            data: {
                labels: dados.atividadesPorStatus.map(d => d.label),
                datasets: [{
                    data: dados.atividadesPorStatus.map(d => d.valor),
                    backgroundColor: dados.atividadesPorStatus.map(d => d.cor),
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }
</script>
@endscript
