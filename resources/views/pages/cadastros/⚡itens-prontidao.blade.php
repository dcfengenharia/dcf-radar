<?php

use App\Models\ItemProntidao;
use App\Models\Work;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
  public ?string $obraId = null;
  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $nome = '';
  public int $ordem = 0;

  #[Computed]
  public function obras(): \Illuminate\Support\Collection
  {
    return Work::orderBy('name')->get(['id', 'name']);
  }

  #[Computed]
  public function itens(): \Illuminate\Support\Collection
  {
    if (!$this->obraId) {
      return collect();
    }

    return ItemProntidao::where('obra_id', $this->obraId)
      ->orderBy('ordem')
      ->orderBy('nome')
      ->get();
  }

  public function abrirCriar(): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'criar'), 403);

    $this->resetForm();
    $this->ordem = ($this->itens->max('ordem') ?? -1) + 1;
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'editar'), 403);

    $item = ItemProntidao::findOrFail($id);
    $this->editandoId = $id;
    $this->nome = $item->nome;
    $this->ordem = $item->ordem;
    $this->modalAberto = true;
  }

  public function salvar(): void
  {
    abort_unless(
      Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', $this->editandoId ? 'editar' : 'criar'),
      403
    );

    $this->validate(
      [
        'obraId' => 'required|exists:works,id',
        'nome' => 'required|string|max:150',
        'ordem' => 'integer|min:0',
      ],
      [
        'obraId.required' => 'Selecione uma obra.',
        'nome.required' => 'O nome do item é obrigatório.',
      ]
    );

    if ($this->editandoId) {
      ItemProntidao::findOrFail($this->editandoId)->update([
        'nome' => $this->nome,
        'ordem' => $this->ordem,
      ]);
    } else {
      ItemProntidao::create([
        'obra_id' => $this->obraId,
        'nome' => $this->nome,
        'ordem' => $this->ordem,
      ]);
    }

    Cache::forget("obra_{$this->obraId}_itens_prontidao");
    $this->resetForm();
    $this->modalAberto = false;
    unset($this->itens);
    $this->dispatch('show-toast', message: 'Item salvo com sucesso.');
  }

  public function moverCima(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'editar'), 403);

    $item = ItemProntidao::findOrFail($id);
    $anterior = ItemProntidao::where('obra_id', $this->obraId)
      ->where('ordem', '<', $item->ordem)
      ->orderByDesc('ordem')
      ->first();

    if ($anterior) {
      [$item->ordem, $anterior->ordem] = [$anterior->ordem, $item->ordem];
      $item->save();
      $anterior->save();
      unset($this->itens);
    }
  }

  public function moverBaixo(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'editar'), 403);

    $item = ItemProntidao::findOrFail($id);
    $proximo = ItemProntidao::where('obra_id', $this->obraId)
      ->where('ordem', '>', $item->ordem)
      ->orderBy('ordem')
      ->first();

    if ($proximo) {
      [$item->ordem, $proximo->ordem] = [$proximo->ordem, $item->ordem];
      $item->save();
      $proximo->save();
      unset($this->itens);
    }
  }

  public function excluir(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'excluir'), 403);

    ItemProntidao::findOrFail($id)->delete();
    unset($this->itens);
    $this->dispatch('show-toast', message: 'Item removido.');
  }

  public function updatedObraId(): void
  {
    unset($this->itens);
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->nome = '';
    $this->ordem = 0;
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
                    <h4 class="mb-1 mt-3">Itens de Prontidão</h4>
                    <p class="text-muted">Determine os itens necessários para a liberação da atividade. Todos os itens devem estar marcados para a atividade ser liberada.</p>
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
                @if($obraId && Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'criar'))
                <div class="col-auto">
                    <button class="btn btn-primary" wire:click="abrirCriar">
                        <i class="bx bx-plus me-1"></i>Novo Item
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
            Selecione uma obra acima para ver e configurar seus itens de prontidão.
        </div>
    </div>

    @elseif($this->itens->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-check-square fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhum item de prontidão cadastrado para esta obra.</p>
            <p class="text-muted small mb-3">
                Exemplo: "Projeto executivo disponível", "Materiais no canteiro",
                "Equipamentos reservados", "Frente de serviço liberada"
            </p>
            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'criar'))
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Criar primeiro item
            </button>
            @endif
        </div>
    </div>

    @else
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-2">
            <span class="fw-semibold">{{ $this->itens->count() }} itens de prontidão</span>
            <small class="text-muted">Use ↑↓ para reordenar</small>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:10%" class="text-center">Ordem</th>
                        <th style="width:60%">Item</th>
                        <th style="width:30%"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->itens as $loop_index => $item)
                    <tr style="height: 60px;">
                        <td class="text-center text-muted small">{{ $loop_index + 1 }}</td>
                        <td>{{ $item->nome }}</td>
                        <td class="text-end pe-3">
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'editar'))
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-1"
                                    wire:click="moverCima('{{ $item->id }}')"
                                    @disabled($loop_index === 0) title="Mover para cima">↑</button>
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-4"
                                    wire:click="moverBaixo('{{ $item->id }}')"
                                    @disabled($loop_index === $this->itens->count() - 1) title="Mover para baixo">↓</button>
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-2"
                                    wire:click="editar('{{ $item->id }}')">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @endif
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.itens_prontidao', 'excluir'))
                            <button class="btn btn-xs btn-outline-danger py-0 px-1"
                                    wire:click="excluir('{{ $item->id }}')"
                                    wire:confirm="Remover '{{ $item->nome }}'?">
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
                        {{ $editandoId ? 'Editar Item' : 'Novo Item de Prontidão' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome do item <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome"
                               placeholder="ex: Projeto executivo disponível">
                        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
