<?php

use App\Models\Plano;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $nome = '';
  public string $descricao = '';
  public string $precoMensal = '';
  public ?string $maxObras = null;
  public ?string $maxUsuarios = null;
  public string $limiteUploadMb = '100';
  public bool $ativo = true;

  #[Computed]
  public function planos(): \Illuminate\Support\Collection
  {
    return Plano::orderBy('ativo', 'desc')->orderBy('nome')->get();
  }

  public function abrirCriar(): void
  {
    $this->resetForm();
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    $plano = Plano::findOrFail($id);
    $this->editandoId = $id;
    $this->nome = $plano->nome;
    $this->descricao = (string) $plano->descricao;
    $this->precoMensal = (string) $plano->preco_mensal;
    $this->maxObras = $plano->max_obras !== null ? (string) $plano->max_obras : null;
    $this->maxUsuarios = $plano->max_usuarios !== null ? (string) $plano->max_usuarios : null;
    $this->limiteUploadMb = (string) $plano->limite_upload_mb;
    $this->ativo = $plano->ativo;
    $this->resetValidation();
    $this->modalAberto = true;
  }

  public function salvar(): void
  {
    $this->validate([
      'nome' => 'required|string|max:100',
      'descricao' => 'nullable|string|max:1000',
      'precoMensal' => 'required|numeric|min:0',
      'maxObras' => 'nullable|integer|min:0',
      'maxUsuarios' => 'nullable|integer|min:0',
      'limiteUploadMb' => 'required|integer|min:1',
    ], [], [
      'precoMensal' => 'preço mensal',
      'maxObras' => 'máximo de obras',
      'maxUsuarios' => 'máximo de usuários',
      'limiteUploadMb' => 'limite de upload (MB)',
    ]);

    $dados = [
      'nome' => $this->nome,
      'descricao' => $this->descricao ?: null,
      'preco_mensal' => $this->precoMensal,
      'max_obras' => $this->maxObras !== null && $this->maxObras !== '' ? $this->maxObras : null,
      'max_usuarios' => $this->maxUsuarios !== null && $this->maxUsuarios !== '' ? $this->maxUsuarios : null,
      'limite_upload_mb' => $this->limiteUploadMb,
      'ativo' => $this->ativo,
    ];

    if ($this->editandoId) {
      Plano::findOrFail($this->editandoId)->update($dados);
    } else {
      Plano::create($dados);
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->planos);
    $this->dispatch('show-toast', message: 'Plano salvo com sucesso.');
  }

  public function descontinuar(string $id): void
  {
    Plano::findOrFail($id)->update(['ativo' => false]);
    unset($this->planos);
    $this->dispatch('show-toast', message: 'Plano descontinuado.');
  }

  public function reativar(string $id): void
  {
    Plano::findOrFail($id)->update(['ativo' => true]);
    unset($this->planos);
    $this->dispatch('show-toast', message: 'Plano reativado.');
  }

  public function excluir(string $id): void
  {
    $plano = Plano::findOrFail($id);

    if ($plano->emUsoPorAlgumTenant()) {
      $this->dispatch('show-toast', message: 'Este plano já foi usado por alguma conta — só é possível descontinuar, não excluir.');
      return;
    }

    $plano->delete();
    unset($this->planos);
    $this->dispatch('show-toast', message: 'Plano excluído.');
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->nome = '';
    $this->descricao = '';
    $this->precoMensal = '';
    $this->maxObras = null;
    $this->maxUsuarios = null;
    $this->limiteUploadMb = (string) Plano::LIMITE_UPLOAD_PADRAO_MB;
    $this->ativo = true;
    $this->resetValidation();
  }
};
?>

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted mb-0">Planos oferecidos aos tenants. Planos descontinuados continuam válidos pra quem já os usa, mas não podem ser atribuídos a novas contas.</p>
        <button class="btn btn-primary text-nowrap ms-3" wire:click="abrirCriar">
            <i class="bx bx-plus me-1"></i>Novo Plano
        </button>
    </div>

    <div class="row g-4">
        @forelse ($this->planos as $plano)
            <div class="col-md-4">
                <div class="card h-100 {{ !$plano->ativo ? 'opacity-75' : '' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="mb-0">{{ $plano->nome }}</h5>
                            @if (!$plano->ativo)
                                <span class="badge bg-label-secondary">Descontinuado</span>
                            @endif
                        </div>
                        <h3 class="mb-2">R$ {{ number_format($plano->preco_mensal, 2, ',', '.') }}<small class="text-muted">/mês</small></h3>
                        <p class="text-muted small mb-2">{{ $plano->descricao ?: 'Sem descrição.' }}</p>
                        <p class="small mb-0">
                            <strong>Máx. obras:</strong> {{ $plano->max_obras ?? 'Ilimitado' }}<br>
                            <strong>Máx. usuários:</strong> {{ $plano->max_usuarios ?? 'Ilimitado' }}<br>
                            <strong>Limite de upload:</strong> {{ $plano->limite_upload_mb }} MB
                        </p>
                    </div>
                    <div class="card-footer d-flex gap-1">
                        <button class="btn btn-xs btn-outline-secondary py-1 px-2" wire:click="editar('{{ $plano->id }}')">
                            <i class="bx bx-pencil"></i> Editar
                        </button>
                        @if ($plano->ativo)
                            <button class="btn btn-xs btn-outline-warning py-1 px-2" wire:click="descontinuar('{{ $plano->id }}')"
                                    wire:confirm="Descontinuar '{{ $plano->nome }}'? Contas que já usam continuam funcionando.">
                                <i class="bx bx-block"></i> Descontinuar
                            </button>
                        @else
                            <button class="btn btn-xs btn-outline-success py-1 px-2" wire:click="reativar('{{ $plano->id }}')">
                                <i class="bx bx-check"></i> Reativar
                            </button>
                        @endif
                        <button class="btn btn-xs btn-outline-danger py-1 px-2" wire:click="excluir('{{ $plano->id }}')"
                                wire:confirm="Excluir '{{ $plano->nome }}' definitivamente?">
                            <i class="bx bx-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bx bx-package fs-1 text-muted d-block mb-2"></i>
                        <p class="text-muted mb-3">Nenhum plano cadastrado ainda.</p>
                        <button class="btn btn-primary" wire:click="abrirCriar">
                            <i class="bx bx-plus me-1"></i>Criar primeiro plano
                        </button>
                    </div>
                </div>
            </div>
        @endforelse
    </div>

    {{-- Modal criar/editar --}}
    @if ($modalAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editandoId ? 'Editar Plano' : 'Novo Plano' }}</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('nome') is-invalid @enderror" wire:model="nome">
                        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control @error('descricao') is-invalid @enderror" wire:model="descricao" rows="2"></textarea>
                        @error('descricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Preço Mensal (R$) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" class="form-control @error('precoMensal') is-invalid @enderror" wire:model="precoMensal">
                        @error('precoMensal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Máx. Obras <span class="text-muted">(vazio = ilimitado)</span></label>
                            <input type="number" min="0" class="form-control @error('maxObras') is-invalid @enderror" wire:model="maxObras">
                            @error('maxObras')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Máx. Usuários <span class="text-muted">(vazio = ilimitado)</span></label>
                            <input type="number" min="0" class="form-control @error('maxUsuarios') is-invalid @enderror" wire:model="maxUsuarios">
                            @error('maxUsuarios')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Limite de upload de cronograma (MB) <span class="text-danger">*</span></label>
                        <input type="number" min="1" class="form-control @error('limiteUploadMb') is-invalid @enderror" wire:model="limiteUploadMb">
                        <small class="text-muted">Teto de tamanho de arquivo .xml aceito na importação de cronograma e avanço.</small>
                        @error('limiteUploadMb')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" wire:model="ativo" id="planoAtivo">
                        <label class="form-check-label" for="planoAtivo">Plano ativo (disponível pra novas atribuições)</label>
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
