<?php

use App\Models\Report;
use App\Models\Work;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Lista/histórico de Reports semanais da obra. Rascunhos só aparecem
 * pra quem tem papel mínimo de GerentePlanejamento (Report::
 * scopeVisivelPara) — mesma regra da App\Policies\ReportPolicy.
 */
new class extends Component {
    public Work $obra;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
    }

    #[Computed]
    public function reports(): \Illuminate\Support\Collection
    {
        return Report::where('obra_id', $this->obra->id)
            ->visivelPara(auth()->user(), $this->obra->id)
            ->with(['criador:id,first_name,last_name', 'emissor:id,first_name,last_name'])
            ->withCount('curvas')
            ->orderByDesc('periodo_referencia')
            ->get();
    }

    public function excluir(string $reportId): void
    {
        $report = Report::findOrFail($reportId);
        $this->authorize('delete', $report);

        $report->delete();
        unset($this->reports);
        $this->dispatch('show-toast', message: 'Report removido.');
    }
};

?>

<div>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h5 class="mb-0">Relatórios</h5>
            <small class="text-muted">Reports semanais de avanço — curvas S, desvios, pontos de atenção e fotos</small>
        </div>
        @can('create', [Report::class, $obra->id])
        <a href="{{ route('radar.relatorios.novo') }}" class="btn btn-primary">
            <i class="bx bx-plus me-1"></i>Novo Report
        </a>
        @endcan
    </div>

    @if($this->reports->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-file fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-0">Nenhum report disponível para esta obra ainda.</p>
        </div>
    </div>
    @else
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Período</th>
                        <th>Título</th>
                        <th class="text-center">Curvas</th>
                        <th>Status</th>
                        <th>Criado por</th>
                        <th>Emitido em</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->reports as $report)
                    <tr>
                        <td>
                            <a href="{{ route('radar.relatorios.show', $report) }}" class="fw-semibold">
                                {{ $report->periodo_referencia->format('d/m/Y') }}
                            </a>
                        </td>
                        <td>{{ $report->titulo ?: '—' }}</td>
                        <td class="text-center">{{ $report->curvas_count }}</td>
                        <td>
                            <span class="badge bg-{{ $report->status->corBadge() }}">
                                {{ $report->status->label() }}
                            </span>
                        </td>
                        <td class="small text-muted">
                            {{ $report->criador ? "{$report->criador->first_name} {$report->criador->last_name}" : '—' }}
                        </td>
                        <td class="small text-muted">
                            {{ $report->emitido_em?->format('d/m/Y H:i') ?? '—' }}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('radar.relatorios.show', $report) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bx bx-show"></i>
                            </a>
                            @can('delete', $report)
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="confirmarAcao(this, {
                                        mensagem: 'Remover este report? Esta ação não pode ser desfeita.',
                                        metodo: 'excluir',
                                        args: ['{{ $report->id }}'],
                                        icone: 'bx-trash',
                                    })">
                                <i class="bx bx-trash"></i>
                            </button>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>

@script
<script>
    $wire.on('show-toast', ({ message }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            toastr.success(message);
        }
    });
</script>
@endscript
