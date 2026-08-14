<?php

use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Jobs\ImportarCronogramaJob;
use App\Models\Atividade;
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

  // Health Check — calculado uma única vez em analisar() (sobre o
  // PlanoImportacao já em memória, nunca lendo o arquivo de novo nem
  // consultando o banco) e reaproveitado até a persistência: o resultado
  // que o usuário vê aqui é EXATAMENTE o que vai pro Job em confirmar() e,
  // dali, pra tabela cronograma_importacao_health_checks.
  public array $healthCheckResultado = ['findings' => []];

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

      $this->healthCheckResultado = app(HealthCheckEngine::class)->avaliar($plano, TipoCronogramaImportacao::Baseline)->toArray();

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
        TipoCronogramaImportacao::Baseline,
        $this->importacaoTrackingId,
        $this->healthCheckResultado,
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
        'healthCheckResultado',
      ]);
      $this->importado = true;
      $this->dispatch('show-toast', message: 'Cronograma importado com sucesso!');
    } else {
      $this->erro = 'Erro ao processar a importação: ' . ($status['erro'] ?? 'erro desconhecido');
    }
  }

  /**
   * Histórico de Importações (Fase 3, Etapa 5) — usa o partial compartilhado
   * historico-importacoes.blade.php, mesma apresentação de
   * ⚡relatorio-importar-avanco.blade.php/⚡obra-detalhe.blade.php. Filtro
   * por tipo é o único ajuste por página; colunas específicas de Baseline
   * (datas/HH que existiam aqui antes) saíram de propósito — unificação
   * pedida pelo usuário, "não quero duas implementações diferentes do
   * histórico" (ver CLAUDE.md).
   */
  #[Computed]
  public function historicoImportacoes()
  {
    return CronogramaImportacao::with(['autor', 'healthCheck'])
      ->where('obra_id', $this->obra->id)
      ->whereIn('tipo', [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value])
      ->latest('importado_em')
      ->paginate(10);
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
      'healthCheckResultado',
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
                     x-on:livewire-upload-start="uploading = true; hasFile = false; progress = 0; $wire.set('excedeuLimiteUpload', false)"
                     x-on:livewire-upload-finish="uploading = false; hasFile = true"
                     x-on:livewire-upload-error="uploading = false; hasFile = false; $wire.marcarLimiteUploadExcedido(tamanhoSelecionadoMb)"
                     x-on:livewire-upload-progress="progress = $event.detail.progress">
                    {{-- hasFile SÓ é controlado pelos eventos livewire-upload-* acima —
                         nunca pelo x-on:change do input abaixo. O change nativo e o
                         listener interno do Livewire (que dispara o upload via
                         wire:model) competem no mesmo evento 'change', sem ordem
                         garantida entre os dois; amarrar hasFile só ao que o próprio
                         Livewire confirma elimina essa corrida (Analisar prévia nunca
                         fica clicável antes do upload realmente terminar). --}}

                    <div class="mb-1">
                        <label class="form-label fw-medium">Arquivo XML do cronograma</label>
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1">
                                <input type="file"
                                       class="form-control @error('arquivoTemp') is-invalid @enderror"
                                       wire:model="arquivoTemp"
                                       accept=".xml"
                                       x-on:change="
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
                                            O arquivo selecionado tem aproximadamente {{ number_format($tamanhoArquivoMb, 1, ',', '.') }} MB
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
                            'idPrefix' => 'hcFinding',
                        ])
                    @endif
                </div>
            </div>

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
                                <td class="text-end font-monospace">{{ number_format($totalBaselineHh, 2, ',', '.') }}</td>
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
                @if($hc->temAlertas())
                {{-- Com alertas: exige confirmação extra explícita antes de importar (Health Check). --}}
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
    {{-- Histórico de Importações — partial compartilhado (Fase 3, Etapa 5) --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card mt-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bx bx-history fs-5 text-secondary"></i>
            <h5 class="mb-0">Histórico de Importações de Linha de Base</h5>
        </div>
        <div class="card-body">
            @include('pages.radar._partials.historico-importacoes', ['importacoes' => $this->historicoImportacoes])
        </div>
    </div>

</div>
