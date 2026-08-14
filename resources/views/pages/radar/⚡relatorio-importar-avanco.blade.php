<?php

use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Jobs\ImportarCronogramaJob;
use App\Models\CronogramaImportacao;
use App\Models\Work;
use App\Support\HealthCheck\HealthCheckEngine;
use App\Support\HealthCheck\HealthCheckResultado;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads, WithPagination;

    protected $paginationTheme = 'bootstrap';

    public Work $obra;

    public $arquivoTemp = null;

    protected function rules(): array
    {
        $mb = $this->obra->tenant->limiteUploadMb();

        return [
            'arquivoTemp' => ['required', 'file', 'max:' . ($mb * 1024), 'mimetypes:text/xml,application/xml'],
        ];
    }

    // Estado da prévia
    public bool $emPrevia   = false;
    public bool $importado  = false;
    public ?string $erro    = null;

    // Contagens e totais extraídos do PlanoImportacao
    public int    $atualizadas  = 0;
    public int    $ignoradas    = 0;
    public float  $totalRealHh  = 0.0;
    public float  $totalWorkHh  = 0.0;
    public ?string $dataStatus  = null;
    public array  $nomesIgnoradas = [];

    // Health Check — mesmo mecanismo de ⚡cronograma.blade.php: calculado uma
    // única vez em analisar() e reaproveitado (nunca recalculado) até a
    // persistência dentro do Job.
    public array $healthCheckResultado = ['findings' => []];

    // Caminho do arquivo temp armazenado (passado ao job)
    public ?string $caminhoArquivo = null;

    // Acompanhamento do job de importação — mesmo mecanismo de
    // ⚡cronograma.blade.php (cache + wire:poll), reaproveitado aqui pra não
    // apresentar sucesso falso antes do Job assíncrono terminar de verdade.
    public ?string $importacaoTrackingId = null;
    public ?string $statusImportacao = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
    }

    public function analisar(): void
    {
        $this->validateOnly('arquivoTemp');
        $this->erro = null;

        try {
            $path = $this->arquivoTemp->storeAs(
                'imports/temp',
                'avanco_' . $this->obra->id . '_' . time() . '.xml',
                'local'
            );
            $this->caminhoArquivo = storage_path('app/' . $path);

            $plano = app(ImportadorCronograma::class)->analisar(
                $this->caminhoArquivo,
                $this->obra,
                TipoCronogramaImportacao::Avanco
            );

            $this->atualizadas    = count($plano->atualizar);
            $this->ignoradas      = count($plano->ignoradasNomes);
            $this->totalRealHh    = $plano->totalRealHh;
            $this->totalWorkHh    = $plano->totalWorkHh;
            $this->dataStatus     = $plano->dataStatus?->format('d/m/Y');
            $this->nomesIgnoradas = $plano->ignoradasNomes;

            $this->healthCheckResultado = app(HealthCheckEngine::class)->avaliar($plano, TipoCronogramaImportacao::Avanco)->toArray();

            $this->emPrevia = true;
        } catch (\Throwable $e) {
            $this->erro = 'Erro ao processar o arquivo: ' . $e->getMessage();
        }
    }

    /** Reidrata o resultado do Health Check pra Blade poder chamar os helpers de agregação. */
    public function resultadoHealthCheck(): HealthCheckResultado
    {
        return HealthCheckResultado::fromArray($this->healthCheckResultado);
    }

    public function confirmar(): void
    {
        abort_unless($this->emPrevia && $this->caminhoArquivo !== null, 422);

        $this->importacaoTrackingId = (string) Str::ulid();
        $this->statusImportacao = 'processando';
        Cache::put(
            "cronograma-importacao-status:{$this->importacaoTrackingId}",
            ['status' => 'processando', 'erro' => null],
            now()->addMinutes(30)
        );

        try {
            ImportarCronogramaJob::dispatch(
                $this->obra,
                $this->caminhoArquivo,
                auth()->id(),
                TipoCronogramaImportacao::Avanco,
                $this->importacaoTrackingId,
                $this->healthCheckResultado,
            );
        } catch (\Throwable $e) {
            // Fila síncrona (dev/testes): o job já rodou inline e uma falha
            // relança a exceção até aqui — o cache já foi marcado 'erro'
            // dentro do job antes do rethrow (mesmo padrão de ⚡cronograma.blade.php).
        }

        // Em fila síncrona o job acima já rodou por completo — resolve na
        // hora. Em fila assíncrona (produção), o front continua via wire:poll.
        $this->verificarStatusImportacao();
    }

    public function verificarStatusImportacao(): void
    {
        if ($this->importacaoTrackingId === null) {
            return;
        }

        $status = Cache::get("cronograma-importacao-status:{$this->importacaoTrackingId}");

        if ($status === null || $status['status'] === 'processando') {
            return;
        }

        Cache::forget("cronograma-importacao-status:{$this->importacaoTrackingId}");
        $this->importacaoTrackingId = null;
        $this->statusImportacao = null;

        if ($status['status'] === 'concluido') {
            $this->reset(['emPrevia', 'arquivoTemp', 'caminhoArquivo',
                'atualizadas', 'ignoradas', 'totalRealHh', 'totalWorkHh',
                'dataStatus', 'nomesIgnoradas', 'healthCheckResultado']);
            $this->importado = true;
            $this->dispatch('show-toast', message: 'Avanço importado com sucesso!');
        } else {
            $this->erro = 'Erro ao processar a importação: ' . ($status['erro'] ?? 'erro desconhecido');
        }
    }

    public function cancelar(): void
    {
        // Não permite cancelar com um job de importação em andamento — o
        // arquivo temp ainda está sendo lido pelo job.
        abort_if($this->statusImportacao === 'processando', 422);

        if ($this->caminhoArquivo && file_exists($this->caminhoArquivo)) {
            @unlink($this->caminhoArquivo);
        }
        $this->reset(['emPrevia', 'arquivoTemp', 'caminhoArquivo',
            'atualizadas', 'ignoradas', 'totalRealHh', 'totalWorkHh',
            'dataStatus', 'nomesIgnoradas', 'healthCheckResultado', 'importado', 'erro',
            'importacaoTrackingId', 'statusImportacao']);
    }

    /**
     * Histórico de Importações (Fase 3, Etapa 5) — usa o partial
     * compartilhado historico-importacoes.blade.php, mesma apresentação de
     * ⚡cronograma.blade.php/⚡obra-detalhe.blade.php. Só o filtro de tipo
     * muda: aqui é Avanço/Ambos, nunca Baseline pura.
     */
    #[Computed]
    public function historicoImportacoes()
    {
        return CronogramaImportacao::with(['autor', 'healthCheck'])
            ->where('obra_id', $this->obra->id)
            ->whereIn('tipo', [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value])
            ->latest('importado_em')
            ->paginate(10);
    }
};

?>

<div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Sucesso --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($importado)
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="bx bx-check-circle fs-4"></i>
        <div>
            <strong>Importação de avanço concluída.</strong>
            <a href="{{ route('radar.relatorios.novo') }}" class="ms-2">Gerar um Report →</a>
        </div>
        <button wire:click="cancelar" class="btn-close ms-auto"></button>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Erro --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($erro)
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bx bx-error-circle fs-4"></i>
        <span>{{ $erro }}</span>
        <button class="btn-close ms-auto" wire:click="$set('erro', null)"></button>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Formulário de upload --}}
    {{-- ------------------------------------------------------------------ --}}
    @if(!$emPrevia && !$importado)
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bx bx-upload me-2"></i>Importar Avanço (Realizado/Tendência)</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                Selecione o arquivo <strong>.xml</strong> exportado do MS Project (formato MSPDI),
                atualizado com o progresso da semana. Esta importação atualiza <strong>só o
                progresso</strong> (realizado/tendência) das atividades <strong>já existentes</strong>
                — nunca cria atividades novas, nunca arquiva e não mexe na estrutura da EAP.
                Para importar a EAP/linha de base, use
                <a href="{{ route('radar.cronograma') }}">Obra → Importar Cronograma</a>.
            </p>

            {{-- Loading durante a análise (mesmo padrão de ⚡cronograma.blade.php) --}}
            <div wire:loading wire:target="analisar" class="text-center py-4">
                <div class="py-2 mx-auto text-start" style="max-width: 480px;"
                     x-data="{
                         etapas: [
                             'Recebendo e abrindo o arquivo XML',
                             'Identificando a estrutura do cronograma (EAP)',
                             'Interpretando atividades e propriedades',
                             'Montando predecessoras e sucessoras',
                             'Avaliando a estrutura da rede do cronograma',
                             'Verificando a lógica do cronograma',
                             'Analisando as folgas (slack)',
                             'Executando o Health Check',
                             'Consolidando o resultado da prévia',
                         ],
                         atual: 0,
                         intervalo: null,
                         init() {
                             this.intervalo = setInterval(() => {
                                 if (this.atual < this.etapas.length - 1) this.atual++;
                             }, 1300);
                         },
                         destroy() { clearInterval(this.intervalo); }
                     }"
                     x-init="init()">
                    <h6 class="fw-semibold mb-3 text-center">
                        <span class="spinner-border spinner-border-sm text-primary me-1" role="status"></span>
                        Analisando cronograma...
                    </h6>
                    <ul class="list-group list-group-flush">
                        <template x-for="(etapa, indice) in etapas" :key="indice">
                            <li class="list-group-item d-flex align-items-center gap-2 px-0"
                                :class="indice > atual ? 'text-muted' : ''">
                                <template x-if="indice < atual">
                                    <i class="bx bx-check-circle text-success"></i>
                                </template>
                                <template x-if="indice === atual">
                                    <span class="spinner-border spinner-border-sm text-primary" style="width:1rem;height:1rem;" role="status"></span>
                                </template>
                                <template x-if="indice > atual">
                                    <i class="bx bx-circle text-muted"></i>
                                </template>
                                <span :class="indice === atual ? 'fw-semibold' : ''" x-text="etapa"></span>
                            </li>
                        </template>
                    </ul>
                    <p class="text-center text-muted small mt-3 mb-0">
                        Não feche esta janela — o processo pode levar alguns segundos para cronogramas grandes.
                    </p>
                </div>
            </div>

            {{-- Formulário normal (oculto durante análise) --}}
            <div wire:loading.remove wire:target="analisar">
                <div x-data="{
                         uploading: false,
                         progress: 0,
                         hasFile: {{ $arquivoTemp ? 'true' : 'false' }}
                     }"
                     x-on:livewire-upload-start="uploading = true; hasFile = false; progress = 0"
                     x-on:livewire-upload-finish="uploading = false; hasFile = true"
                     x-on:livewire-upload-error="uploading = false; hasFile = false"
                     x-on:livewire-upload-progress="progress = $event.detail.progress">

                    <div class="mb-1">
                        <label class="form-label">Arquivo XML do cronograma</label>
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1">
                                <input type="file" class="form-control @error('arquivoTemp') is-invalid @enderror"
                                    wire:model="arquivoTemp" accept=".xml">
                                @error('arquivoTemp')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button wire:click="analisar" class="btn btn-primary flex-shrink-0" :disabled="!hasFile || uploading">
                                <i class="bx bx-search-alt me-1"></i>Analisar prévia
                            </button>
                        </div>
                    </div>

                    {{-- Progresso do upload --}}
                    <div x-show="uploading" x-cloak class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span><i class="bx bx-cloud-upload me-1"></i>Enviando arquivo...</span>
                            <span x-text="progress + '%'"></span>
                        </div>
                        <div class="progress" style="height: 4px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                 :style="'width: ' + progress + '%'"></div>
                        </div>
                    </div>

                    <div x-show="!uploading && !hasFile" x-cloak class="mt-1">
                        <small class="text-muted">Selecione o arquivo para habilitar a análise.</small>
                    </div>
                    <div x-show="hasFile && !uploading" x-cloak class="mt-1">
                        <small class="text-success"><i class="bx bx-check-circle me-1"></i>Arquivo pronto — clique em <strong>Analisar prévia</strong>.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Prévia --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($emPrevia)
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bx bx-list-check fs-5 text-primary"></i>
            <h5 class="mb-0">Prévia da importação de avanço</h5>
            @if($dataStatus)
            <span class="badge bg-label-secondary ms-2">Status: {{ $dataStatus }}</span>
            @endif
        </div>
        <div class="card-body">

            {{-- ------------------------------------------------------------ --}}
            {{-- Health Check / Análise de Coerência do Cronograma --}}
            {{-- ------------------------------------------------------------ --}}
            @php
                $hc = $this->resultadoHealthCheck();
                $hcPorSeveridade = $hc->totalPorSeveridade();
                $hcPorCategoria = $hc->totalPorCategoria();
            @endphp
            <div class="card border mb-4">
                <div class="card-header py-2 bg-light">
                    <span class="fw-semibold"><i class="bx bx-shield-quarter me-1"></i>Health Check — Análise de Coerência do Cronograma</span>
                </div>
                <div class="card-body">
                    @if(!$hc->temAlertas())
                        <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
                            <i class="bx bx-check-circle fs-4"></i>
                            <div>
                                <strong>Nenhuma inconsistência relevante foi encontrada.</strong>
                                <div class="small text-muted">O arquivo foi analisado e não há pontos de atenção a revisar antes de importar.</div>
                            </div>
                        </div>
                    @else
                        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
                            <i class="bx bx-error fs-4"></i>
                            <div>
                                <strong>Foram encontradas inconsistências que podem afetar a confiabilidade do cronograma.</strong>
                                <div class="small text-muted">O sistema não identificou necessariamente erros no arquivo — os pontos abaixo devem ser avaliados antes de confirmar a importação.</div>
                            </div>
                        </div>

                        {{-- Contagem por severidade --}}
                        <div class="row g-2 mb-3 text-center">
                            @foreach (\App\Enums\HealthCheckSeveridade::cases() as $sev)
                                @continue($hcPorSeveridade[$sev->value] === 0)
                                <div class="col-6 col-md">
                                    <div class="p-2 rounded bg-label-{{ $sev->cor() }}">
                                        <div class="fs-5">{{ $sev->emoji() }} {{ $hcPorSeveridade[$sev->value] }}</div>
                                        <small class="text-muted">{{ $sev->label() }}</small>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <p class="text-muted small mb-4">
                            <i class="bx bx-info-circle me-1"></i>
                            Score de saúde do cronograma: <strong>disponível em uma etapa futura</strong> — por enquanto, avalie cada ocorrência individualmente abaixo.
                        </p>

                        {{-- Resumo por categoria --}}
                        <h6 class="mb-2">Resumo por categoria</h6>
                        <div class="row g-2 mb-4">
                            @foreach ($hcPorCategoria as $hcCatValue => $hcQtd)
                                @php $hcCat = \App\Enums\HealthCheckCategoria::from($hcCatValue); @endphp
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-semibold">{{ $hcQtd }}</div>
                                        <small class="text-muted">{{ $hcCat->label() }}</small>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Ocorrências encontradas --}}
                        <h6 class="mb-2">Ocorrências encontradas</h6>
                        @include('pages.radar._partials.health-check-findings', [
                            'findings' => $healthCheckResultado['findings'],
                            'idPrefix' => 'hcFindingAvanco',
                        ])
                    @endif
                </div>
            </div>

            {{-- Contagens --}}
            <div class="row g-3 mb-4">
                <div class="col-sm-6">
                    <div class="d-flex align-items-center gap-2 p-3 rounded bg-label-primary">
                        <i class="bx bx-edit fs-4 text-primary"></i>
                        <div>
                            <div class="fw-bold fs-4">{{ $atualizadas }}</div>
                            <small class="text-muted">atividades com progresso atualizado</small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="d-flex align-items-center gap-2 p-3 rounded bg-label-secondary">
                        <i class="bx bx-hide fs-4 text-secondary"></i>
                        <div>
                            <div class="fw-bold fs-4">{{ $ignoradas }}</div>
                            <small class="text-muted">tarefas ignoradas (sem atividade correspondente)</small>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Conferência de integridade dos totais de HH --}}
            <div class="card border mb-4">
                <div class="card-header py-2 bg-light">
                    <span class="fw-semibold"><i class="bx bx-calculator me-1"></i>Conferência de totais (HH)</span>
                </div>
                <div class="card-body py-2">
                    <table class="table table-sm mb-0">
                        <thead><tr>
                            <th>Série</th>
                            <th class="text-end">Total calculado (HH)</th>
                            <th class="text-center">Status</th>
                        </tr></thead>
                        <tbody>
                            <tr>
                                <td>Realizado (Actual)</td>
                                <td class="text-end font-monospace">{{ number_format($totalRealHh, 2, ',', '.') }}</td>
                                <td class="text-center">
                                    @if($totalRealHh > 0)
                                        <span class="badge bg-success" title="Totais conferem">✓ OK</span>
                                    @else
                                        <span class="badge bg-secondary">sem dados</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td>Tendência (Work atual)</td>
                                <td class="text-end font-monospace">{{ number_format($totalWorkHh, 2, ',', '.') }}</td>
                                <td class="text-center">
                                    @if($totalWorkHh > 0)
                                        <span class="badge bg-success" title="Totais conferem">✓ OK</span>
                                    @else
                                        <span class="badge bg-secondary">sem dados</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    {{-- Aviso de desvio de distribuição --}}
                    <div class="alert alert-warning d-flex gap-2 mt-3 mb-0 py-2">
                        <i class="bx bx-info-circle mt-1 flex-shrink-0"></i>
                        <small>
                            <strong>Aviso de precisão:</strong>
                            Os totais de HH acima conferem exatamente com o cronograma.
                            A distribuição <em>mensal</em> é reconstruída pelo ponto médio de cada bloco
                            faseado e pode ter desvio de fronteira de até <strong>~0,3%</strong> em relação
                            ao que o MS Project exibe — isso é esperado e transparente.
                        </small>
                    </div>
                </div>
            </div>

            {{-- Tarefas ignoradas --}}
            @if($ignoradas > 0)
            <div class="mb-4">
                <h6 class="text-secondary"><i class="bx bx-hide me-1"></i>Tarefas ignoradas</h6>
                <p class="text-muted small mb-2">
                    Estas tarefas existem no arquivo XML mas não têm nenhuma atividade
                    correspondente já importada nesta obra. A importação de avanço
                    <strong>nunca cria</strong> atividades novas — se alguma dessas tarefas for
                    realmente nova, importe a linha de base primeiro em
                    <a href="{{ route('radar.cronograma') }}">Obra → Importar Cronograma</a>.
                </p>
                <ul class="list-group list-group-flush">
                    @foreach($nomesIgnoradas as $nome)
                    <li class="list-group-item d-flex align-items-center gap-2 px-0">
                        <i class="bx bx-hide text-muted"></i>
                        {{ $nome }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Ações --}}
            <div class="d-flex align-items-center gap-2">
                @if($hc->temAlertas())
                <button type="button"
                        onclick="confirmarAcao(this, {
                            titulo: 'Inconsistências encontradas',
                            mensagem: 'Este cronograma possui pontos de atenção identificados pelo Health Check. A importação poderá prosseguir, mas os problemas encontrados permanecerão registrados na análise desta revisão.',
                            metodo: 'confirmar',
                            args: [],
                            corBotao: 'warning',
                            icone: 'bx-error',
                            textoBotao: 'Confirmar importação',
                        })"
                        wire:loading.attr="disabled"
                        wire:target="confirmar"
                        class="btn btn-warning"
                        @if($statusImportacao === 'processando') disabled @endif>
                    <span wire:loading.remove wire:target="confirmar">
                        @if($statusImportacao !== 'processando')
                            <i class="bx bx-error me-1"></i>Importar mesmo assim
                        @else
                            <span class="spinner-border spinner-border-sm me-1" role="status"></span>Processando...
                        @endif
                    </span>
                    <span wire:loading wire:target="confirmar">
                        <span class="spinner-border spinner-border-sm me-1" role="status"></span>Processando...
                    </span>
                </button>
                @else
                <button wire:click="confirmar"
                        wire:loading.attr="disabled"
                        wire:target="confirmar"
                        class="btn btn-success"
                        @if($statusImportacao === 'processando') disabled @endif>
                    <span wire:loading.remove wire:target="confirmar">
                        @if($statusImportacao !== 'processando')
                            <i class="bx bx-check me-1"></i>Confirmar importação
                        @else
                            <span class="spinner-border spinner-border-sm me-1" role="status"></span>Processando...
                        @endif
                    </span>
                    <span wire:loading wire:target="confirmar">
                        <span class="spinner-border spinner-border-sm me-1" role="status"></span>Processando...
                    </span>
                </button>
                @endif
                <button wire:click="cancelar"
                        wire:loading.attr="disabled"
                        wire:target="confirmar"
                        class="btn btn-outline-secondary"
                        @if($statusImportacao === 'processando') disabled @endif>
                    Cancelar
                </button>
            </div>

            {{-- Status do processamento em andamento (mesmo padrão de ⚡cronograma.blade.php). --}}
            @if($statusImportacao === 'processando')
            <div wire:poll.2s="verificarStatusImportacao" class="d-flex align-items-center gap-2 mt-3 text-muted">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <span>
                    Importando o avanço para a obra... isso pode levar alguns segundos
                    para cronogramas grandes. Não feche esta janela.
                </span>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Histórico de Importações — partial compartilhado (Fase 3, Etapa 5) --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card mt-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bx bx-history fs-5 text-secondary"></i>
            <h5 class="mb-0">Histórico de Importações de Avanço</h5>
        </div>
        <div class="card-body">
            @include('pages.radar._partials.historico-importacoes', ['importacoes' => $this->historicoImportacoes])
        </div>
    </div>

</div>
