<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use App\Models\Work;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

new class extends Component {
  use WithPagination;
  protected $paginationTheme = 'bootstrap';

  public ?Tenant $tenant = null;
  public string $search = '';
  public array $selectedIds = [];
  public ?string $confirmDeleteId = null;
  public ?string $confirmDeleteName = null;

  public function mount(): void
  {
    $this->tenant = Tenant::find(\App\Support\TenantContext::currentId());
  }

  #[Computed]
  public function works()
  {
    return Work::with('client')
      ->when($this->search, function ($q) {
        $q->where(function ($inner) {
          $inner
            ->where('name', 'like', "%{$this->search}%")
            ->orWhere('location', 'like', "%{$this->search}%")
            ->orWhereHas('client', fn($c) => $c->where('name', 'like', "%{$this->search}%"));
        });
      })
      ->orderBy('name')
      ->paginate(7);
  }

  public function updatedSearch(): void
  {
    $this->resetPage();
    $this->selectedIds = [];
  }

  public function clearSearch(): void
  {
    $this->search = '';
    $this->selectedIds = [];
    $this->resetPage();
  }

  #[On('work-created')]
  public function onWorkCreated(): void
  {
    $this->resetPage();
    $this->selectedIds = [];
  }

  #[On('work-updated')]
  public function onWorkUpdated(): void
  {
  }

  public function editWork(string $id): void
  {
    $this->dispatch('edit-work', id: $id);
  }

  public function toggleSelectAll(): void
  {
    $pageIds = $this->works->pluck('id')->toArray();
    $allSelected = !empty($pageIds) && collect($pageIds)->every(fn($id) => in_array($id, $this->selectedIds));

    $this->selectedIds = $allSelected
      ? array_values(array_diff($this->selectedIds, $pageIds))
      : array_values(array_unique(array_merge($this->selectedIds, $pageIds)));
  }

  public function openBulkDeleteModal(): void
  {
    if (empty($this->selectedIds)) {
      return;
    }
    $this->dispatch('show-bulk-delete-modal');
  }

  public function bulkDeleteWorks(): void
  {
    $works = Work::whereIn('id', $this->selectedIds)->get();

    foreach ($works as $work) {
      $this->authorize('delete', $work);
    }

    foreach ($works as $work) {
      $work->users()->detach();
      $work->delete();
    }

    $count = $works->count();
    $this->selectedIds = [];
    $this->dispatch('hide-bulk-delete-modal');
    $this->dispatch('show-toast', message: "{$count} obra(s) excluída(s) com sucesso!");
  }

  public function cancelBulkDelete(): void
  {
    $this->dispatch('hide-bulk-delete-modal');
  }

  public function confirmDelete(string $id): void
  {
    $work = Work::findOrFail($id);
    $this->confirmDeleteId = $work->id;
    $this->confirmDeleteName = $work->name;
    $this->dispatch('show-delete-modal');
  }

  public function deleteWork(): void
  {
    if (!$this->confirmDeleteId) {
      return;
    }

    $work = Work::findOrFail($this->confirmDeleteId);
    $this->authorize('delete', $work);
    $work->users()->detach();
    $work->delete();

    $this->selectedIds = array_values(array_diff($this->selectedIds, [$this->confirmDeleteId]));
    $this->confirmDeleteId = null;
    $this->confirmDeleteName = null;
    $this->dispatch('hide-delete-modal');
    $this->dispatch('show-toast', message: 'Obra excluída com sucesso!');
  }

  public function cancelDelete(): void
  {
    $this->confirmDeleteId = null;
    $this->confirmDeleteName = null;
    $this->dispatch('hide-delete-modal');
  }
};
?>

<div>
    {{-- Cabeçalho --}}
    <div class="row">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
                <div class="d-flex flex-column justify-content-center">
                    <h4 class="mb-1 mt-3">🏗️ Carteira de Obras do(a) <mark>{{ $tenant->name }}</mark></h4>
                    <p class="text-muted">Gerencie todas as obras cadastradas na sua empresa</p>
                </div>
                <div class="d-flex align-content-center flex-wrap gap-2">
                    @can('create', \App\Models\Work::class)
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#obraModal">
                        <i class="bx bx-hard-hat me-2 fs-5"></i>Nova Obra
                    </button>
                    @endcan
                </div>
            </div>
        </div>
    </div>

    <div>
        @php
            $pageIds          = $this->works->pluck('id')->toArray();
            $selectedOnPage   = collect($pageIds)->filter(fn($id) => in_array($id, $selectedIds))->count();
            $allOnPageSelected = count($pageIds) > 0 && $selectedOnPage === count($pageIds);
            $someOnPageSelected = $selectedOnPage > 0 && !$allOnPageSelected;
        @endphp

        @if ($this->works->count() > 0)
            {{-- Barra de filtro e ações em massa --}}
            <div class="d-flex flex-column flex-md-row gap-2 mb-3 align-items-md-center">
                <div class="flex-grow-1">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent">
                            <i class="bx bx-search text-muted"></i>
                        </span>
                        <input
                            type="text"
                            class="form-control"
                            placeholder="Buscar por nome da obra, cliente ou localização..."
                            wire:model.live.debounce.300ms="search"
                        >
                        @if ($search)
                            <button class="btn btn-outline-secondary" wire:click="clearSearch">
                                <i class="bx bx-x"></i> Limpar
                            </button>
                        @endif
                    </div>
                </div>

                @if (!empty($selectedIds))
                    <div class="flex-shrink-0">
                        <button class="btn btn-danger" wire:click="openBulkDeleteModal">
                            <i class="bx bx-trash me-1"></i>Excluir Selecionadas ({{ count($selectedIds) }})
                        </button>
                    </div>
                @endif
            </div>

            <div class="card table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="card-header bg-dark">
                        <tr>
                            <th class="text-white text-center ps-3" style="width: 44px;">
                                <input
                                    type="checkbox"
                                    class="form-check-input js-select-all"
                                    wire:click="toggleSelectAll"
                                    {{ $allOnPageSelected ? 'checked' : '' }}
                                    data-indeterminate="{{ $someOnPageSelected ? 'true' : 'false' }}"
                                >
                            </th>
                            <th class="text-white">Obra</th>
                            <th class="text-white">Cliente</th>
                            <th class="text-white">Localização</th>
                            <th class="text-white">Período</th>
                            <th class="text-white text-center">Status</th>
                            <th class="text-white text-center" style="width: 110px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="card-body">
                        @foreach ($this->works as $work)
                            <tr class="{{ in_array($work->id, $selectedIds) ? 'table-primary' : '' }}">
                                <td class="text-center ps-3" style="width: 44px;">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        wire:model.live="selectedIds"
                                        value="{{ $work->id }}"
                                    >
                                </td>
                                <td>
                                    <span class="fw-semibold">{{ $work->name }}</span>
                                    @if ($work->budget_total)
                                        <br><small class="text-muted">R$ {{ number_format($work->budget_total, 2, ',', '.') }}</small>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-truncate">{{ $work->client?->name ?? '—' }}</span>
                                </td>
                                <td>
                                    <span class="text-muted text-truncate">{{ $work->location ?? '—' }}</span>
                                </td>
                                <td>
                                    @if ($work->start_date_baseline || $work->end_date_baseline)
                                        <small>
                                            {{ $work->start_date_baseline?->format('d/m/Y') ?? '?' }}
                                            →
                                            {{ $work->end_date_baseline?->format('d/m/Y') ?? '?' }}
                                        </small>
                                    @else
                                        <small class="text-muted">—</small>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge {{ $work->status_badge }}">
                                        {{ match($work->status) {
                                            'planejamento' => 'Planejamento',
                                            'em_andamento' => 'Em Andamento',
                                            'paralisada'   => 'Paralisada',
                                            'concluida'    => 'Concluída',
                                            default        => $work->status,
                                        } }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex gap-1">
                                        @can('update', $work)
                                        <button
                                            class="btn btn-sm btn-icon btn-outline-primary me-2"
                                            title="Editar"
                                            wire:click="editWork('{{ $work->id }}')"
                                        >
                                            <i class="bx bx-edit"></i>
                                        </button>
                                        @endcan
                                        @can('delete', $work)
                                        <button
                                            class="btn btn-sm btn-icon btn-outline-danger"
                                            title="Excluir"
                                            wire:click="confirmDelete('{{ $work->id }}')"
                                        >
                                            <i class="bx bx-trash"></i>
                                        </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center mt-3">
                {{ $this->works->links() }}
            </div>


        @elseif ($search)
            <div class="card-body text-center py-5 my-4">
                <div class="mb-4" style="font-size: 3rem;">🔍</div>
                <h5 class="fw-bold text-heading mb-2">Nenhuma obra encontrada</h5>
                <p class="text-muted mb-4">Não há resultados para "<strong>{{ $search }}</strong>". Tente outros termos.</p>
                <button type="button" class="btn btn-outline-secondary shadow-none" wire:click="clearSearch">
                    <i class="bx bx-x me-1"></i>Limpar Filtro
                </button>
            </div>

        @else
            <div class="card-body text-center py-5 my-4">
                <div class="avatar avatar-xl mx-auto mb-4 bg-label-primary p-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                    <i class="bx bx-hard-hat display-4 text-primary"></i>
                </div>
                <h4 class="fw-bold text-heading mb-2">Nenhuma Obra Cadastrada</h4>
                <p class="text-muted mx-auto mb-4 px-3" style="max-width: 480px;">
                    Comece cadastrando a primeira obra da sua empresa. Cada obra é o ponto de partida para o planejamento e controle de restrições.
                </p>
                <button type="button" class="btn btn-primary shadow-none btn-lg px-4" data-bs-toggle="modal" data-bs-target="#obraModal">
                    <i class="bx bx-hard-hat me-2 fs-5"></i> Cadastrar Primeira Obra
                </button>
            </div>
        @endif
    </div>

    {{-- Modal: Confirmar exclusão individual --}}
    <div wire:ignore.self class="modal fade" id="workDeleteModal" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-semibold text-danger">
                        <i class="bx bx-error-circle me-2"></i>Confirmar Exclusão
                    </h5>
                    <button type="button" class="btn-close" wire:click="cancelDelete"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="mb-1">Você está prestes a excluir a obra:</p>
                    <p class="fw-bold fs-6 mb-3">{{ $confirmDeleteName }}</p>
                    <div class="alert alert-warning d-flex align-items-center mb-0">
                        <i class="bx bx-info-circle me-2 flex-shrink-0"></i>
                        <span>Esta ação é <strong>irreversível</strong>. Todos os dados da obra serão permanentemente removidos.</span>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" wire:click="cancelDelete">Cancelar</button>
                    <button type="button" class="btn btn-danger shadow-none" wire:click="deleteWork">
                        <span wire:loading.remove wire:target="deleteWork"><i class="bx bx-trash me-1"></i>Excluir Permanentemente</span>
                        <span wire:loading wire:target="deleteWork"><i class="bx bx-loader-alt bx-spin me-1"></i>Excluindo...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal: Confirmar exclusão em massa --}}
    <div wire:ignore.self class="modal fade" id="workBulkDeleteModal" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-semibold text-danger">
                        <i class="bx bx-error-circle me-2"></i>Confirmar Exclusão em Massa
                    </h5>
                    <button type="button" class="btn-close" wire:click="cancelBulkDelete"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="mb-3">Você está prestes a excluir <strong>{{ count($selectedIds) }} obra(s)</strong> selecionada(s).</p>
                    <div class="alert alert-warning d-flex align-items-center mb-0">
                        <i class="bx bx-info-circle me-2 flex-shrink-0"></i>
                        <span>Esta ação é <strong>irreversível</strong>.</span>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" wire:click="cancelBulkDelete">Cancelar</button>
                    <button type="button" class="btn btn-danger shadow-none" wire:click="bulkDeleteWorks">
                        <span wire:loading.remove wire:target="bulkDeleteWorks"><i class="bx bx-trash me-1"></i>Excluir {{ count($selectedIds) }} Obra(s)</span>
                        <span wire:loading wire:target="bulkDeleteWorks"><i class="bx bx-loader-alt bx-spin me-1"></i>Excluindo...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <livewire:obras.create/>
</div>

@script
<script>
    function syncSelectAllState() {
        document.querySelectorAll('.js-select-all').forEach(el => {
            el.indeterminate = el.dataset.indeterminate === 'true';
        });
    }
    document.addEventListener('livewire:updated', syncSelectAllState);
    syncSelectAllState();

    $wire.on('show-delete-modal', () => {
        const el = document.getElementById('workDeleteModal');
        if (el) bootstrap.Modal.getOrCreateInstance(el).show();
    });
    $wire.on('hide-delete-modal', () => {
        const el = document.getElementById('workDeleteModal');
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
    $wire.on('show-bulk-delete-modal', () => {
        const el = document.getElementById('workBulkDeleteModal');
        if (el) bootstrap.Modal.getOrCreateInstance(el).show();
    });
    $wire.on('hide-bulk-delete-modal', () => {
        const el = document.getElementById('workBulkDeleteModal');
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
    $wire.on('show-toast', ({ message }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            toastr.success(message);
        }
    });
</script>
@endscript
