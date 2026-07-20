<?php

use Livewire\Component;
use Livewire\Attributes\Validate;
use Livewire\WithFileUploads; // OBRIGATÓRIO PARA UPLOAD DE ARQUIVOS
use App\Models\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

new class extends Component
{
    use WithFileUploads; // Habilita o suporte a uploads no componente

    // Propriedades do Formulário (Data Binding)
    public $name;
    public $trading_name;
    public $cnpj;
    public $email;
    public $phone;
    public $logo; // Armazena o arquivo temporário durante o upload

    // Propriedades de Controle
    public $clientId;
    public $currentLogoPath; // Guarda o caminho da logo atual durante a edição
    public $isEditing = false;


    public $clients; // Lista de clientes para exibição

    // Regras de validação dinâmicas
    protected function rules()
    {
        return [
            'name' => 'required|min:3|max:255',
            'trading_name' => 'nullable|max:255',
            'cnpj' => 'nullable|max:18', // Suporta a máscara: 00.000.000/0000-00
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|max:15', // Suporta a máscara: (00) 00000-0000
            //'logo' => 'nullable|image|max:2048', // Imagem de no máximo 2MB
        ];
    }

    public function mount($name = null)
    {
        $this->clearForm();
    }

    // Salva um novo cliente ou atualiza um existente
    public function saveClient()
    {
        // 1. Valida os campos de texto
        $this->validate();

        // 2. Cria o registro amarrado ao Tenant logado (logo_path fica null por enquanto)
        Client::create([
            'tenant_id'    => Auth::user()->tenant_id,
            'name'         => $this->name,
            'trading_name' => $this->trading_name,
            'cnpj'         => $this->cnpj,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'logo_path'    => null, 
        ]);

        // 3. Mensagem de sucesso
        session()->flash('message', 'Cliente cadastrado com sucesso!');

        // 4. Limpa o formulário
        $this->clearForm();

        // 5. Dispara o evento para o Blade fechar o modal
        $this->dispatch('close-client-modal');
    }

    // Prepara o formulário para edição carregando os dados do banco
    public function editClient($id)
    {
        $client = Client::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);
        
        $this->clientId = $client->id;
        $this->name = $client->name;
        $this->trading_name = $client->trading_name;
        $this->cnpj = $client->cnpj;
        $this->email = $client->email;
        $this->phone = $client->phone;
        $this->currentLogoPath = $client->logo_path; // Guarda a referência do arquivo atual
        
        $this->isEditing = true;
    }

    // Exclui o cliente e limpa os ficheiros associados
    public function deleteClient($id)
    {
        $client = Client::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);
        
        // Remove a imagem do storage antes de apagar o registo do banco
        if ($client->logo_path) {
            Storage::delete($client->logo_path);
        }

        $client->delete();
        session()->flash('message', 'Cliente excluído com sucesso!');
    }

    // Reseta o formulário para o estado inicial limpo
    public function clearForm()
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
            'isEditing'
            ]);
        $this->formTittle = "Cadastrar Cliente";
    } 


};
?>

<div class="modal fade" id="backDropModal" data-bs-backdrop="static" tabindex="-1">
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
                    @if (session()->has('message'))
                        <div class="alert alert-solid-success d-flex align-items-center mb-3" role="alert">
                            <i class="bx bx-check-circle me-2"></i> {{ session('message') }}
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="name">Razão Social / Nome Completo *</label>
                            <input type="text" id="name" wire:model="name" class="form-control @error('name') is-invalid @enderror" placeholder="Ex: Incorporadora Alpha LTDA">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="trading_name">Nome Fantasia</label>
                            <input type="text" id="trading_name" wire:model="trading_name" class="form-control @error('trading_name') is-invalid @enderror" placeholder="Ex: Incorporadora Alpha">
                            @error('trading_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="cnpj">CNPJ</label>
                            <input type="text" id="cnpj" wire:model="cnpj" class="form-control @error('cnpj') is-invalid @enderror" placeholder="00.000.000/0000-00">
                            @error('cnpj') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="phone">Telefone</label>
                            <input type="text" id="phone" wire:model="phone" class="form-control @error('phone') is-invalid @enderror" placeholder="(00) 00000-0000">
                            @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-medium text-muted mb-1" for="email">E-mail de Contato</label>
                        <input type="email" id="email" wire:model="email" class="form-control @error('email') is-invalid @enderror" placeholder="engenharia@cliente.com">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
</div>
