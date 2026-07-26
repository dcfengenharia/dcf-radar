<?php

use App\Enums\GranularidadePeriodo;
use App\Exports\ReportExport;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportFoto;
use App\Models\ReportPontoAtencao;
use App\Services\ReportGerador;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Detalhe de um Report — todos os números aqui vêm das tabelas
 * ReportCurva/ReportCurvaDatapoint/ReportDesvio já gravadas na geração
 * (ver App\Services\ReportGerador). Esta página NUNCA recalcula nada ao
 * vivo — é a "fotografia" que o usuário vê, mesmo que o cronograma
 * tenha sido reimportado depois.
 *
 * EXCEÇÃO: enquanto o report ainda está em RASCUNHO, pontos de atenção e
 * fotos continuam editáveis aqui mesmo (não só no assistente de criação)
 * — são texto/anexo livre, não números calculados da curva, então editá-
 * los não fere a filosofia de fotografia. Gate via Policy `update`
 * (`App\Policies\ReportPolicy`), que já só libera enquanto rascunho.
 */
new class extends Component {
    use WithFileUploads;

    public Report $report;

    public string $novoComentario = '';

    /** Edição do título enquanto rascunho — sincronizado de $report->titulo no mount(). */
    public string $tituloEdit = '';

    /** [curvaId => [['id' => ?string, 'categoria' => ?string, 'texto' => string], ...]] */
    public array $pontosAtencaoEdit = [];

    /** [fotoId => legenda] — edição de legenda das fotos já enviadas. */
    public array $legendasFotosEdit = [];

    public array $novasFotosUpload = [];
    public array $legendasNovasFotosUpload = [];

    /** Link público gerado pra este report (ver gerarLinkCliente()) — null até o botão ser clicado. */
    public ?string $linkClienteGerado = null;

    /**
     * Limiares do velocímetro de aderência (zonas vermelha/amarela/verde) —
     * repassados ao JS via limiaresAderencia() na hora de montar o gráfico
     * (ver bloco @script no fim desta view), pra não duplicar os números.
     */
    private const ADERENCIA_LIMIAR_OTIMO = 95.0;
    private const ADERENCIA_LIMIAR_ATENCAO = 80.0;

    public function mount(Report $report): void
    {
        $this->authorize('view', $report);

        $this->report = $report->load([
            'obra',
            'curvas.datapoints',
            'curvas.desvios',
            'curvas.pontosAtencao',
            'fotos.enviadoPor:id,first_name,last_name',
            'comentarios.autor:id,first_name,last_name',
            'criador:id,first_name,last_name',
            'emissor:id,first_name,last_name',
            'indicadoresSemana',
        ]);

        $this->tituloEdit = (string) $this->report->titulo;
        $this->sincronizarPontosAtencaoEdit();
        $this->sincronizarLegendasFotosEdit();
    }

    public function salvarTitulo(): void
    {
        $this->authorize('update', $this->report);

        $this->report->update(['titulo' => trim($this->tituloEdit) ?: null]);
        $this->dispatch('show-toast', message: 'Título atualizado.');
    }

    /**
     * Gera um link assinado (Laravel signed route, 30 dias de validade)
     * pra este report específico — único jeito de acesso somente-leitura
     * sem login, servido por App\Http\Controllers\ClienteRelatorioPublicoController.
     * Só faz sentido pra report já emitido (rascunho nunca é exposto
     * publicamente, mesma regra de visibilidade normal do sistema).
     */
    public function gerarLinkCliente(): void
    {
        $this->authorize('view', $this->report);
        abort_unless($this->report->estaEmitido(), 403);

        $this->linkClienteGerado = URL::temporarySignedRoute(
            'cliente.relatorio.publico',
            now()->addDays(30),
            ['report' => $this->report->id]
        );
    }

    private function sincronizarPontosAtencaoEdit(): void
    {
        foreach ($this->report->curvas as $curva) {
            $this->pontosAtencaoEdit[$curva->id] = $curva->pontosAtencao->map(fn ($p) => [
                'id' => $p->id,
                'categoria' => $p->categoria,
                'texto' => $p->texto,
            ])->all();
        }
    }

    private function sincronizarLegendasFotosEdit(): void
    {
        $this->legendasFotosEdit = $this->report->fotos->mapWithKeys(fn ($f) => [$f->id => $f->legenda])->all();
    }

    /**
     * Monta os dados de cada curva (mensal + semanal) pra gráficos E
     * tabelas — consumido tanto pelo Blade (tabelas, server-side) quanto
     * pelo bloco @script no fim da view (gráficos Chart.js, via @json).
     */
    #[Computed]
    public function dadosGraficos(): array
    {
        return $this->report->curvas->map(function (ReportCurva $curva) {
            $mensal = $this->serieParaGrafico($curva, GranularidadePeriodo::Mensal);
            $semanal = $this->serieParaGrafico($curva, GranularidadePeriodo::Semanal);

            return [
                'id' => $curva->id,
                'mensal' => $mensal,
                'semanal' => $semanal,
                // Aderência "da semana corrente" = aderencia_periodo (ver
                // serieParaGrafico()) da ÚLTIMA semana que teve atualização
                // de verdade (previsto E realizado presentes) — não
                // necessariamente a última das 4 semanas gravadas, pois o
                // realizado pode não ter chegado até lá ainda (nesse caso a
                // última semana ficaria com aderencia_periodo=null e o
                // velocímetro pareceria "quebrado").
                'aderencia_atual' => $this->aderenciaDaUltimaSemanaAtualizada($semanal['tabela']),
            ];
        })->all();
    }

    /** Varre a tabela semanal de trás pra frente e devolve a aderência DO PERÍODO (%real÷%previsto daquela semana) da última semana com dado — nunca a última data gravada se ela ainda não tiver realizado. */
    private function aderenciaDaUltimaSemanaAtualizada(array $tabelaSemanal): ?float
    {
        for ($i = count($tabelaSemanal) - 1; $i >= 0; $i--) {
            if ($tabelaSemanal[$i]['aderencia_periodo'] !== null) {
                return $tabelaSemanal[$i]['aderencia_periodo'];
            }
        }

        return null;
    }

    /**
     * Delega pra App\Support\ReportCurvaSerializer (extraída daqui pra
     * ser reaproveitada também pelo link público do cliente — mesma
     * lógica, sem duplicar/arriscar divergência entre as duas telas).
     */
    private function serieParaGrafico(ReportCurva $curva, GranularidadePeriodo $gran): array
    {
        return \App\Support\ReportCurvaSerializer::serieParaGrafico($curva, $gran);
    }

    /** Limiares do velocímetro de aderência, expostos pro Blade repassar ao JS (evita duplicar os números). */
    public function limiaresAderencia(): array
    {
        return [self::ADERENCIA_LIMIAR_ATENCAO, self::ADERENCIA_LIMIAR_OTIMO];
    }

    /** Indicadores da semana anterior (previsto×concluído), 1 por categoria, indexados por 'categoria'. */
    #[Computed]
    public function indicadoresSemanaAnterior(): \Illuminate\Support\Collection
    {
        return $this->report->indicadoresSemana
            ->where('janela', 'semana_anterior')
            ->keyBy('categoria');
    }

    /** Indicadores da próxima semana (só previsto), 1 por categoria, indexados por 'categoria'. */
    #[Computed]
    public function indicadoresSemanaProxima(): \Illuminate\Support\Collection
    {
        return $this->report->indicadoresSemana
            ->where('janela', 'semana_proxima')
            ->keyBy('categoria');
    }

    /**
     * Variação do término (tendência − linha de base), em dias — positivo
     * = atraso, negativo = adiantado. Calculado via subtração de
     * timestamps (não Carbon::diffInDays) pra não depender de convenção
     * de sinal entre versões do Carbon.
     */
    private function variacaoDias(ReportCurva $curva): ?int
    {
        if (! $curva->termino_linha_base || ! $curva->termino_tendencia) {
            return null;
        }

        return (int) round(($curva->termino_tendencia->timestamp - $curva->termino_linha_base->timestamp) / 86400);
    }

    public function emitir(): void
    {
        $this->authorize('emitir', $this->report);

        app(ReportGerador::class)->emitir($this->report, auth()->user());

        $this->report->refresh();
        $this->dispatch('hide-emitir-modal');
        $this->dispatch('show-toast', message: 'Report emitido. Agora é somente-leitura e visível a todos com acesso à obra.');
    }

    public function adicionarComentario(): void
    {
        $this->authorize('comentar', $this->report);

        $this->validate([
            'novoComentario' => 'required|string|min:2',
        ], [], ['novoComentario' => 'comentário']);

        $this->report->comentarios()->create([
            'autor_id' => auth()->id(),
            'comentario' => $this->novoComentario,
        ]);

        $this->novoComentario = '';
        $this->report->load('comentarios.autor:id,first_name,last_name');
        $this->dispatch('show-toast', message: 'Comentário adicionado.');
    }

    public function adicionarPontoAtencaoEdit(string $curvaId): void
    {
        $this->authorize('update', $this->report);

        $this->pontosAtencaoEdit[$curvaId][] = ['id' => null, 'categoria' => '', 'texto' => ''];
    }

    public function removerPontoAtencaoEdit(string $curvaId, int $indice): void
    {
        $this->authorize('update', $this->report);

        $ponto = $this->pontosAtencaoEdit[$curvaId][$indice] ?? null;
        if ($ponto && $ponto['id']) {
            ReportPontoAtencao::where('id', $ponto['id'])->delete();
            $this->report->load('curvas.pontosAtencao');
        }

        unset($this->pontosAtencaoEdit[$curvaId][$indice]);
        $this->pontosAtencaoEdit[$curvaId] = array_values($this->pontosAtencaoEdit[$curvaId]);
    }

    public function salvarPontosAtencao(string $curvaId): void
    {
        $this->authorize('update', $this->report);

        $curva = $this->report->curvas->firstWhere('id', $curvaId);
        abort_unless($curva, 404);

        foreach ($this->pontosAtencaoEdit[$curvaId] as $i => &$ponto) {
            if (trim($ponto['texto'] ?? '') === '') {
                continue;
            }

            if ($ponto['id']) {
                ReportPontoAtencao::where('id', $ponto['id'])->update([
                    'categoria' => $ponto['categoria'] ?: null,
                    'texto' => $ponto['texto'],
                    'ordem' => $i,
                ]);
            } else {
                $novo = $curva->pontosAtencao()->create([
                    'categoria' => $ponto['categoria'] ?: null,
                    'texto' => $ponto['texto'],
                    'ordem' => $i,
                ]);
                $ponto['id'] = $novo->id;
            }
        }
        unset($ponto);

        $this->report->load('curvas.pontosAtencao');
        $this->dispatch('show-toast', message: 'Pontos de atenção atualizados.');
    }

    public function enviarFotos(): void
    {
        $this->authorize('update', $this->report);

        $this->validate([
            'novasFotosUpload.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if (empty($this->novasFotosUpload)) {
            return;
        }

        $ordemBase = ((int) $this->report->fotos->max('ordem')) + 1;
        foreach ($this->novasFotosUpload as $i => $arquivo) {
            $caminho = $arquivo->store("report-fotos/{$this->report->obra_id}/{$this->report->id}", 'public');
            $this->report->fotos()->create([
                'caminho_arquivo' => $caminho,
                'legenda' => $this->legendasNovasFotosUpload[$i] ?? null,
                'ordem' => $ordemBase + $i,
                'enviado_por' => auth()->id(),
            ]);
        }

        $this->reset(['novasFotosUpload', 'legendasNovasFotosUpload']);
        $this->report->load('fotos.enviadoPor:id,first_name,last_name');
        $this->sincronizarLegendasFotosEdit();
        $this->dispatch('show-toast', message: 'Fotos adicionadas.');
    }

    public function removerFoto(string $fotoId): void
    {
        $this->authorize('update', $this->report);

        $foto = $this->report->fotos->firstWhere('id', $fotoId);
        abort_unless($foto, 404);

        if ($foto->caminho_arquivo) {
            Storage::disk('public')->delete($foto->caminho_arquivo);
        }
        $foto->delete();

        $this->report->load('fotos.enviadoPor:id,first_name,last_name');
        unset($this->legendasFotosEdit[$fotoId]);
        $this->dispatch('show-toast', message: 'Foto removida.');
    }

    public function salvarLegendaFoto(string $fotoId): void
    {
        $this->authorize('update', $this->report);

        ReportFoto::where('id', $fotoId)->update([
            'legenda' => $this->legendasFotosEdit[$fotoId] ?: null,
        ]);

        $this->report->load('fotos.enviadoPor:id,first_name,last_name');
        $this->dispatch('show-toast', message: 'Legenda atualizada.');
    }

    public function exportarPdf()
    {
        $pdf = Pdf::loadView('exports.report-pdf', ['report' => $this->report]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            "report-{$this->report->periodo_referencia->format('Y-m-d')}.pdf"
        );
    }

    public function exportarExcel()
    {
        return Excel::download(
            new ReportExport($this->report),
            "report-{$this->report->periodo_referencia->format('Y-m-d')}.xlsx"
        );
    }
};

?>

<div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Cabeçalho --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            @can('update', $report)
            <div class="d-flex align-items-center gap-2 mb-1">
                <input type="text" class="form-control form-control-sm w-auto" style="min-width: 260px"
                       wire:model="tituloEdit" wire:keydown.enter="salvarTitulo"
                       placeholder="Título (opcional) — ex: Report Semana 26">
                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="salvarTitulo">
                    <i class="bx bx-save"></i>
                </button>
                <span class="badge bg-{{ $report->status->corBadge() }}">{{ $report->status->label() }}</span>
            </div>
            @else
            <h5 class="mb-1">
                {{ $report->titulo ?: 'Report ' . $report->periodo_referencia->format('d/m/Y') }}
                <span class="badge bg-{{ $report->status->corBadge() }} ms-2">{{ $report->status->label() }}</span>
            </h5>
            @endcan
            <small class="text-muted">
                Semana de {{ $report->periodo_referencia->format('d/m/Y') }}
                @if($report->data_status)
                — status em {{ $report->data_status->format('d/m/Y') }}
                @endif
                @if($report->criador)
                — criado por {{ $report->criador->first_name }} {{ $report->criador->last_name }}
                @endif
            </small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-danger btn-sm" wire:click="exportarPdf">
                <i class="bx bxs-file-pdf me-1"></i>PDF
            </button>
            <button class="btn btn-outline-success btn-sm" wire:click="exportarExcel">
                <i class="bx bxs-file-export me-1"></i>Excel
            </button>
            @if($report->estaEmitido())
            <button class="btn btn-outline-primary btn-sm" wire:click="gerarLinkCliente">
                <i class="bx bx-link me-1"></i>Link para o cliente
            </button>
            @endif
            @can('emitir', $report)
            <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#emitirReportModal">
                <i class="bx bx-send me-1"></i>Emitir
            </button>
            @endcan
        </div>
    </div>

    @if($linkClienteGerado)
    <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-4" x-data>
        <i class="bx bx-link"></i>
        <input type="text" readonly class="form-control form-control-sm" value="{{ $linkClienteGerado }}" x-ref="linkCliente" onclick="this.select()">
        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                x-on:click="navigator.clipboard.writeText($refs.linkCliente.value)">
            <i class="bx bx-copy"></i> Copiar
        </button>
        <small class="text-muted flex-shrink-0">Válido por 30 dias, sem login.</small>
    </div>
    @endif

    @if($report->estaEmitido())
    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-4">
        <i class="bx bx-check-circle"></i>
        <small>
            Emitido em <strong>{{ $report->emitido_em?->format('d/m/Y H:i') }}</strong>
            @if($report->emissor)
            por <strong>{{ $report->emissor->first_name }} {{ $report->emissor->last_name }}</strong>
            @endif
            — somente leitura.
        </small>
    </div>
    @else
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-4">
        <i class="bx bx-lock-alt"></i>
        <small>Rascunho — visível só para o setor de planejamento até ser emitido.</small>
    </div>
    @endif

    <div class="alert alert-light border d-flex gap-2 mb-4 py-2">
        <i class="bx bx-info-circle mt-1 flex-shrink-0"></i>
        <small>
            <strong>Aviso de precisão:</strong>
            Os totais de HH conferem exatamente com o cronograma importado.
            A distribuição <em>mensal/semanal</em> é reconstruída pelo ponto médio de
            cada bloco faseado do MS Project e pode ter desvio de fronteira de até
            <strong>~0,3%</strong> em relação ao que o MS Project exibe — isso é
            esperado e transparente.
        </small>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Desempenho da Semana Anterior (Restrições/Engenharia/Suprimentos) --}}
    {{-- ------------------------------------------------------------------ --}}
    <h6 class="mb-2"><i class="bx bx-history me-1"></i>Desempenho da Semana Anterior</h6>
    <div class="row mb-2">
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Restrições',
            'icone' => 'bx-shield-quarter',
            'indicador' => $this->indicadoresSemanaAnterior['restricoes'] ?? null,
            'colunas' => ['titulo' => 'Restrição', 'responsavel_nome' => 'Responsável', 'status' => 'Status', 'prazo_limite' => 'Prazo'],
            'colunasData' => ['prazo_limite'],
            'mensagemVazia' => 'Nenhuma restrição prevista para a semana anterior.',
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Engenharia',
            'icone' => 'bx-drafting-compass',
            'indicador' => $this->indicadoresSemanaAnterior['engenharia'] ?? null,
            'colunas' => ['codigo' => 'Código', 'descricao' => 'Descrição', 'status' => 'Status', 'data_planejada' => 'Previsto'],
            'colunasData' => ['data_planejada'],
            'mensagemVazia' => 'Nenhum documento de engenharia previsto para a semana anterior.',
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Suprimentos',
            'icone' => 'bx-package',
            'indicador' => $this->indicadoresSemanaAnterior['suprimentos'] ?? null,
            'colunas' => ['nome' => 'Item', 'fornecedor_nome' => 'Fornecedor', 'status' => 'Status', 'data_prevista' => 'Previsto'],
            'colunasData' => ['data_prevista'],
            'mensagemVazia' => 'Nenhum item de suprimento previsto para a semana anterior.',
        ])
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Curvas --}}
    {{-- ------------------------------------------------------------------ --}}
    @foreach($report->curvas as $i => $curva)
    @php
        $dados = $this->dadosGraficos[$i];
        $variacao = $this->variacaoDias($curva);
    @endphp
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bx bx-line-chart me-1"></i>{{ $curva->titulo_exibicao }}</h5>
        </div>
        <div class="card-body">

            {{-- Cards de KPI --}}
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card h-100 bg-label-primary">
                        <div class="card-body">
                            <p class="text-muted mb-1">Atividades no Escopo</p>
                            <h3 class="mb-0">{{ $curva->total_atividades }}</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 bg-label-success">
                        <div class="card-body">
                            <p class="text-muted mb-1">Concluídas</p>
                            <h3 class="mb-0">{{ $curva->atividades_concluidas }}</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 {{ $curva->atividades_atrasadas > 0 ? 'bg-label-danger' : 'bg-label-secondary' }}">
                        <div class="card-body">
                            <p class="text-muted mb-1">Atrasadas</p>
                            <h3 class="mb-0">{{ $curva->atividades_atrasadas }}</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <p class="text-muted mb-1">Variação de Término</p>
                            @if($variacao === null)
                            <h5 class="mb-0 text-muted">—</h5>
                            @else
                            <span class="badge fs-6 bg-{{ $variacao > 0 ? 'danger' : ($variacao < 0 ? 'success' : 'secondary') }}">
                                {{ $variacao > 0 ? "+{$variacao}d atraso" : ($variacao < 0 ? "{$variacao}d adiantado" : 'No prazo') }}
                            </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Términos em destaque --}}
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <p class="text-muted small mb-1"><i class="bx bx-bookmark me-1"></i>Término Linha de Base</p>
                        <h5 class="mb-0">{{ $curva->termino_linha_base?->format('d/m/Y') ?? '—' }}</h5>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <p class="text-muted small mb-1"><i class="bx bx-trending-up me-1"></i>Término Tendência</p>
                        <h5 class="mb-0">{{ $curva->termino_tendencia?->format('d/m/Y') ?? '—' }}</h5>
                    </div>
                </div>
            </div>

            {{-- Gráfico mensal (tudo em %) + tabela abaixo --}}
            <h6 class="mb-2">Curva S — Mensal</h6>
            <div wire:ignore class="mb-3" style="height: 260px;">
                <canvas id="chart-mensal-{{ $curva->id }}"></canvas>
            </div>
            @include('pages.radar._partials.relatorio-tabela-curva', ['tabela' => $dados['mensal']['tabela']])

            {{-- Gráfico semanal (tudo em %) + tabela abaixo, com a coluna de Aderência da semana --}}
            <h6 class="mb-2 mt-4">Curva S — Semanal (últimas 4 semanas)</h6>
            <div wire:ignore class="mb-3" style="height: 260px;">
                <canvas id="chart-semanal-{{ $curva->id }}"></canvas>
            </div>
            @include('pages.radar._partials.relatorio-tabela-curva', ['tabela' => $dados['semanal']['tabela'], 'comAderencia' => true])

            {{-- Velocímetro de aderência — sempre a última semana atualizada (ver coluna Aderência na tabela acima) --}}
            <h6 class="mb-2 mt-4"><i class="bx bx-tachometer me-1"></i>Aderência ao Cronograma — Última Semana Atualizada</h6>
            <div class="row justify-content-center mb-3">
                <div class="col-md-4">
                    <div wire:ignore class="text-center" style="height: 200px;">
                        <canvas id="chart-aderencia-{{ $curva->id }}"></canvas>
                    </div>
                    <p class="text-muted small text-center mb-0">
                        Aderência = % realizado do período ÷ % previsto do período da última semana atualizada —
                        veja o detalhe semana a semana na tabela acima.
                    </p>
                </div>
            </div>

            {{-- Quadro de análise de desvios --}}
            <h6 class="mb-2"><i class="bx bx-table me-1"></i>Análise de Desvios</h6>
            <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Pacote</th>
                            <th class="text-end">Peso</th>
                            <th class="text-end">% Previsto</th>
                            <th class="text-end">% Real</th>
                            <th class="text-end">% Desvio</th>
                            <th class="text-end">% Impacto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($curva->desvios as $desvio)
                        <tr class="{{ $desvio->eh_nivel_pai ? 'table-light fw-bold' : '' }}">
                            <td>{{ $desvio->titulo_exibicao }}</td>
                            <td class="text-end">{{ number_format($desvio->peso * 100, 1, ',', '.') }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_previsto, 1, ',', '.') }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_real, 1, ',', '.') }}%</td>
                            <td class="text-end {{ $desvio->percentual_desvio < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($desvio->percentual_desvio, 1, ',', '.') }}%
                            </td>
                            <td class="text-end {{ $desvio->percentual_impacto < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($desvio->percentual_impacto, 1, ',', '.') }}%
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pontos de atenção --}}
            <h6 class="mb-2"><i class="bx bx-error-circle me-1"></i>Pontos de Atenção</h6>
            @can('update', $report)
            @foreach($pontosAtencaoEdit[$curva->id] ?? [] as $j => $ponto)
            <div class="row g-2 mb-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm"
                           wire:model="pontosAtencaoEdit.{{ $curva->id }}.{{ $j }}.categoria"
                           placeholder="Categoria (opcional)">
                </div>
                <div class="col-md-8">
                    <input type="text" class="form-control form-control-sm"
                           wire:model="pontosAtencaoEdit.{{ $curva->id }}.{{ $j }}.texto"
                           placeholder="Descreva o ponto de atenção...">
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger py-0"
                            wire:click="removerPontoAtencaoEdit('{{ $curva->id }}', {{ $j }})">
                        <i class="bx bx-x"></i>
                    </button>
                </div>
            </div>
            @endforeach
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="adicionarPontoAtencaoEdit('{{ $curva->id }}')">
                    <i class="bx bx-plus me-1"></i>Adicionar
                </button>
                <button type="button" class="btn btn-sm btn-primary" wire:click="salvarPontosAtencao('{{ $curva->id }}')">
                    <i class="bx bx-save me-1"></i>Salvar pontos de atenção
                </button>
            </div>
            @else
            @if($curva->pontosAtencao->isEmpty())
            <p class="text-muted small mb-0">Nenhum ponto de atenção registrado para esta curva.</p>
            @else
            <ul class="mb-0">
                @foreach($curva->pontosAtencao as $ponto)
                <li>
                    @if($ponto->categoria)
                    <span class="badge bg-label-warning me-1">{{ $ponto->categoria }}</span>
                    @endif
                    {{ $ponto->texto }}
                </li>
                @endforeach
            </ul>
            @endif
            @endcan
        </div>
    </div>
    @endforeach

    {{-- ------------------------------------------------------------------ --}}
    {{-- Planejamento da Próxima Semana (Restrições/Engenharia/Suprimentos) --}}
    {{-- ------------------------------------------------------------------ --}}
    <h6 class="mb-2"><i class="bx bx-calendar-plus me-1"></i>Planejamento da Próxima Semana</h6>
    <div class="row mb-4">
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Restrições',
            'icone' => 'bx-shield-quarter',
            'indicador' => $this->indicadoresSemanaProxima['restricoes'] ?? null,
            'colunas' => ['titulo' => 'Restrição', 'responsavel_nome' => 'Responsável', 'status' => 'Status', 'prazo_limite' => 'Prazo'],
            'colunasData' => ['prazo_limite'],
            'mensagemVazia' => 'Nenhuma restrição prevista para a próxima semana.',
            'mostrarConcluido' => false,
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Engenharia',
            'icone' => 'bx-drafting-compass',
            'indicador' => $this->indicadoresSemanaProxima['engenharia'] ?? null,
            'colunas' => ['codigo' => 'Código', 'descricao' => 'Descrição', 'disciplina_nome' => 'Disciplina', 'data_planejada' => 'Previsto'],
            'colunasData' => ['data_planejada'],
            'mensagemVazia' => 'Nenhum documento de engenharia previsto para a próxima semana.',
            'mostrarConcluido' => false,
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Suprimentos',
            'icone' => 'bx-package',
            'indicador' => $this->indicadoresSemanaProxima['suprimentos'] ?? null,
            'colunas' => ['nome' => 'Item', 'fornecedor_nome' => 'Fornecedor', 'status' => 'Status', 'data_prevista' => 'Previsto'],
            'colunasData' => ['data_prevista'],
            'mensagemVazia' => 'Nenhum item de suprimento previsto para a próxima semana.',
            'mostrarConcluido' => false,
        ])
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Galeria de fotos --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bx bx-images me-1"></i>Relatório Fotográfico</h6>
        </div>
        <div class="card-body">
            @can('update', $report)
            <div class="mb-3">
                <label class="form-label small">Adicionar fotos</label>
                <input type="file" class="form-control @error('novasFotosUpload.*') is-invalid @enderror"
                       wire:model="novasFotosUpload" multiple accept="image/*">
                @error('novasFotosUpload.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div wire:loading wire:target="novasFotosUpload" class="small text-muted mt-1">Enviando...</div>
            </div>

            @if(!empty($novasFotosUpload))
            <div class="row g-3 mb-2">
                @foreach($novasFotosUpload as $i => $foto)
                <div class="col-md-3">
                    <div class="card h-100">
                        <img src="{{ $foto->temporaryUrl() }}" class="card-img-top" style="height:120px; object-fit:cover">
                        <div class="card-body p-2">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="legendasNovasFotosUpload.{{ $i }}" placeholder="Legenda...">
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-primary mb-3" wire:click="enviarFotos">
                <i class="bx bx-upload me-1"></i>Enviar fotos
            </button>
            @endif
            @endcan

            @if($report->fotos->isEmpty())
            <p class="text-muted small mb-0">Nenhuma foto anexada a este report.</p>
            @else
            <div class="row g-3">
                @foreach($report->fotos as $foto)
                <div class="col-md-3">
                    <div class="card h-100">
                        @if($foto->url)
                        <img src="{{ $foto->url }}" class="card-img-top" style="height:160px; object-fit:cover; cursor: zoom-in;"
                             onclick="window.abrirFotoAmpliada('{{ $foto->url }}', @js($foto->legenda))">
                        @endif
                        @can('update', $report)
                        <div class="card-body p-2">
                            <input type="text" class="form-control form-control-sm mb-1"
                                   wire:model="legendasFotosEdit.{{ $foto->id }}" placeholder="Legenda...">
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary flex-fill py-0"
                                        wire:click="salvarLegendaFoto('{{ $foto->id }}')">Salvar</button>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0"
                                        onclick="confirmarAcao(this, {
                                            mensagem: 'Remover esta foto?',
                                            metodo: 'removerFoto',
                                            args: ['{{ $foto->id }}'],
                                            icone: 'bx-trash',
                                        })">
                                    <i class="bx bx-trash"></i>
                                </button>
                            </div>
                        </div>
                        @elseif($foto->legenda)
                        <div class="card-body p-2">
                            <small class="text-muted">{{ $foto->legenda }}</small>
                        </div>
                        @endcan
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- Modal: Confirmar emissão --}}
    <div wire:ignore.self class="modal fade" id="emitirReportModal" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-semibold">
                        <i class="bx bx-send me-2"></i>Emitir Report
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="mb-3">Este report deixará de ser rascunho e passará a ser visível (e comentável) por todos com acesso à obra.</p>
                    <div class="alert alert-warning d-flex align-items-center mb-0">
                        <i class="bx bx-info-circle me-2 flex-shrink-0"></i>
                        <span>Depois de emitido, o report vira <strong>somente-leitura</strong> para o planejamento.</span>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success shadow-none" wire:click="emitir">
                        <span wire:loading.remove wire:target="emitir"><i class="bx bx-send me-1"></i>Emitir Report</span>
                        <span wire:loading wire:target="emitir"><i class="bx bx-loader-alt bx-spin me-1"></i>Emitindo...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal de ampliação de foto (reaproveitado por todas as miniaturas) --}}
    <div class="modal fade" id="modal-foto-ampliada" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center pt-0">
                    <img id="modal-foto-ampliada-img" src="" class="img-fluid rounded" style="max-height: 75vh;">
                    <p id="modal-foto-ampliada-legenda" class="text-white-50 mt-2 mb-0"></p>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Comentários (só após emissão) --}}
    {{-- ------------------------------------------------------------------ --}}
    @can('comentar', $report)
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bx bx-comment-detail me-1"></i>Comentários</h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <textarea class="form-control @error('novoComentario') is-invalid @enderror" rows="2"
                          wire:model="novoComentario" placeholder="Escreva um comentário..."></textarea>
                @error('novoComentario')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button class="btn btn-sm btn-primary mt-2" wire:click="adicionarComentario">
                    <i class="bx bx-send me-1"></i>Comentar
                </button>
            </div>

            @forelse($report->comentarios as $comentario)
            <div class="d-flex gap-2 mb-3">
                <i class="bx bx-user-circle fs-4 text-muted"></i>
                <div>
                    <div class="small">
                        <strong>{{ $comentario->autor?->first_name }} {{ $comentario->autor?->last_name }}</strong>
                        <span class="text-muted ms-1">{{ $comentario->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                    <div>{{ $comentario->comentario }}</div>
                </div>
            </div>
            @empty
            <p class="text-muted small mb-0">Nenhum comentário ainda.</p>
            @endforelse
        </div>
    </div>
    @elseif($report->estaRascunho())
    <div class="alert alert-light border small text-muted">
        <i class="bx bx-info-circle me-1"></i>Comentários ficam disponíveis depois que o report é emitido.
    </div>
    @endcan

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

    $wire.on('hide-emitir-modal', () => {
        const el = document.getElementById('emitirReportModal');
        if (el) {
            bootstrap.Modal.getInstance(el)?.hide();
            setTimeout(() => {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            }, 150);
        }
    });

    const curvasGrafico = @json($this->dadosGraficos);
    const [limiarAtencao, limiarOtimo] = @json($this->limiaresAderencia());
    const cfg = window.RelatorioGraficoConfig;

    curvasGrafico.forEach((curva) => {
        ['mensal', 'semanal'].forEach((gran) => {
            const canvas = document.getElementById(`chart-${gran}-${curva.id}`);
            if (!canvas || !curva[gran].labels.length) return;

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: curva[gran].labels,
                    datasets: cfg.construirDatasets(curva[gran].barras, curva[gran].linhas),
                },
                options: cfg.opcoesDuploEixo(),
            });
        });

        const canvasAderencia = document.getElementById(`chart-aderencia-${curva.id}`);
        if (canvasAderencia) {
            const config = cfg.gaugeConfig(limiarAtencao, limiarOtimo, 100);
            config.data.datasets[0].needleValue = curva.aderencia_atual;

            new Chart(canvasAderencia, {
                ...config,
                plugins: [cfg.pluginAgulha, cfg.pluginTextoCentral],
            });
        }
    });
</script>
@endscript

<script>
    // Amplia uma foto do relatório fotográfico no modal compartilhado —
    // função global (não depende do Livewire) porque é só apresentação,
    // sem estado de servidor envolvido.
    window.abrirFotoAmpliada = function (url, legenda) {
        document.getElementById('modal-foto-ampliada-img').src = url;
        document.getElementById('modal-foto-ampliada-legenda').textContent = legenda || '';
        new bootstrap.Modal(document.getElementById('modal-foto-ampliada')).show();
    };
</script>
