<?php

use App\Enums\PilarLean;
use App\Models\CategoriaRestricao;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $nome = '';
  public string $pilarLean = '';

  #[Computed]
  public function categorias(): \Illuminate\Support\Collection
  {
    return CategoriaRestricao::orderBy('pilar_lean')
      ->orderBy('nome')
      ->get();
  }

  #[Computed]
  public function pilares(): array
  {
    return [
      PilarLean::Materiais->value => 'Materiais',
      PilarLean::MaoDeObra->value => 'Mão de Obra',
      PilarLean::Equipamentos->value => 'Equipamentos',
      PilarLean::Informacoes->value => 'Informações',
      PilarLean::CondicoesPrecedentes->value => 'Condições Precedentes',
    ];
  }

  public function abrirCriar(): void
  {
    $this->resetForm();
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    $cat = CategoriaRestricao::findOrFail($id);
    $this->editandoId = $id;
    $this->nome = $cat->nome;
    $this->pilarLean = $cat->pilar_lean->value;
    $this->modalAberto = true;
  }

  public function salvar(): void
  {
    $this->validate(
      [
        'nome' => 'required|string|max:100',
        'pilarLean' => 'required|in:' . implode(',', array_column(PilarLean::cases(), 'value')),
      ],
      [
        'nome.required' => 'O nome é obrigatório.',
        'pilarLean.required' => 'Selecione um pilar Lean.',
        'pilarLean.in' => 'Pilar inválido.',
      ]
    );

    if ($this->editandoId) {
      CategoriaRestricao::findOrFail($this->editandoId)->update([
        'nome' => $this->nome,
        'pilar_lean' => $this->pilarLean,
      ]);
    } else {
      CategoriaRestricao::create([
        'nome' => $this->nome,
        'pilar_lean' => $this->pilarLean,
      ]);
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->categorias);
    $this->invalidarCacheGlobal();
    $this->dispatch('show-toast', message: 'Categoria salva com sucesso.');
  }

  public function excluir(string $id): void
  {
    CategoriaRestricao::findOrFail($id)->delete();
    unset($this->categorias);
    $this->invalidarCacheGlobal();
    $this->dispatch('show-toast', message: 'Categoria removida.');
  }

  private function invalidarCacheGlobal(): void
  {
    // Invalida o cache de categorias do tenant em todas as obras
    Cache::forget('tenant_' . \App\Support\TenantContext::currentId() . '_categorias');
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->nome = '';
    $this->pilarLean = '';
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
                    <h4 class="mb-1 mt-3">Categorias de Restrição</h4>
                    <p class="text-muted">Crie categorias baseadas nos pilares Lean</p>
                </div>
                <div class="d-flex align-content-center flex-wrap gap-2">
                    <button class="btn btn-primary" wire:click="abrirCriar">
                      <i class="bx bx-plus me-1"></i>Nova Categoria
                  </button>
                </div>
            </div>
        </div>
    </div>


    {{-- Legenda dos pilares --}}
    <div class="alert alert-light border mb-4 py-2">
        <small class="text-muted">
            <strong>Pilares Lean:</strong>
            <span class="badge bg-label-primary mx-1">Materiais</span>
            <span class="badge bg-label-success mx-1">Mão de Obra</span>
            <span class="badge bg-label-warning mx-1">Equipamentos</span>
            <span class="badge bg-label-info mx-1">Informações</span>
            <span class="badge bg-label-secondary mx-1">Condições Precedentes</span>
        </small>
    </div>

    {{-- Estado vazio --}}
    @if($this->categorias->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-tag fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhuma categoria cadastrada ainda.</p>
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Criar primeira categoria
            </button>
        </div>
    </div>
    @else

    {{-- Lista agrupada por pilar --}}
    @php
        $pilarLabels = [
            'materiais'              => ['label' => 'Materiais',              'color' => 'primary'],
            'mao_de_obra'            => ['label' => 'Mão de Obra',            'color' => 'success'],
            'equipamentos'           => ['label' => 'Equipamentos',           'color' => 'warning'],
            'informacoes'            => ['label' => 'Informações',            'color' => 'info'],
            'condicoes_precedentes'  => ['label' => 'Condições Precedentes',  'color' => 'secondary'],
        ];
        $agrupado = $this->categorias->groupBy(fn($c) => $c->pilar_lean->value);
    @endphp

    @foreach($pilarLabels as $pilarValue => $pilarMeta)
        @if($agrupado->has($pilarValue))
        <div class="card mb-3">
            <div class="card-header py-3">
                <span class="badge bg-label-{{ $pilarMeta['color'] }} fs-6">{{ $pilarMeta['label'] }}</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                        @foreach($agrupado[$pilarValue] as $cat)
                        <tr style="height: 60px;">
                            <td class="ps-3">{{ $cat->nome }}</td>
                            <td class="text-muted small">
                                <strong>{{ $cat->restricoes()->count() }}</strong> restrições vinculadas
                            </td>
                            <td class="text-end pe-3">
                                <button class="btn btn-xs btn-outline-secondary me-1 py-0 px-2"
                                        wire:click="editar('{{ $cat->id }}')">
                                    <i class="bx bx-pencil"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-danger py-0 px-2"
                                        wire:click="excluir('{{ $cat->id }}')"
                                        wire:confirm="Remover '{{ $cat->nome }}'? Restrições vinculadas perderão esta categoria.">
                                    <i class="bx bx-trash"></i>
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    @endforeach
    @endif

    {{-- Modal criar/editar --}}
    @if($modalAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ $editandoId ? 'Editar Categoria' : 'Nova Categoria' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome"
                               placeholder="ex: Projeto de estrutura, Licença ambiental...">
                        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Pilar Lean <span class="text-danger">*</span></label>
                        <select class="form-select @error('pilarLean') is-invalid @enderror"
                                wire:model="pilarLean">
                            <option value="">— Selecione —</option>
                            @foreach($this->pilares as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('pilarLean')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
