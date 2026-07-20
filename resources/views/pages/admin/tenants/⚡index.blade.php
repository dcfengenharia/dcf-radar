<?php

use App\Enums\StatusAssinatura;
use App\Models\Assinatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\Work;
use App\Support\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
  use WithPagination;
  protected $paginationTheme = 'bootstrap';

  public string $search = '';
  public string $filtroStatus = '';
  public string $filtroPlano = '';

  #[Computed]
  public function planos(): \Illuminate\Support\Collection
  {
    return Plano::orderBy('nome')->get();
  }

  #[Computed]
  public function statusDisponiveis(): array
  {
    return StatusAssinatura::cases();
  }

  /** Assinatura vigente (mais recente por início) de cada tenant, indexada por tenant_id. */
  #[Computed]
  public function assinaturasAtuais(): \Illuminate\Support\Collection
  {
    return Assinatura::with('plano')->get()
      ->groupBy('tenant_id')
      ->map(fn ($grupo) => $grupo->sortByDesc('inicio')->first());
  }

  #[Computed]
  public function tenants()
  {
    $tenantIdsFiltrados = null;

    if ($this->filtroStatus !== '' || $this->filtroPlano !== '') {
      $tenantIdsFiltrados = $this->assinaturasAtuais
        ->filter(function ($assinatura) {
          if ($this->filtroStatus !== '' && $assinatura->status->value !== $this->filtroStatus) {
            return false;
          }
          if ($this->filtroPlano !== '' && $assinatura->plano_id !== $this->filtroPlano) {
            return false;
          }
          return true;
        })
        ->keys()
        ->all();
    }

    return Tenant::query()
      ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
      ->when($tenantIdsFiltrados !== null, fn ($q) => $q->whereIn('id', $tenantIdsFiltrados))
      ->orderBy('name')
      ->paginate(7);
  }

  public function updatedSearch(): void
  {
    $this->resetPage();
  }

  public function updatedFiltroStatus(): void
  {
    $this->resetPage();
  }

  public function updatedFiltroPlano(): void
  {
    $this->resetPage();
  }

  public function contarObras(Tenant $tenant): int
  {
    return TenantContext::actingAs($tenant, fn () => Work::count());
  }
};
?>

<div>
    <div class="row mb-3 g-2">
        <div class="col-md-5">
            <input type="text" class="form-control" wire:model.live.debounce.400ms="search"
                   placeholder="Buscar por nome da conta...">
        </div>
        <div class="col-md-3">
            <select class="form-select" wire:model.live="filtroStatus">
                <option value="">Todos os status</option>
                @foreach ($this->statusDisponiveis as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <select class="form-select" wire:model.live="filtroPlano">
                <option value="">Todos os planos</option>
                @foreach ($this->planos as $plano)
                    <option value="{{ $plano->id }}">{{ $plano->nome }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Nome da Conta</th>
                        <th>Plano Atual</th>
                        <th>Status</th>
                        <th>Usuários</th>
                        <th>Obras</th>
                        <th>Criado em</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->tenants as $tenant)
                        @php
                            $assinatura = $this->assinaturasAtuais->get($tenant->id);
                        @endphp
                        <tr>
                            <td>{{ $tenant->name }}</td>
                            <td>{{ $assinatura?->plano->nome ?? '—' }}</td>
                            <td>
                                @if ($assinatura)
                                    <span class="badge bg-label-{{ $assinatura->status->corBadge() }}">
                                        {{ $assinatura->status->label() }}
                                    </span>
                                @else
                                    <span class="badge bg-label-secondary">Sem assinatura</span>
                                @endif
                            </td>
                            <td>{{ $tenant->users()->count() }}</td>
                            <td>{{ $this->contarObras($tenant) }}</td>
                            <td>{{ $tenant->created_at->format('d/m/y') }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.tenants.show', $tenant) }}" class="btn btn-xs btn-outline-secondary py-0 px-2">
                                    <i class="bx bx-show"></i> Ver detalhe
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">Nenhuma conta encontrada.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->tenants->hasPages())
            <div class="card-body">
                {{ $this->tenants->links() }}
            </div>
        @endif
    </div>
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
