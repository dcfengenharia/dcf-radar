<?php

use App\Models\Fornecedor;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
  use ExecutaComTransacaoSegura;

  public ?string $obraId = null;
  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $nome = '';
  public string $cnpj = '';
  public string $contatoNome = '';
  public string $contatoEmail = '';
  public string $contatoTelefone = '';
  public string $observacoes = '';

  #[Computed]
  public function obras(): \Illuminate\Support\Collection
  {
    return Work::orderBy('name')->get(['id', 'name']);
  }

  #[Computed]
  public function fornecedores(): \Illuminate\Support\Collection
  {
    if (!$this->obraId) {
      return collect();
    }

    return Fornecedor::where('obra_id', $this->obraId)
      ->orderBy('nome')
      ->get();
  }

  public function abrirCriar(): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', 'criar'), 403);

    $this->resetForm();
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', 'editar'), 403);

    $fornecedor = Fornecedor::findOrFail($id);
    $this->editandoId = $id;
    $this->nome = $fornecedor->nome;
    $this->cnpj = $fornecedor->cnpj ?? '';
    $this->contatoNome = $fornecedor->contato_nome ?? '';
    $this->contatoEmail = $fornecedor->contato_email ?? '';
    $this->contatoTelefone = $fornecedor->contato_telefone ?? '';
    $this->observacoes = $fornecedor->observacoes ?? '';
    $this->modalAberto = true;
  }

  public function salvar(): void
  {
    abort_unless(
      Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', $this->editandoId ? 'editar' : 'criar'),
      403
    );

    $this->validate(
      [
        'obraId' => 'required|exists:works,id',
        'nome' => 'required|string|max:150',
        'cnpj' => 'nullable|string|max:30',
        'contatoNome' => 'nullable|string|max:150',
        'contatoEmail' => 'nullable|email|max:150',
        'contatoTelefone' => 'nullable|string|max:30',
        'observacoes' => 'nullable|string|max:1000',
      ],
      [
        'obraId.required' => 'Selecione uma obra.',
        'nome.required' => 'O nome do fornecedor é obrigatório.',
        'contatoEmail.email' => 'Informe um e-mail válido.',
      ]
    );

    $dados = [
      'obra_id' => $this->obraId,
      'nome' => $this->nome,
      'cnpj' => $this->cnpj ?: null,
      'contato_nome' => $this->contatoNome ?: null,
      'contato_email' => $this->contatoEmail ?: null,
      'contato_telefone' => $this->contatoTelefone ?: null,
      'observacoes' => $this->observacoes ?: null,
    ];

    $this->transacaoSegura(function () use ($dados) {
      if ($this->editandoId) {
        Fornecedor::findOrFail($this->editandoId)->update($dados);
      } else {
        Fornecedor::create($dados);
      }
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->fornecedores);
    $this->dispatch('show-toast', message: 'Fornecedor salvo com sucesso.');
  }

  public function excluir(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', 'excluir'), 403);

    Fornecedor::findOrFail($id)->delete();
    unset($this->fornecedores);
    $this->dispatch('show-toast', message: 'Fornecedor removido.');
  }

  public function updatedObraId(): void
  {
    unset($this->fornecedores);
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->nome = '';
    $this->cnpj = '';
    $this->contatoNome = '';
    $this->contatoEmail = '';
    $this->contatoTelefone = '';
    $this->observacoes = '';
    $this->resetValidation();
  }
};
?>

<div>

    {{-- Cabeçalho --}}
    <div class="row">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
                <div class="d-flex flex-column justify-content-center">
                    <h4 class="mb-1 mt-3">Fornecedores</h4>
                    <p class="text-muted">Cadastro de fornecedores usado no Mapa de Suprimentos.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Seletor de obra --}}
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="row align-items-end g-3">
                <div class="col-md-6">
                    <label class="form-label mb-1">Obra</label>
                    <select class="form-select" wire:model.live="obraId">
                        <option value="">— Selecione uma obra para configurar —</option>
                        @foreach($this->obras as $obra)
                        <option value="{{ $obra->id }}">{{ $obra->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if($obraId && Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', 'criar'))
                <div class="col-auto">
                    <button class="btn btn-primary" wire:click="abrirCriar">
                        <i class="bx bx-plus me-1"></i>Novo Fornecedor
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>

    @if(!$obraId)
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bx bx-buildings fs-1 d-block mb-2"></i>
            Selecione uma obra acima para ver e configurar seus fornecedores.
        </div>
    </div>

    @elseif($this->fornecedores->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-store fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhum fornecedor cadastrado para esta obra.</p>
            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', 'criar'))
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Criar primeiro fornecedor
            </button>
            @endif
        </div>
    </div>

    @else
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-2">
            <span class="fw-semibold">{{ $this->fornecedores->count() }} fornecedores</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nome</th>
                        <th>CNPJ</th>
                        <th>Contato</th>
                        <th style="width:15%"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->fornecedores as $fornecedor)
                    <tr style="height: 60px;">
                        <td>{{ $fornecedor->nome }}</td>
                        <td class="text-muted small">{{ $fornecedor->cnpj ?? '—' }}</td>
                        <td class="text-muted small">
                            {{ $fornecedor->contato_nome ?? '—' }}
                            @if($fornecedor->contato_email)
                                <br>{{ $fornecedor->contato_email }}
                            @endif
                        </td>
                        <td class="text-end pe-3">
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', 'editar'))
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-1"
                                    wire:click="editar('{{ $fornecedor->id }}')">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @endif
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fornecedores', 'excluir'))
                            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1"
                                    onclick="confirmarAcao(this, {
                                        mensagem: 'Remover \'{{ $fornecedor->nome }}\'?',
                                        metodo: 'excluir',
                                        args: ['{{ $fornecedor->id }}'],
                                        icone: 'bx-trash',
                                    })">
                                <i class="bx bx-trash"></i>
                            </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Modal criar/editar --}}
    @if($modalAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ $editandoId ? 'Editar Fornecedor' : 'Novo Fornecedor' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome"
                               placeholder="ex: FOA Engenharia e Pré-Fabricados">
                        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">CNPJ</label>
                        <input type="text" class="form-control @error('cnpj') is-invalid @enderror" wire:model="cnpj">
                        @error('cnpj')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Contato</label>
                            <input type="text" class="form-control @error('contatoNome') is-invalid @enderror" wire:model="contatoNome">
                            @error('contatoNome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="text" class="form-control @error('contatoTelefone') is-invalid @enderror" wire:model="contatoTelefone">
                            @error('contatoTelefone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-0 mt-3">
                        <label class="form-label">E-mail</label>
                        <input type="text" class="form-control @error('contatoEmail') is-invalid @enderror" wire:model="contatoEmail">
                        @error('contatoEmail')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-0 mt-3">
                        <label class="form-label">Observações</label>
                        <textarea class="form-control @error('observacoes') is-invalid @enderror" rows="2" wire:model="observacoes"></textarea>
                        @error('observacoes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="$set('modalAberto', false)">Cancelar</button>
                    <button class="btn btn-primary" wire:click="salvar">
                        <i class="bx bx-check me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
