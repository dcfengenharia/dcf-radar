<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use App\Models\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

new class extends Component {
  use WithFileUploads;

  public $name;
  public $trading_name;
  public $cnpj;
  public $email;
  public $phone;
  public $logo;

  public $clientId;
  public $currentLogoPath;
  public ?string $currentLogoUrl = null;
  public $isEditing = false;
  public string $formTitle = 'Cadastrar Cliente';

  public function mount(): void
  {
    $this->clearForm();
  }

  protected function rules(): array
  {
    return [
      'name' => 'required|min:3|max:255',
      'trading_name' => 'required|max:255',
      'cnpj' => 'nullable|max:18',
      'email' => 'nullable|email|max:255',
      'phone' => 'nullable|max:15',
      'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
    ];
  }

  #[On('edit-client')]
  public function editClient(string $id): void
  {
    $client = Client::findOrFail($id);

    $this->clientId = $client->id;
    $this->name = $client->name;
    $this->trading_name = $client->trading_name;
    $this->cnpj = $client->cnpj;
    $this->email = $client->email;
    $this->phone = $client->phone;
    $this->currentLogoPath = $client->logo_path;
    $this->currentLogoUrl = $client->logo_path ? Storage::disk('public')->url($client->logo_path) : null;
    $this->isEditing = true;
    $this->formTitle = 'Editar Cliente';

    $this->dispatch('open-client-modal');
  }

  public function saveClient(): void
  {
    $this->validate();

    if ($this->isEditing) {
      $this->updateClient();
    } else {
      $this->createClient();
    }
  }

  private function createClient(): void
  {
    $this->authorize('create', Client::class);

    $logoPath = null;
    if ($this->logo) {
      $logoPath = $this->logo->store('logos/clients', 'public');
    }

    Client::create([
      'tenant_id' => Auth::user()->tenant_id,
      'name' => $this->name,
      'trading_name' => $this->trading_name,
      'cnpj' => $this->cnpj,
      'email' => $this->email,
      'phone' => $this->phone,
      'logo_path' => $logoPath,
    ]);

    $this->clearForm();
    $this->dispatch('close-client-modal');
    $this->dispatch('client-created');
    $this->dispatch('show-client-toast', message: 'Cliente cadastrado com sucesso!');
  }

  private function updateClient(): void
  {
    $client = Client::findOrFail($this->clientId);
    $this->authorize('update', $client);

    $logoPath = $client->logo_path;
    if ($this->logo) {
      if ($logoPath) {
        Storage::disk('public')->delete($logoPath);
      }
      $logoPath = $this->logo->store('logos/clients', 'public');
    }

    $client->update([
      'name' => $this->name,
      'trading_name' => $this->trading_name,
      'cnpj' => $this->cnpj,
      'email' => $this->email,
      'phone' => $this->phone,
      'logo_path' => $logoPath,
    ]);

    $this->clearForm();
    $this->dispatch('close-client-modal');
    $this->dispatch('client-updated');
    $this->dispatch('show-client-toast', message: 'Cliente atualizado com sucesso!');
  }

  public function clearForm(): void
  {
    $this->reset([
      'name',
      'trading_name',
      'cnpj',
      'email',
      'phone',
      'logo',
      'clientId',
      'currentLogoPath',
      'currentLogoUrl',
      'isEditing',
    ]);
    $this->formTitle = 'Cadastrar Cliente';
  }
};
?>

<div wire:ignore.self class="modal fade" id="backDropModal" name="cliente-modal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-semibold" id="clientModalLabel">
                    <i class="bx {{ $isEditing ? 'bx-edit' : 'bx-user-plus' }} me-2"></i>{{ $isEditing ? 'Editar Cliente' : 'Cadastrar Novo Cliente' }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="clearForm"></button>
            </div>
            <form wire:submit.prevent="saveClient" enctype="multipart/form-data">

                <div class="modal-body pt-4">
                    @if ($errors->any())
                        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                            <i class="bx bx-error-circle me-2"></i> Corrija os campos destacados abaixo antes de continuar.
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="name">Razão Social / Nome Completo <span class="text-danger">*</span></label>
                            <input type="text" id="name" wire:model="name" class="form-control @error('name') is-invalid @enderror" placeholder="Ex: Incorporadora Alpha LTDA">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="trading_name">Nome Fantasia <span class="text-danger">*</span></label>
                            <input type="text" id="trading_name" wire:model="trading_name" class="form-control @error('trading_name') is-invalid @enderror" placeholder="Ex: Incorporadora Alpha">
                            @error('trading_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="row">
                      <div class="col-md-6 mb-3">
                        <div class="row">
                          <div class="col-md-6 mb-3">
                              <label class="form-label fw-medium text-muted mb-1" for="cnpj">CNPJ</label>
                              <input
                                  type="text"
                                  id="cnpj"
                                  wire:model="cnpj"
                                  x-init="new Cleave($el, { blocks: [2,3,3,4,2], delimiters: ['.', '.', '/', '-'], numericOnly: true })"
                                  class="form-control @error('cnpj') is-invalid @enderror"
                                  placeholder="00.000.000/0000-00"
                                  maxlength="18"
                              >
                              @error('cnpj') <div class="invalid-feedback">{{ $message }}</div> @enderror
                          </div>
                          <div class="col-md-6 mb-3">
                              <label class="form-label fw-medium text-muted mb-1" for="phone">Telefone</label>
                              <input
                                  type="text"
                                  id="phone"
                                  wire:model="phone"
                                  x-on:input="
                                      const d = $event.target.value.replace(/\D/g, '').slice(0, 11);
                                      if (!d.length) { $event.target.value = ''; return; }
                                      let v = '(' + d.slice(0, 2);
                                      if (d.length >= 2) v += ') ' + d.slice(2, d.length > 10 ? 7 : 6);
                                      if (d.length > 6 && d.length <= 10) v += '-' + d.slice(6);
                                      else if (d.length > 7) v += '-' + d.slice(7);
                                      $event.target.value = v;
                                  "
                                  x-on:keydown="
                                      if ($event.key === 'Backspace' && $event.target.value.length && !/\d$/.test($event.target.value)) {
                                          $event.preventDefault();
                                          $event.target.value = $event.target.value.replace(/\D/g, '').slice(0, -1);
                                          $event.target.dispatchEvent(new Event('input'));
                                      }
                                  "
                                  class="form-control @error('phone') is-invalid @enderror"
                                  placeholder="(00) 00000-0000"
                                  maxlength="15"
                              >
                              @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                          </div>
                          <div class="col-md-12 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="email">E-mail de Contato</label>
                            <input type="email" id="email" wire:model="email" class="form-control @error('email') is-invalid @enderror" placeholder="engenharia@cliente.com">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                          </div>
                        </div>
                      </div>
                      <div class="col-md-6 mb-3">
                        <div class="row">
                          <div class="col-md-12 mb-3">
                            <label class="form-label fw-medium text-muted mb-1">
                                Logo do Cliente <span class="text-muted fw-normal small">(opcional — PNG, JPG, máx. 2 MB)</span>
                            </label>

                            <div
                                x-data="{
                                    preview: null,
                                    isDragging: false,
                                    uploading: false,
                                    progress: 0,
                                    init() {
                                        if ($wire.currentLogoUrl) {
                                            this.preview = $wire.currentLogoUrl;
                                        }
                                        $wire.$watch('currentLogoUrl', (val) => {
                                            this.preview = val || null;
                                        });
                                        $wire.$watch('logo', (val) => {
                                            if (!val) {
                                                this.preview = $wire.currentLogoUrl || null;
                                                this.uploading = false;
                                                this.progress = 0;
                                            }
                                        });
                                    },
                                    setPreview(file) {
                                        const reader = new FileReader();
                                        reader.onload = (e) => { this.preview = e.target.result; };
                                        reader.readAsDataURL(file);
                                    },
                                    handleDrop(e) {
                                        this.isDragging = false;
                                        const file = e.dataTransfer?.files[0];
                                        if (!file || !file.type.startsWith('image/')) return;
                                        this.setPreview(file);
                                        $wire.upload('logo', file, () => {}, () => { this.uploading = false; }, (ev) => { this.progress = ev.detail.progress; });
                                    }
                                }"
                                x-on:livewire-upload-start="uploading = true; progress = 0"
                                x-on:livewire-upload-finish="uploading = false"
                                x-on:livewire-upload-error="uploading = false"
                                x-on:dragover.prevent="isDragging = true"
                                x-on:dragleave.prevent="isDragging = false"
                                x-on:drop.prevent="handleDrop($event)"
                            >
                                <div
                                    class="rounded p-3 text-center position-relative"
                                    style="border: 2px dashed; cursor: pointer; min-height: 110px; display: flex; align-items: center; justify-content: center;"
                                    :class="isDragging ? 'border-primary bg-primary bg-opacity-10' : 'border-secondary'"
                                    x-on:click="$refs.logoInput.click()"
                                >
                                    <template x-if="preview">
                                        <div x-on:click.stop>
                                            <img :src="preview" class="rounded mb-2" style="max-height: 80px; max-width: 180px; object-fit: contain;">
                                            <div>
                                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                                                    x-on:click="preview = null; $wire.set('logo', null); $wire.set('currentLogoUrl', null);">
                                                    <i class="bx bx-trash me-1"></i>Remover
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="!preview && !uploading">
                                        <div>
                                            <i class="bx bx-image-add fs-2 text-muted d-block mb-1"></i>
                                            <p class="mb-0 small">Arraste e solte ou <span class="text-primary fw-medium">clique para buscar</span></p>
                                        </div>
                                    </template>

                                    <template x-if="uploading">
                                        <div class="w-100 px-2">
                                            <p class="text-muted small mb-2">Enviando imagem...</p>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated"
                                                     role="progressbar"
                                                     :style="`width: ${progress}%`"></div>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <input
                                    type="file"
                                    wire:model="logo"
                                    x-ref="logoInput"
                                    accept="image/jpeg,image/png,image/gif,image/webp"
                                    class="d-none"
                                    x-on:change="
                                        const file = $event.target.files[0];
                                        if (file) { setPreview(file); }
                                    "
                                >

                                @error('logo') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="row">
                      <div class="col-12">
                        <small class="text-muted">
                          <span>(<span class="text-danger">*</span>) Campos de preenchimento obrigatório!</span>
                        </small>
                      </div>
                    </div>
                </div>

                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" data-bs-dismiss="modal" wire:click="clearForm">Cancelar</button>
                    <button type="submit" class="btn btn-primary shadow-none">
                        <span wire:loading.remove wire:target="saveClient">
                            {{ $isEditing ? 'Salvar Alterações' : 'Cadastrar Cliente' }}
                        </span>
                        <span wire:loading wire:target="saveClient">
                            <i class="bx bx-loader-alt bx-spin me-2"></i>Salvando...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    window.addEventListener('close-client-modal', event => {
        $('#backDropModal').modal('hide');
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open');
        $('body').css('padding-right', '');
    });
    </script>
</div>

@script
<script>
    $wire.on('close-client-modal', () => {
       const modalElement = document.getElementById('backDropModal');
       if (modalElement) {
           const modalInstance = bootstrap.Modal.getInstance(modalElement);
           if (modalInstance) {
               modalInstance.hide();
           } else {
               $('#backDropModal').modal('hide');
           }

           setTimeout(() => {
               document.body.classList.remove('modal-open');
               document.body.style.overflow = '';
               document.body.style.paddingRight = '';
               document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
           }, 150);
       }
    });

    $wire.on('open-client-modal', () => {
        const modalElement = document.getElementById('backDropModal');
        if (modalElement) {
            const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
            modalInstance.show();
        }
    });

    $wire.on('show-client-toast', ({ message }) => {
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
