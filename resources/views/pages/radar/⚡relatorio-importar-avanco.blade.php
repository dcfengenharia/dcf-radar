<?php

use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Jobs\ImportarCronogramaJob;
use App\Models\Work;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

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

    // Caminho do arquivo temp armazenado (passado ao job)
    public ?string $caminhoArquivo = null;

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

            $this->emPrevia = true;
        } catch (\Throwable $e) {
            $this->erro = 'Erro ao processar o arquivo: ' . $e->getMessage();
        }
    }

    public function confirmar(): void
    {
        abort_unless($this->emPrevia && $this->caminhoArquivo !== null, 422);

        ImportarCronogramaJob::dispatch(
            $this->obra,
            $this->caminhoArquivo,
            auth()->id(),
            TipoCronogramaImportacao::Avanco
        );

        $this->reset(['emPrevia', 'arquivoTemp', 'caminhoArquivo',
            'atualizadas', 'ignoradas', 'totalRealHh', 'totalWorkHh',
            'dataStatus', 'nomesIgnoradas']);
        $this->importado = true;
        $this->dispatch('show-toast', message: 'Avanço importado com sucesso!');
    }

    public function cancelar(): void
    {
        if ($this->caminhoArquivo && file_exists($this->caminhoArquivo)) {
            @unlink($this->caminhoArquivo);
        }
        $this->reset(['emPrevia', 'arquivoTemp', 'caminhoArquivo',
            'atualizadas', 'ignoradas', 'totalRealHh', 'totalWorkHh',
            'dataStatus', 'nomesIgnoradas', 'importado', 'erro']);
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

            <div class="mb-3">
                <label class="form-label">Arquivo XML do cronograma</label>
                <input type="file" class="form-control @error('arquivoTemp') is-invalid @enderror"
                    wire:model="arquivoTemp" accept=".xml">
                @error('arquivoTemp')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <button wire:click="analisar" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading wire:target="analisar"
                      class="spinner-border spinner-border-sm me-1"></span>
                <i wire:loading.remove wire:target="analisar" class="bx bx-search-alt me-1"></i>
                Analisar prévia
            </button>
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
            <div class="d-flex gap-2">
                <button wire:click="confirmar" class="btn btn-success">
                    <i class="bx bx-check me-1"></i>Confirmar importação
                </button>
                <button wire:click="cancelar" class="btn btn-outline-secondary">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
    @endif

</div>
