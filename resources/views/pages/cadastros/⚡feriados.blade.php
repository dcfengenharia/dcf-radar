<?php

use App\Models\Feriado;
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
  public string $data = '';
  public string $descricao = '';

  #[Computed]
  public function obras(): \Illuminate\Support\Collection
  {
    return Work::orderBy('name')->get(['id', 'name']);
  }

  #[Computed]
  public function feriados(): \Illuminate\Support\Collection
  {
    if (!$this->obraId) {
      return collect();
    }

    return Feriado::where('obra_id', $this->obraId)
      ->orderBy('data')
      ->get();
  }

  public function abrirCriar(): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', 'criar'), 403);

    $this->resetForm();
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', 'editar'), 403);

    $feriado = Feriado::findOrFail($id);
    $this->editandoId = $id;
    $this->data = $feriado->data->toDateString();
    $this->descricao = $feriado->descricao ?? '';
    $this->modalAberto = true;
  }

  public function salvar(): void
  {
    abort_unless(
      Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', $this->editandoId ? 'editar' : 'criar'),
      403
    );

    $this->validate(
      [
        'obraId' => 'required|exists:works,id',
        'data' => 'required|date',
        'descricao' => 'nullable|string|max:150',
      ],
      [
        'obraId.required' => 'Selecione uma obra.',
        'data.required' => 'Informe a data do feriado.',
      ]
    );

    $dados = [
      'obra_id' => $this->obraId,
      'data' => $this->data,
      'descricao' => $this->descricao ?: null,
    ];

    $this->transacaoSegura(function () use ($dados) {
      if ($this->editandoId) {
        Feriado::findOrFail($this->editandoId)->update($dados);
      } else {
        Feriado::create($dados);
      }
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->feriados);
    $this->dispatch('show-toast', message: 'Feriado salvo com sucesso.');
  }

  public function excluir(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', 'excluir'), 403);

    Feriado::findOrFail($id)->delete();
    unset($this->feriados);
    $this->dispatch('show-toast', message: 'Feriado removido.');
  }

  public function updatedObraId(): void
  {
    unset($this->feriados);
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->data = '';
    $this->descricao = '';
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
                    <h4 class="mb-1 mt-3">Feriados</h4>
                    <p class="text-muted">Calendário de feriados usado no cálculo de dias úteis do Mapa de Suprimentos.</p>
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
                @if($obraId && Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', 'criar'))
                <div class="col-auto">
                    <button class="btn btn-primary" wire:click="abrirCriar">
                        <i class="bx bx-plus me-1"></i>Novo Feriado
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
            Selecione uma obra acima para ver e configurar seus feriados.
        </div>
    </div>

    @elseif($this->feriados->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-calendar-x fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhum feriado cadastrado para esta obra.</p>
            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', 'criar'))
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Criar primeiro feriado
            </button>
            @endif
        </div>
    </div>

    @else
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-2">
            <span class="fw-semibold">{{ $this->feriados->count() }} feriados</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:20%">Data</th>
                        <th>Descrição</th>
                        <th style="width:15%"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->feriados as $feriado)
                    <tr style="height: 60px;">
                        <td>{{ $feriado->data->format('d/m/Y') }}</td>
                        <td class="text-muted small">{{ $feriado->descricao ?? '—' }}</td>
                        <td class="text-end pe-3">
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', 'editar'))
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-1"
                                    wire:click="editar('{{ $feriado->id }}')">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @endif
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.feriados', 'excluir'))
                            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1"
                                    onclick="confirmarAcao(this, {
                                        mensagem: 'Remover o feriado de {{ $feriado->data->format('d/m/Y') }}?',
                                        metodo: 'excluir',
                                        args: ['{{ $feriado->id }}'],
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
                        {{ $editandoId ? 'Editar Feriado' : 'Novo Feriado' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Data <span class="text-danger">*</span></label>
                        <input type="date"
                               class="form-control @error('data') is-invalid @enderror"
                               wire:model="data">
                        @error('data')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Descrição</label>
                        <input type="text"
                               class="form-control @error('descricao') is-invalid @enderror"
                               wire:model="descricao"
                               placeholder="ex: Feriado municipal">
                        @error('descricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
