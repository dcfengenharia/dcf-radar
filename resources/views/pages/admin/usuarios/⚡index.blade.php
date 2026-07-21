<?php

use App\Models\Tenant;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
  use WithPagination;
  protected $paginationTheme = 'bootstrap';

  public string $search = '';
  public string $filtroTenant = '';
  public string $filtroStatus = '';

  #[Computed]
  public function tenantsDisponiveis(): \Illuminate\Support\Collection
  {
    return Tenant::orderBy('name')->get();
  }

  #[Computed]
  public function usuarios()
  {
    return User::with('tenant')
      ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
        $q->where('first_name', 'like', "%{$this->search}%")
          ->orWhere('last_name', 'like', "%{$this->search}%")
          ->orWhere('email', 'like', "%{$this->search}%");
      }))
      ->when($this->filtroTenant !== '', fn ($q) => $q->where('tenant_id', $this->filtroTenant))
      ->when($this->filtroStatus === 'ativo', fn ($q) => $q->where('ativo', true))
      ->when($this->filtroStatus === 'inativo', fn ($q) => $q->where('ativo', false))
      ->orderBy('first_name')
      ->paginate(15);
  }

  public function updatedSearch(): void
  {
    $this->resetPage();
  }

  public function updatedFiltroTenant(): void
  {
    $this->resetPage();
  }

  public function updatedFiltroStatus(): void
  {
    $this->resetPage();
  }

  public function alternarStatus(string $userId): void
  {
    if ($userId === auth()->id()) {
      $this->dispatch('show-toast', message: 'Você não pode desativar sua própria conta.');
      return;
    }

    $usuario = User::findOrFail($userId);
    $usuario->update(['ativo' => ! $usuario->ativo]);

    $mensagem = $usuario->ativo
      ? 'Usuário reativado.'
      : 'Usuário desativado — perde acesso à plataforma no próximo acesso.';

    $this->dispatch('show-toast', message: $mensagem);
  }
};
?>

<div>
    <div class="row mb-3 g-2">
        <div class="col-md-5">
            <input type="text" class="form-control" wire:model.live.debounce.400ms="search"
                   placeholder="Buscar por nome ou e-mail...">
        </div>
        <div class="col-md-4">
            <select class="form-select" wire:model.live="filtroTenant">
                <option value="">Todas as contas</option>
                @foreach ($this->tenantsDisponiveis as $tenant)
                    <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" wire:model.live="filtroStatus">
                <option value="">Todos os status</option>
                <option value="ativo">Ativo</option>
                <option value="inativo">Inativo</option>
            </select>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>E-mail</th>
                        <th>Empresa</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->usuarios as $usuario)
                        <tr>
                            <td>
                                {{ $usuario->first_name }} {{ $usuario->last_name }}
                                @if ($usuario->is_platform_admin)
                                    <span class="badge bg-label-primary ms-1">Admin da Plataforma</span>
                                @endif
                            </td>
                            <td>{{ $usuario->email }}</td>
                            <td>
                                {{ $usuario->tenant?->name ?? '—' }}
                                @if ($usuario->tenant?->eh_conta_operadora)
                                    <span class="badge bg-label-dark ms-1">Conta Operadora</span>
                                @endif
                            </td>
                            <td>
                                @if ($usuario->ativo)
                                    <span class="badge bg-label-success">Ativo</span>
                                @else
                                    <span class="badge bg-label-secondary">Inativo</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($usuario->id !== auth()->id())
                                    <button type="button"
                                            class="btn btn-xs btn-outline-{{ $usuario->ativo ? 'danger' : 'success' }} py-0 px-2"
                                            wire:click="alternarStatus('{{ $usuario->id }}')"
                                            wire:confirm="{{ $usuario->ativo ? 'Desativar este usuário? Ele perde acesso à plataforma no próximo acesso.' : 'Reativar este usuário?' }}">
                                        <i class="bx {{ $usuario->ativo ? 'bx-block' : 'bx-check' }}"></i>
                                        {{ $usuario->ativo ? 'Desativar' : 'Reativar' }}
                                    </button>
                                @else
                                    <span class="text-muted small">Sua conta</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">Nenhum usuário encontrado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->usuarios->hasPages())
            <div class="card-body">
                {{ $this->usuarios->links() }}
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
