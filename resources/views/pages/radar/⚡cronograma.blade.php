<?php

use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Jobs\ImportarCronogramaJob;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\Work;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
  use WithFileUploads;

  public Work $obra;

  public $arquivoTemp = null;

  // Limite de upload excedido (plano do tenant) — tratado à parte do
  // $errors bag padrão do Livewire pra poder mostrar uma mensagem
  // amigável com CTA de upgrade, em vez do "não pode ser superior a N
  // kilobytes" genérico. Alimentado tanto pelo catch em analisar()
  // (quando o arquivo já chegou a esta instância) quanto pelo evento
  // JS 'livewire-upload-error' (quando o upload falha antes disso, no
  // endpoint do próprio Livewire — ver DefinirLimiteUploadDoTenant).
  public bool $excedeuLimiteUpload = false;
  public ?float $tamanhoArquivoMb = null;

  protected function rules(): array
  {
    $mb = $this->obra->tenant->limiteUploadMb();

    return [
      'arquivoTemp' => ['required', 'file', 'max:' . ($mb * 1024), 'mimetypes:text/xml,application/xml'],
    ];
  }

  /** Chamado do JS quando o upload falha no endpoint do Livewire (arquivo maior que o limite do plano). */
  public function marcarLimiteUploadExcedido(?float $tamanhoArquivoMb = null): void
  {
    $this->excedeuLimiteUpload = true;
    $this->tamanhoArquivoMb = $tamanhoArquivoMb;
    $this->arquivoTemp = null;
  }

  // Estado da prévia
  public bool $emPrevia = false;
  public bool $importado = false;
  public ?string $erro = null;

  // Contagens e totais extraídos do PlanoImportacao
  public int $criadas = 0;
  public int $atualizadas = 0;
  public int $arquivadas = 0;
  public float $totalBaselineHh = 0.0;
  public float $totalWorkHh = 0.0;
  public float $totalRealHh = 0.0;
  public ?string $dataStatus = null;
  public array $nomesArquivadas = [];
  public array $arquivadasComRestricoes = []; // nome => qtd restrições

  // Caminho do arquivo temp armazenado (passado ao job)
  public ?string $caminhoArquivo = null;

  // Acompanhamento do job de importação (funciona com fila síncrona ou
  // assíncrona: o status fica em cache, fora da transação do importador,
  // e o front consulta via wire:poll até virar 'concluido'/'erro').
  public ?string $importacaoTrackingId = null;
  public ?string $statusImportacao = null;

  public function mount(Work $obra): void
  {
    $this->obra = $obra;
  }

  public function analisar(): void
  {
    $this->excedeuLimiteUpload = false;
    $this->tamanhoArquivoMb = null;

    try {
      $this->validateOnly('arquivoTemp');
    } catch (\Illuminate\Validation\ValidationException $e) {
      if (array_key_exists('Max', $e->validator->failed()['arquivoTemp'] ?? [])) {
        $tamanhoMb = $this->arquivoTemp?->getSize() ? round($this->arquivoTemp->getSize() / 1024 / 1024, 1) : null;
        $this->marcarLimiteUploadExcedido($tamanhoMb);
        return;
      }

      throw $e;
    }

    $this->erro = null;

    try {
      $path = $this->arquivoTemp->storeAs('imports/temp', 'cron_' . $this->obra->id . '_' . time() . '.xml', 'local');
      $this->caminhoArquivo = storage_path('app/' . $path);

      $plano = app(ImportadorCronograma::class)->analisar(
        $this->caminhoArquivo,
        $this->obra,
        TipoCronogramaImportacao::Baseline
      );

      $this->criadas = count($plano->criar);
      $this->atualizadas = count($plano->atualizar);
      $this->arquivadas = count($plano->removerIds);
      $this->totalBaselineHh = $plano->totalBaselineHh;
      $this->totalWorkHh = $plano->totalWorkHh;
      $this->totalRealHh = $plano->totalRealHh;
      $this->dataStatus = $plano->dataStatus?->format('d/m/Y');
      $this->nomesArquivadas = $plano->removerNomes;

      if ($plano->removerIds) {
        $this->arquivadasComRestricoes = Atividade::whereIn('id', $plano->removerIds)
          ->whereHas('restricoes')
          ->withCount('restricoes')
          ->pluck('restricoes_count', 'nome')
          ->all();
      }

      $this->emPrevia = true;
    } catch (\Throwable $e) {
      $this->erro = 'Erro ao processar o arquivo: ' . $e->getMessage();
    }
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
        TipoCronogramaImportacao::Baseline,
        $this->importacaoTrackingId,
      );
    } catch (\Throwable $e) {
      // Fila síncrona (dev): o job roda inline e uma falha relança a
      // exceção até aqui. O cache já foi marcado 'erro' dentro do job
      // antes do rethrow — verificarStatusImportacao() abaixo lê de lá.
    }

    // Em fila síncrona (dev), o job acima já rodou por completo — resolve
    // na hora. Em fila assíncrona (produção), ainda não há nada em cache
    // além de 'processando', e o front continua via wire:poll.
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
      $this->reset([
        'emPrevia',
        'arquivoTemp',
        'caminhoArquivo',
        'criadas',
        'atualizadas',
        'arquivadas',
        'totalBaselineHh',
        'totalWorkHh',
        'totalRealHh',
        'dataStatus',
        'nomesArquivadas',
        'arquivadasComRestricoes',
      ]);
      $this->importado = true;
      $this->dispatch('show-toast', message: 'Cronograma importado com sucesso!');
    } else {
      $this->erro = 'Erro ao processar a importação: ' . ($status['erro'] ?? 'erro desconhecido');
    }
  }

  #[Computed]
  public function historicoImportacoes()
  {
    return CronogramaImportacao::with('autor')
      ->withSum(
        ['avancoPeriodos as total_hh' => fn($q) => $q->where('serie', 'previsto')->where('granularidade', 'mensal')],
        'horas'
      )
      ->withMin('atividadeSnapshots as baseline_inicio', 'baseline_inicio')
      ->withMax('atividadeSnapshots as baseline_termino', 'baseline_termino')
      ->where('obra_id', $this->obra->id)
      ->whereIn('tipo', [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value])
      ->latest('importado_em')
      ->get();
  }

  public function cancelar(): void
  {
    // Não permite cancelar com um job de importação em andamento — o
    // arquivo temp ainda está sendo lido pelo job.
    abort_if($this->statusImportacao === 'processando', 422);

    if ($this->caminhoArquivo && file_exists($this->caminhoArquivo)) {
      @unlink($this->caminhoArquivo);
    }
    $this->reset([
      'emPrevia',
      'arquivoTemp',
      'caminhoArquivo',
      'criadas',
      'atualizadas',
      'arquivadas',
      'totalBaselineHh',
      'totalWorkHh',
      'totalRealHh',
      'dataStatus',
      'nomesArquivadas',
      'arquivadasComRestricoes',
      'importado',
      'erro',
      'importacaoTrackingId',
      'statusImportacao',
    ]);
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
            <strong>Importação concluída.</strong>
            <a href="{{ route('radar.curvas') }}" class="ms-2">Ver curvas de avanço →</a>
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
    {{-- Modal: como exportar XML do MS Project --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="modal fade" id="modalInstrucoesMSP" tabindex="-1" aria-labelledby="modalInstrucoesMSPLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalInstrucoesMSPLabel">
                        <i class="bx bx-help-circle me-2 text-primary"></i>Como exportar o XML do MS Project
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info d-flex gap-2 mb-4">
                        <i class="bx bx-info-circle mt-1 flex-shrink-0"></i>
                        <span>O DCF Radar lê o formato <strong>MSPDI</strong> (XML nativo do MS Project). Siga os passos abaixo para gerar o arquivo corretamente.</span>
                    </div>

                    <ol class="list-group list-group-numbered list-group-flush mb-4">
                        <li class="list-group-item d-flex gap-3 px-0">
                            <div>
                                <strong>Abra o projeto no MS Project</strong>
                                <div class="text-muted small">Certifique-se de que o arquivo está salvo e atualizado com os dados mais recentes.</div>
                            </div>
                        </li>
                        <li class="list-group-item d-flex gap-3 px-0">
                            <div>
                                <strong>Acesse o menu <kbd>Arquivo</kbd> → <kbd>Salvar como</kbd></strong>
                                <div class="text-muted small">Em versões mais novas: <kbd>Arquivo</kbd> → <kbd>Exportar</kbd> → <kbd>Salvar projeto como arquivo</kbd>.</div>
                            </div>
                        </li>
                        <li class="list-group-item d-flex gap-3 px-0">
                            <div>
                                <strong>No tipo de arquivo, selecione <kbd>XML Format (*.xml)</kbd></strong>
                                <div class="text-muted small">Esse é o formato MSPDI — diferente do .mpp. Não confunda com "Pasta de trabalho do Excel".</div>
                            </div>
                        </li>
                        <li class="list-group-item d-flex gap-3 px-0">
                            <div>
                                <strong>Clique em <kbd>Salvar</kbd></strong>
                                <div class="text-muted small">O MS Project pode exibir um aviso sobre dados que não serão exportados — pode ignorar e continuar.</div>
                            </div>
                        </li>
                        <li class="list-group-item d-flex gap-3 px-0">
                            <div>
                                <strong>Importe o arquivo <code>.xml</code> aqui no DCF Radar</strong>
                                <div class="text-muted small">Use o campo de upload abaixo. Apenas arquivos <code>.xml</code> são aceitos.</div>
                            </div>
                        </li>
                    </ol>

                    <div class="alert alert-warning d-flex gap-2 mb-0">
                        <i class="bx bx-error mt-1 flex-shrink-0"></i>
                        <div class="small">
                            <strong>Importante:</strong> Para que as curvas S funcionem corretamente, o cronograma precisa ter
                            <strong>recursos de trabalho atribuídos às tarefas</strong> com HH faseados (Baseline Work e Actual Work preenchidos).
                            Tarefas sem recursos não geram dados de curva.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido!</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Formulário de upload --}}
    {{-- ------------------------------------------------------------------ --}}
    @if(!$emPrevia && !$importado)
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bx bx-upload me-2"></i>Importar Cronograma MS Project (Linha de Base)</h5>
            <a href="#" data-bs-toggle="modal" data-bs-target="#modalInstrucoesMSP"
               class="text-primary small d-flex align-items-center gap-1">
                <i class="bx bx-help-circle"></i> Como gerar o arquivo .xml?
            </a>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4">
                Selecione o arquivo <strong>.xml</strong> exportado do MS Project (formato MSPDI).
                Uma <strong>prévia</strong> será exibida antes de confirmar a importação. Esta importação cria/
                atualiza/arquiva atividades e pacotes da EAP e grava a <strong>linha de base</strong>
                (Previsto) — para atualizar realizado/tendência semanalmente, use
                <a href="{{ route('radar.relatorios.importar-avanco') }}">Relatórios → Importar Avanço</a>.
            </p>

            {{-- Loading durante a análise --}}
            <div class="row">
              <div class="col-12 text-center">


            <div wire:loading wire:target="analisar" class="text-center py-4">
                <div class="py-4"
                     x-data="{
                         etapas: [
                             { icon: '📂', texto: 'Abrindo o arquivo XML...', detalhe: 'lendo o que o MS Project guardou' },
                             { icon: '🔍', texto: 'Vasculhando as tarefas...', detalhe: 'cada linha do cronograma, uma a uma' },
                             { icon: '⏱️', texto: 'Calculando os HH faseados...', detalhe: 'semana a semana, hora a hora' },
                             { icon: '🏗️', texto: 'Organizando a EAP...', detalhe: 'sim, até a hierarquia auto-aninhada' },
                             { icon: '🧮', texto: 'Fechando os números...', detalhe: 'garantindo que os totais batem (±0,3%)' },
                             { icon: '🗂️', texto: 'Comparando com o cronograma atual...', detalhe: 'o que criou, atualizou e some do .xml' },
                             { icon: '✅', texto: 'Montando a prévia...', detalhe: 'quase pronto — só mais um segundo' },
                         ],
                         atual: 0,
                         progresso: 5,
                         intervaloEtapa: null,
                         intervaloProgresso: null,
                         init() {
                             this.intervaloEtapa = setInterval(() => {
                                 if (this.atual < this.etapas.length - 1) this.atual++;
                             }, 2200);
                             this.intervaloProgresso = setInterval(() => {
                                 const teto = [12, 28, 45, 60, 74, 86, 95][this.atual] ?? 95;
                                 if (this.progresso < teto) this.progresso = Math.min(this.progresso + 1, teto);
                             }, 120);
                         },
                         destroy() {
                             clearInterval(this.intervaloEtapa);
                             clearInterval(this.intervaloProgresso);
                         }
                     }"
                     x-init="init()">

                    <div class="text-center mb-4 w-100">
                        <div class="display-4 mb-2" x-text="etapas[atual].icon" style="line-height:1"></div>
                        <h5 class="fw-semibold mb-1" x-text="etapas[atual].texto"></h5>
                        <p class="text-muted small mb-0" x-text="etapas[atual].detalhe"></p>
                    </div>

                    <div class="mx-auto w-100" style="max-width: 480px;">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Analisando cronograma...</span>
                            <span x-text="progresso + '%'"></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                                 role="progressbar"
                                 :style="'width: ' + progresso + '%'"
                                 :aria-valuenow="progresso"
                                 aria-valuemin="0"
                                 aria-valuemax="100">
                            </div>
                        </div>
                        <p class="text-center text-muted small mt-3 mb-0">
                            Não feche esta janela — o processo pode levar alguns segundos para cronogramas grandes.
                        </p>
                    </div>
                </div>
            </div>

            </div>
            </div>

            {{-- Formulário normal (oculto durante análise) --}}
            <div wire:loading.remove wire:target="analisar">
                <div x-data="{
                         uploading: false,
                         progress: 0,
                         hasFile: {{ $arquivoTemp ? 'true' : 'false' }},
                         tamanhoSelecionadoMb: null
                     }"
                     x-on:livewire-upload-start="uploading = true; progress = 0; $wire.set('excedeuLimiteUpload', false)"
                     x-on:livewire-upload-finish="uploading = false; hasFile = true"
                     x-on:livewire-upload-error="uploading = false; $wire.marcarLimiteUploadExcedido(tamanhoSelecionadoMb)"
                     x-on:livewire-upload-progress="progress = $event.detail.progress">

                    <div class="mb-1">
                        <label class="form-label fw-medium">Arquivo XML do cronograma</label>
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1">
                                <input type="file"
                                       class="form-control @error('arquivoTemp') is-invalid @enderror"
                                       wire:model="arquivoTemp"
                                       accept=".xml"
                                       x-on:change="
                                           hasFile = false;
                                           const arquivo = $event.target.files[0];
                                           tamanhoSelecionadoMb = arquivo ? +(arquivo.size / 1024 / 1024).toFixed(1) : null;
                                       ">
                                @error('arquivoTemp')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                @if($excedeuLimiteUpload)
                                <div class="alert alert-warning d-flex gap-2 mt-2 mb-0 py-2">
                                    <i class="bx bx-error mt-1 flex-shrink-0"></i>
                                    <div class="small">
                                        <strong>Arquivo grande demais para o seu plano.</strong>
                                        @if($tamanhoArquivoMb)
                                            O arquivo selecionado tem aproximadamente {{ number_format($tamanhoArquivoMb, 1) }} MB
                                        @else
                                            O arquivo selecionado
                                        @endif
                                        e o limite do plano atual é de {{ $obra->tenant->limiteUploadMb() }} MB.
                                        @if(auth()->user()->podeGerenciarTenant($obra->tenant))
                                            <a href="{{ route('app.empresa.assinatura') }}" class="fw-semibold">Faça upgrade do plano para importar arquivos maiores →</a>
                                        @else
                                            Peça ao administrador da conta pra fazer upgrade do plano.
                                        @endif
                                    </div>
                                </div>
                                @endif
                            </div>
                            <button wire:click="analisar"
                                    class="btn btn-primary flex-shrink-0"
                                    :disabled="!hasFile || uploading">
                                <i class="bx bx-search-alt me-1"></i>Analisar prévia
                            </button>
                        </div>
                    </div>

                    {{-- Progresso do upload --}}
                    <div x-show="uploading" x-cloak class="mt-4">
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
            <h5 class="mb-0">Prévia da importação</h5>
            @if($dataStatus)
            <span class="badge bg-label-secondary ms-2">Status: {{ $dataStatus }}</span>
            @endif
        </div>
        <div class="card-body">

            {{-- Contagens --}}
            <div class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="d-flex align-items-center gap-2 p-3 rounded bg-label-success">
                        <i class="bx bx-plus-circle fs-4 text-success"></i>
                        <div>
                            <div class="fw-bold fs-4">{{ $criadas }}</div>
                            <small class="text-muted">atividades a criar</small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="d-flex align-items-center gap-2 p-3 rounded bg-label-primary">
                        <i class="bx bx-edit fs-4 text-primary"></i>
                        <div>
                            <div class="fw-bold fs-4">{{ $atualizadas }}</div>
                            <small class="text-muted">atividades a atualizar</small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="d-flex align-items-center gap-2 p-3 rounded bg-label-warning">
                        <i class="bx bx-archive fs-4 text-warning"></i>
                        <div>
                            <div class="fw-bold fs-4">{{ $arquivadas }}</div>
                            <small class="text-muted">atividades a arquivar</small>
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
                                <td>Previsto (Baseline)</td>
                                <td class="text-end font-monospace">{{ number_format($totalBaselineHh, 2) }}</td>
                                <td class="text-center">
                                    @if($totalBaselineHh > 0)
                                        <span class="badge bg-success" title="Totais conferem">✓ OK</span>
                                    @else
                                        <span class="badge bg-secondary">sem dados</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <small class="text-muted d-block mt-2">
                        Esta importação grava só a <strong>linha de base</strong>. Realizado e
                        tendência são importados semanalmente em
                        <a href="{{ route('radar.relatorios.importar-avanco') }}">Relatórios → Importar Avanço</a>.
                    </small>

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

            {{-- Atividades arquivadas --}}
            @if($arquivadas > 0)
            <div class="mb-4">
                <h6 class="text-warning"><i class="bx bx-archive me-1"></i>Atividades que serão arquivadas</h6>
                <p class="text-muted small mb-2">
                    Estas atividades não estão mais no arquivo XML. Serão marcadas como
                    <code>fora_do_cronograma</code> e <strong>nunca apagadas</strong> — todas as
                    restrições vinculadas são preservadas.
                </p>
                <ul class="list-group list-group-flush">
                    @foreach($nomesArquivadas as $nome)
                    @php $qtdRest = $arquivadasComRestricoes[$nome] ?? 0; @endphp
                    <li class="list-group-item d-flex align-items-center gap-2 px-0
                        {{ $qtdRest > 0 ? 'text-warning fw-semibold' : '' }}">
                        <i class="bx {{ $qtdRest > 0 ? 'bx-error text-warning' : 'bx-archive text-muted' }}"></i>
                        {{ $nome }}
                        @if($qtdRest > 0)
                        <span class="badge bg-warning text-dark ms-auto"
                              title="Esta atividade tem restrições ativas — não será apagada">
                            {{ $qtdRest }} restrição(ões)
                        </span>
                        @endif
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Ações --}}
            <div class="d-flex align-items-center gap-2">
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
                <button wire:click="cancelar"
                        wire:loading.attr="disabled"
                        wire:target="confirmar"
                        class="btn btn-outline-secondary"
                        @if($statusImportacao === 'processando') disabled @endif>
                    Cancelar
                </button>
            </div>

            {{-- Status do processamento em andamento (funciona tanto se o
                 job já terminou dentro da própria requisição — fila
                 síncrona — quanto se ainda está rodando em background —
                 fila assíncrona, via polling). --}}
            @if($statusImportacao === 'processando')
            <div wire:poll.2s="verificarStatusImportacao" class="d-flex align-items-center gap-2 mt-3 text-muted">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <span>
                    Importando o cronograma para a obra... isso pode levar alguns segundos
                    para cronogramas grandes. Não feche esta janela.
                </span>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Histórico de importações --}}
    {{-- ------------------------------------------------------------------ --}}
    @php $historico = $this->historicoImportacoes; @endphp
    @if($historico->isNotEmpty())
    <div class="card mt-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bx bx-history fs-5 text-secondary"></i>
            <h5 class="mb-0">Histórico de Importações de Linha de Base</h5>
            <span class="badge bg-label-secondary ms-auto">{{ $historico->count() }} {{ $historico->count() === 1 ? 'importação' : 'importações' }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Importado por</th>
                        <th class="text-center">Criadas</th>
                        <th class="text-center">Atualizadas</th>
                        <th class="text-center">Arquivadas</th>
                        <th class="text-center">Início Linha de Base</th>
                        <th class="text-center">Término Linha de Base</th>
                        <th class="text-end">Total HH (Previsto)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($historico as $imp)
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $imp->importado_em->format('d/m/Y') }}</span>
                            <br><small class="text-muted">{{ $imp->importado_em->format('H:i') }}</small>
                        </td>
                        <td>
                          @if($imp->autor)
                            <div class="d-flex">
                              <div class="flex-shrink-0 me-3">
                                <div class="avatar">
                                  <img src="{{ $imp->autor ? $imp->autor->profile_photo_url : asset('assets/img/avatars/1.png') }}" alt class="rounded-circle">
                                </div>
                              </div>
                              <div class="flex-grow-1">
                                <span class="fw-medium d-block">
                                  {{ trim($imp->autor->first_name . ' ' . $imp->autor->last_name) }}
                                </span>
                                @php $obraAtualNavbar = \App\Support\ObraContext::current(); @endphp
                                @if ($obraAtualNavbar)
                                <small class="text-muted">
                                  {{ $imp->autor->perfilNaObra($obraAtualNavbar)?->nome }}
                                </small>
                                @endif
                              </div>
                            </div>
                          @else
                              <span class="text-muted">—</span>
                          @endif
                        </td>
                        <td class="text-center">
                            <span class="badge bg-label-success">{{ number_format($imp->criadas) }}</span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-label-primary">{{ number_format($imp->atualizadas) }}</span>
                        </td>
                        <td class="text-center">
                            @if($imp->removidas > 0)
                                <span class="badge bg-label-warning">{{ number_format($imp->removidas) }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($imp->baseline_inicio)
                                {{ \Illuminate\Support\Carbon::parse($imp->baseline_inicio)->format('d/m/Y') }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($imp->baseline_termino)
                                {{ \Illuminate\Support\Carbon::parse($imp->baseline_termino)->format('d/m/Y') }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end font-monospace">
                            @if($imp->total_hh)
                                {{ number_format($imp->total_hh, 0, ',', '.') }} HH
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>
