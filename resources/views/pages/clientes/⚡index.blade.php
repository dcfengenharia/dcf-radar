<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

new class extends Component {
  use WithPagination;
  protected $paginationTheme = 'bootstrap';

  public $tenant;
  public string $search = '';
  public array $selectedIds = [];
  public ?string $confirmDeleteId = null;
  public ?string $confirmDeleteName = null;

  public function mount(): void
  {
    $this->tenant = Tenant::find(\App\Support\TenantContext::currentId());
  }

  #[Computed]
  public function clients()
  {
    return Client::query()
      ->withCount('works')
      ->when($this->search, function ($query) {
        $query->where(function ($q) {
          $q->where('name', 'like', "%{$this->search}%")
            ->orWhere('trading_name', 'like', "%{$this->search}%")
            ->orWhere('cnpj', 'like', "%{$this->search}%")
            ->orWhere('email', 'like', "%{$this->search}%")
            ->orWhere('phone', 'like', "%{$this->search}%");
        });
      })
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

  #[On('client-created')]
  public function onClientCreated(): void
  {
    $this->resetPage();
    $this->selectedIds = [];
  }

  #[On('client-updated')]
  public function onClientUpdated(): void
  {
  }

  public function editClient(string $id): void
  {
    $this->dispatch('edit-client', id: $id);
  }

  public function toggleSelectAll(): void
  {
    $pageIds = $this->clients->pluck('id')->toArray();
    $allSelected = !empty($pageIds) && collect($pageIds)->every(fn($id) => in_array($id, $this->selectedIds));

    if ($allSelected) {
      $this->selectedIds = array_values(array_diff($this->selectedIds, $pageIds));
    } else {
      $this->selectedIds = array_values(array_unique(array_merge($this->selectedIds, $pageIds)));
    }
  }

  public function openBulkDeleteModal(): void
  {
    if (empty($this->selectedIds)) {
      return;
    }
    $this->dispatch('show-bulk-delete-modal');
  }

  public function bulkDeleteClients(): void
  {
    $clients = Client::whereIn('id', $this->selectedIds)->get();

    foreach ($clients as $client) {
      $this->authorize('delete', $client);
    }

    foreach ($clients as $client) {
      if ($client->logo_path) {
        Storage::disk('public')->delete($client->logo_path);
      }
      $client->delete();
    }

    $count = $clients->count();
    $this->selectedIds = [];
    $this->dispatch('hide-bulk-delete-modal');
    $this->dispatch('show-toast', message: "{$count} cliente(s) excluído(s) com sucesso!");
  }

  public function cancelBulkDelete(): void
  {
    $this->dispatch('hide-bulk-delete-modal');
  }

  public function confirmDelete(string $id): void
  {
    $client = Client::findOrFail($id);
    $this->confirmDeleteId = $client->id;
    $this->confirmDeleteName = $client->name;
    $this->dispatch('show-delete-modal');
  }

  public function deleteClient(): void
  {
    if (!$this->confirmDeleteId) {
      return;
    }

    $client = Client::findOrFail($this->confirmDeleteId);
    $this->authorize('delete', $client);

    if ($client->logo_path) {
      Storage::disk('public')->delete($client->logo_path);
    }

    $client->delete();

    $this->selectedIds = array_values(array_diff($this->selectedIds, [$this->confirmDeleteId]));
    $this->confirmDeleteId = null;
    $this->confirmDeleteName = null;
    $this->dispatch('hide-delete-modal');
    $this->dispatch('show-toast', message: 'Cliente excluído com sucesso!');
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
    <!-- Cabeçalho e botão Novo Cliente -->
    <div class="row">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
                <div class="d-flex flex-column justify-content-center">
                    <h4 class="mb-1 mt-3">Lista de Clientes do(a) <mark>{{ $tenant->name }}</mark></h4>
                    <p class="text-muted">Abaixo estão todos os Clientes da sua empresa</p>
                </div>
                <div class="d-flex align-content-center flex-wrap gap-2">
                    @can('create', \App\Models\Client::class)
                    <div class="px-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#backDropModal">
                            <i class="bx bx-user-plus me-2 fs-5"></i>Novo Cliente
                        </button>
                    </div>
                    @endcan
                </div>
            </div>
        </div>
    </div>



    <div>
        @php
            $pageIds = $this->clients->pluck('id')->toArray();
            $selectedOnPage = collect($pageIds)->filter(fn($id) => in_array($id, $selectedIds))->count();
            $allOnPageSelected = count($pageIds) > 0 && $selectedOnPage === count($pageIds);
            $someOnPageSelected = $selectedOnPage > 0 && ! $allOnPageSelected;
        @endphp

        @if ($this->clients->count() > 0)
            <!-- Barra de filtro e ações em massa -->
            <div class="d-flex flex-column flex-md-row gap-2 mb-3 align-items-md-center">
                <div class="flex-grow-1">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent">
                            <i class="bx bx-search text-muted"></i>
                        </span>
                        <input
                            type="text"
                            class="form-control"
                            placeholder="Buscar por nome, CNPJ, e-mail ou telefone..."
                            wire:model.live.debounce.300ms="search"
                        >
                        @if ($search)
                            <button
                                class="btn btn-outline-secondary"
                                title="Limpar filtro"
                                wire:click="clearSearch"
                            >
                                <i class="bx bx-x"></i> Limpar
                            </button>
                        @endif
                    </div>
                </div>

                @if (!empty($selectedIds) && Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.clientes', 'excluir'))
                    <div class="flex-shrink-0">
                        <button class="btn btn-danger" wire:click="openBulkDeleteModal">
                            <i class="bx bx-trash me-1"></i>Excluir Selecionados ({{ count($selectedIds) }})
                        </button>
                    </div>
                @endif
            </div>

            <div class="card table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="card-header bg-dark">
                        <tr>
                            <th class="text-white text-center ps-3" style="width: 44px;">
                                <div class="form-check mb-0 d-flex justify-content-center">
                                    <input
                                        type="checkbox"
                                        class="form-check-input js-select-all"
                                        wire:click="toggleSelectAll"
                                        {{ $allOnPageSelected ? 'checked' : '' }}
                                        data-indeterminate="{{ $someOnPageSelected ? 'true' : 'false' }}"
                                    >
                                </div>
                            </th>
                            <th class="text-white text-center" style="width: 90px;">Logo</th>
                            <th class="text-white">Cliente</th>
                            <th class="text-white">CNPJ</th>
                            <th class="text-white">Contato</th>
                            <th class="text-white text-center">Obras</th>
                            <th class="text-white text-center" style="width: 110px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="card-body">
                        @foreach ($this->clients as $client)
                            <tr style="height: 80px;" class="{{ in_array($client->id, $selectedIds) ? 'table-primary' : '' }}">
                                <td class="text-center ps-3" style="width: 44px;">
                                    <div class="form-check mb-0 d-flex justify-content-center">
                                        <input
                                            type="checkbox"
                                            class="form-check-input"
                                            wire:model.live="selectedIds"
                                            value="{{ $client->id }}"
                                        >
                                    </div>
                                </td>
                                <td class="text-center" style="width: 90px;">
                                    <img
                                        src="{{ $client->logo_url }}"
                                        style="width: 56px; height: 56px; object-fit: contain; border-radius: 6px;"
                                        alt="Logo {{ $client->name }}"
                                    />
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <h6 class="mb-0">{{ $client->name }}</h6>
                                        <small class="text-muted">{{ $client->trading_name ?? 'Sem Nome Fantasia' }}</small>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-truncate">{{ $client->cnpj ?? 'Não informado' }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="text-truncate"><i class="bx bx-envelope bx-xs me-1"></i>{{ $client->email ?? 'N/A' }}</span>
                                        <small class="text-muted"><i class="bx bx-phone bx-xs me-1"></i>{{ $client->phone ?? 'N/A' }}</small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-label-warning">
                                      <h5 class="mb-0">{{ $client->works_count }}</h5>
                                    </span>
                                </td>
                                <td class="text-center" style="width: 110px;">
                                    <div class="d-inline-flex gap-1">
                                        @can('update', $client)
                                        <button
                                            class="btn btn-sm btn-icon btn-outline-primary me-2"
                                            title="Editar"
                                            wire:click="editClient('{{ $client->id }}')"
                                        >
                                            <i class="bx bx-edit"></i>
                                        </button>
                                        @endcan
                                        @can('delete', $client)
                                        <button
                                            class="btn btn-sm btn-icon btn-outline-danger"
                                            title="Excluir"
                                            wire:click="confirmDelete('{{ $client->id }}')"
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
                {{ $this->clients->links() }}
            </div>

        @elseif ($search)
            <div class="card-body text-center py-5 my-4">
                <div class="mb-4" style="font-size: 3rem;">🔍</div>
                <h5 class="fw-bold text-heading mb-2">Nenhum cliente encontrado</h5>
                <p class="text-muted mb-4">Não há resultados para "<strong>{{ $search }}</strong>". Tente outros termos.</p>
                <button type="button" class="btn btn-outline-secondary shadow-none" wire:click="clearSearch">
                    <i class="bx bx-x me-1"></i>Limpar Filtro
                </button>
            </div>
        @else
            <div class="card-body text-center py-5 my-4">
                <div class="avatar avatar-xl mx-auto mb-4 bg-label-warning p-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                    <i class="bx bx-hard-hat display-4 animate__animated animate__pulse animate__infinite text-warning"></i>
                </div>

                <h4 class="fw-bold text-heading mb-2">Canteiro de Obras Vazio por Aqui!</h4>

                <p class="text-muted mx-auto mb-4 px-3" style="max-width: 480px;">
                    Parece que a sua carteira de clientes está mais limpa do que terreno antes da terraplanagem. Sem clientes, sem projetos, sem medições... e o mais preocupante: <span class="text-danger fw-semibold">sem faturamento</span>. Vamos mudar isso?
                </p>

                <button type="button" class="btn btn-primary shadow-none btn-lg px-4" data-bs-toggle="modal" data-bs-target="#backDropModal">
                    <i class="bx bx-user-plus me-2 fs-5"></i> Trazer o Primeiro Cliente para o Jogo
                </button>
            </div>
        @endif
    </div>

    <!-- Modal de Confirmação de Exclusão Individual -->
    <div wire:ignore.self class="modal fade" id="deleteConfirmModal" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-semibold text-danger">
                        <i class="bx bx-error-circle me-2"></i>Confirmar Exclusão
                    </h5>
                    <button type="button" class="btn-close" wire:click="cancelDelete"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="mb-1">Você está prestes a excluir o cliente:</p>
                    <p class="fw-bold fs-6 mb-3">{{ $confirmDeleteName }}</p>
                    <div class="alert alert-warning d-flex align-items-center mb-0" role="alert">
                        <i class="bx bx-info-circle me-2 flex-shrink-0"></i>
                        <span>Esta ação é <strong>irreversível</strong>. Todos os dados do cliente serão permanentemente removidos.</span>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" wire:click="cancelDelete">
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-danger shadow-none" wire:click="deleteClient">
                        <span wire:loading.remove wire:target="deleteClient">
                            <i class="bx bx-trash me-1"></i>Excluir Permanentemente
                        </span>
                        <span wire:loading wire:target="deleteClient">
                            <i class="bx bx-loader-alt bx-spin me-1"></i>Excluindo...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmação de Exclusão em Massa -->
    <div wire:ignore.self class="modal fade" id="bulkDeleteModal" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-semibold text-danger">
                        <i class="bx bx-error-circle me-2"></i>Confirmar Exclusão em Massa
                    </h5>
                    <button type="button" class="btn-close" wire:click="cancelBulkDelete"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="mb-3">
                        Você está prestes a excluir <strong>{{ count($selectedIds) }} cliente(s)</strong> selecionado(s).
                    </p>
                    <div class="alert alert-warning d-flex align-items-center mb-0" role="alert">
                        <i class="bx bx-info-circle me-2 flex-shrink-0"></i>
                        <span>Esta ação é <strong>irreversível</strong>. Todos os dados e logos dos clientes selecionados serão permanentemente removidos.</span>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" wire:click="cancelBulkDelete">
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-danger shadow-none" wire:click="bulkDeleteClients">
                        <span wire:loading.remove wire:target="bulkDeleteClients">
                            <i class="bx bx-trash me-1"></i>Excluir {{ count($selectedIds) }} Cliente(s)
                        </span>
                        <span wire:loading wire:target="bulkDeleteClients">
                            <i class="bx bx-loader-alt bx-spin me-1"></i>Excluindo...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <livewire:clientes.create/>
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
        const el = document.getElementById('deleteConfirmModal');
        if (el) bootstrap.Modal.getOrCreateInstance(el).show();
    });

    $wire.on('hide-delete-modal', () => {
        const el = document.getElementById('deleteConfirmModal');
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
        const el = document.getElementById('bulkDeleteModal');
        if (el) bootstrap.Modal.getOrCreateInstance(el).show();
    });

    $wire.on('hide-bulk-delete-modal', () => {
        const el = document.getElementById('bulkDeleteModal');
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
        toastr.options = {
            positionClass: 'toast-top-right',
            timeOut: 4000,
            closeButton: true,
            progressBar: true,
        };
        toastr.success(message);
    });
</script>
@endscript
