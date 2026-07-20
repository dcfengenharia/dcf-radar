<?php

use App\Models\Tenant;
use App\Support\TenantSwitchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component {
  public string $name = '';

  protected function rules(): array
  {
    return [
      'name' => 'required|min:3|max:255',
    ];
  }

  public function criar(): void
  {
    $this->validate();

    $tenant = DB::transaction(function () {
      $tenant = Tenant::create([
        'name' => $this->name,
        'criado_por_id' => Auth::id(),
      ]);
      $tenant->usuariosComAcesso()->attach(Auth::id());

      return $tenant;
    });

    TenantSwitchContext::set($tenant);

    $this->reset('name');
    $this->dispatch('close-empresa-modal');
    $this->dispatch('show-toast', message: 'Empresa cadastrada com sucesso!');
    $this->dispatch('empresa-criada');
  }
};
?>

<div wire:ignore.self class="modal fade" id="empresaCreateModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-semibold">
                    <i class="bx bx-plus-circle me-2"></i>Cadastrar Nova Empresa
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form wire:submit.prevent="criar">
                <div class="modal-body pt-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium text-muted mb-1" for="empresaNome">Nome da Empresa <span class="text-danger">*</span></label>
                        <input type="text" id="empresaNome" wire:model="name"
                               class="form-control @error('name') is-invalid @enderror"
                               placeholder="Ex: Construtora Alpha LTDA">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <small class="text-muted">Você poderá completar logotipo, CNPJ e demais dados depois, em "Dados da Empresa".</small>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary shadow-none">
                        <span wire:loading.remove wire:target="criar">Cadastrar Empresa</span>
                        <span wire:loading wire:target="criar"><i class="bx bx-loader-alt bx-spin me-2"></i>Salvando...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@script
<script>
    $wire.on('close-empresa-modal', () => {
        const modalElement = document.getElementById('empresaCreateModal');
        if (modalElement) {
            const modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (modalInstance) {
                modalInstance.hide();
            }
        }
    });

    $wire.on('show-toast', ({ message }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            toastr.success(message);
        }
    });

    {{-- Redirect adiado de propósito: se fosse imediato (via $this->redirect no PHP),
         a navegação cortava a página antes do toastr conseguir aparecer na tela. --}}
    $wire.on('empresa-criada', () => {
        setTimeout(() => {
            window.location.href = @json(route('app.home'));
        }, 1200);
    });
</script>
@endscript
