<?php

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
  use WithFileUploads;

  public Tenant $tenant;

  public string $name = '';
  public ?string $cnpj = null;
  public ?string $razao_social = null;
  public ?string $telefone = null;
  public ?string $email_comercial = null;
  public $logo = null;
  public ?string $currentLogoUrl = null;

  public function mount(Tenant $tenant): void
  {
    abort_unless(Auth::user()->podeGerenciarTenant($tenant), 403);

    $this->tenant = $tenant;
    $this->name = $tenant->name;
    $this->cnpj = $tenant->cnpj;
    $this->razao_social = $tenant->razao_social;
    $this->telefone = $tenant->telefone;
    $this->email_comercial = $tenant->email_comercial;
    $this->currentLogoUrl = $tenant->logo_path ? Storage::disk('public')->url($tenant->logo_path) : null;
  }

  protected function rules(): array
  {
    return [
      'name' => 'required|min:3|max:255',
      'cnpj' => 'nullable|max:18',
      'razao_social' => 'nullable|string|max:255',
      'telefone' => 'nullable|max:15',
      'email_comercial' => 'nullable|email|max:255',
      'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
    ];
  }

  #[Computed]
  public function outrasEmpresas(): \Illuminate\Support\Collection
  {
    return Auth::user()->tenants()->orderBy('name')->get();
  }

  public function salvar(): void
  {
    $this->validate();

    abort_unless(Auth::user()->podeGerenciarTenant($this->tenant), 403);

    $logoPath = $this->tenant->logo_path;
    if ($this->logo) {
      if ($logoPath) {
        Storage::disk('public')->delete($logoPath);
      }
      $logoPath = $this->logo->store('logos/tenants', 'public');
    }

    $this->tenant->update([
      'name' => $this->name,
      'cnpj' => $this->cnpj,
      'razao_social' => $this->razao_social,
      'telefone' => $this->telefone,
      'email_comercial' => $this->email_comercial,
      'logo_path' => $logoPath,
    ]);

    $this->logo = null;
    $this->currentLogoUrl = $logoPath ? Storage::disk('public')->url($logoPath) : null;
    $this->dispatch('show-toast', message: 'Dados da empresa atualizados com sucesso!');
  }
};
?>

<div>
    <div class="row g-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Dados da Empresa</h5>
                </div>
                <div class="card-body">
                    <form wire:submit.prevent="salvar" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label fw-medium text-muted mb-1">Logotipo <span class="text-muted fw-normal small">(opcional — PNG, JPG, máx. 2 MB)</span></label>
                            <div class="d-flex align-items-center gap-3">
                                @if ($currentLogoUrl)
                                    <img src="{{ $currentLogoUrl }}" alt="Logo" style="max-height: 60px; max-width: 160px; object-fit: contain;">
                                @endif
                                <input type="file" wire:model="logo" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control @error('logo') is-invalid @enderror">
                            </div>
                            @error('logo') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="empresaNome">Nome <span class="text-danger">*</span></label>
                            <input type="text" id="empresaNome" wire:model="name" class="form-control @error('name') is-invalid @enderror">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium text-muted mb-1" for="cnpj">CNPJ</label>
                                <input type="text" id="cnpj" wire:model="cnpj" maxlength="18" class="form-control @error('cnpj') is-invalid @enderror" placeholder="00.000.000/0000-00">
                                @error('cnpj') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium text-muted mb-1" for="telefone">Telefone</label>
                                <input type="text" id="telefone" wire:model="telefone" maxlength="15" class="form-control @error('telefone') is-invalid @enderror" placeholder="(00) 00000-0000">
                                @error('telefone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="razaoSocial">Razão Social</label>
                            <input type="text" id="razaoSocial" wire:model="razao_social" class="form-control @error('razao_social') is-invalid @enderror">
                            @error('razao_social') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="emailComercial">E-mail Comercial</label>
                            <input type="email" id="emailComercial" wire:model="email_comercial" class="form-control @error('email_comercial') is-invalid @enderror">
                            @error('email_comercial') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <span wire:loading.remove wire:target="salvar">Salvar</span>
                            <span wire:loading wire:target="salvar"><i class="bx bx-loader-alt bx-spin me-2"></i>Salvando...</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Plano e Cobrança</h5>
                </div>
                <div class="card-body">
                    @php $assinaturaAtual = $tenant->assinaturaAtual(); @endphp
                    @if ($assinaturaAtual)
                        <p class="mb-1">
                            {{ $assinaturaAtual->plano->nome }}
                            <span class="badge bg-label-{{ $assinaturaAtual->status->corBadge() }} ms-1">{{ $assinaturaAtual->status->label() }}</span>
                        </p>
                    @else
                        <p class="text-muted mb-1">Nenhuma assinatura ainda.</p>
                    @endif
                    <a href="{{ route('app.empresa.assinatura') }}" class="btn btn-sm btn-outline-primary">Gerenciar assinatura</a>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Outras Empresas</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#empresaCreateModal">
                        <i class="bx bx-plus"></i>
                    </button>
                </div>
                <ul class="list-group list-group-flush">
                    @foreach ($this->outrasEmpresas as $t)
                        @php $ativo = $t->id === \App\Support\TenantContext::currentId(); @endphp
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                {{ $t->name }}
                                @if ($t->criado_por_id === Auth::id())
                                    <br><span class="badge bg-label-secondary x-small">Criada por você</span>
                                @endif
                            </div>
                            @if ($ativo)
                                <span class="badge bg-label-primary rounded-pill">Atual</span>
                            @else
                                <form method="POST" action="{{ route('app.empresa.trocar', $t) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-outline-secondary py-0 px-2">Trocar</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
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
