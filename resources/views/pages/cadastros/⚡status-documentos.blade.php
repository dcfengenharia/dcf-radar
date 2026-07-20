<?php

use App\Models\StatusDocumento;
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
  public string $codigo = '';
  public string $cor = '#0d6efd';
  public bool $conclusivo = false;

  #[Computed]
  public function obras(): \Illuminate\Support\Collection
  {
    return Work::orderBy('name')->get(['id', 'name']);
  }

  #[Computed]
  public function statusDocumentos(): \Illuminate\Support\Collection
  {
    if (!$this->obraId) {
      return collect();
    }

    return StatusDocumento::where('obra_id', $this->obraId)->orderBy('ordem')->get();
  }

  public function abrirCriar(): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'criar'), 403);

    $this->resetForm();
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'editar'), 403);

    $status = StatusDocumento::findOrFail($id);
    $this->editandoId = $id;
    $this->nome = $status->nome;
    $this->codigo = $status->codigo ?? '';
    $this->cor = $status->cor ?? '#0d6efd';
    $this->conclusivo = $status->conclusivo;
    $this->modalAberto = true;
  }

  public function salvar(): void
  {
    abort_unless(
      Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', $this->editandoId ? 'editar' : 'criar'),
      403
    );

    $this->validate(
      [
        'obraId' => 'required|exists:works,id',
        'nome' => 'required|string|max:100',
        'codigo' => 'nullable|string|max:50',
        'cor' => 'nullable|string|max:7',
      ],
      [
        'obraId.required' => 'Selecione uma obra.',
        'nome.required' => 'O nome do status é obrigatório.',
      ]
    );

    $this->transacaoSegura(function () {
      if ($this->editandoId) {
        StatusDocumento::findOrFail($this->editandoId)->update([
          'nome' => $this->nome,
          'codigo' => $this->codigo ?: null,
          'cor' => $this->cor ?: null,
          'conclusivo' => $this->conclusivo,
        ]);
      } else {
        $proximaOrdem = (int) (StatusDocumento::where('obra_id', $this->obraId)->max('ordem')) + 1;

        StatusDocumento::create([
          'obra_id' => $this->obraId,
          'nome' => $this->nome,
          'codigo' => $this->codigo ?: null,
          'cor' => $this->cor ?: null,
          'conclusivo' => $this->conclusivo,
          'ordem' => $proximaOrdem,
        ]);
      }
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->statusDocumentos);
    $this->dispatch('show-toast', message: 'Status de documento salvo com sucesso.');
  }

  public function excluir(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'excluir'), 403);

    StatusDocumento::findOrFail($id)->delete();
    unset($this->statusDocumentos);
    $this->dispatch('show-toast', message: 'Status de documento removido.');
  }

  public function moverCima(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'editar'), 403);
    $this->trocarOrdem($id, -1);
  }

  public function moverBaixo(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'editar'), 403);
    $this->trocarOrdem($id, 1);
  }

  private function trocarOrdem(string $id, int $direcao): void
  {
    $lista = StatusDocumento::where('obra_id', $this->obraId)->orderBy('ordem')->get();
    $indice = $lista->search(fn($s) => $s->id === $id);

    if ($indice === false) {
      return;
    }

    $indiceVizinho = $indice + $direcao;
    if ($indiceVizinho < 0 || $indiceVizinho >= $lista->count()) {
      return;
    }

    $atual = $lista[$indice];
    $vizinho = $lista[$indiceVizinho];

    $this->transacaoSegura(function () use ($atual, $vizinho) {
      $ordemAtual = $atual->ordem;
      $atual->update(['ordem' => $vizinho->ordem]);
      $vizinho->update(['ordem' => $ordemAtual]);
    });

    unset($this->statusDocumentos);
  }

  public function updatedObraId(): void
  {
    unset($this->statusDocumentos);
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->nome = '';
    $this->codigo = '';
    $this->cor = '#0d6efd';
    $this->conclusivo = false;
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
                    <h4 class="mb-1 mt-3">Status de Documento</h4>
                    <p class="text-muted">Lista de status usada na Lista de Documentos de Engenharia — configurável por obra.</p>
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
                @if($obraId && Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'criar'))
                <div class="col-auto">
                    <button class="btn btn-primary" wire:click="abrirCriar">
                        <i class="bx bx-plus me-1"></i>Novo Status
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
            Selecione uma obra acima para ver e configurar seus status de documento.
        </div>
    </div>

    @elseif($this->statusDocumentos->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-purchase-tag fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhum status de documento cadastrado para esta obra.</p>
            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'criar'))
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Criar primeiro status
            </button>
            @endif
        </div>
    </div>

    @else
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-2">
            <span class="fw-semibold">{{ $this->statusDocumentos->count() }} status</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:70px"></th>
                        <th>Nome</th>
                        <th style="width:120px">Código</th>
                        <th class="text-center" style="width:100px">Cor</th>
                        <th class="text-center" style="width:120px">Conclusivo</th>
                        <th style="width:15%"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->statusDocumentos as $indice => $status)
                    <tr style="height: 56px;">
                        <td>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1 me-1"
                                    wire:click="moverCima('{{ $status->id }}')"
                                    @disabled($indice === 0)>↑</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1"
                                    wire:click="moverBaixo('{{ $status->id }}')"
                                    @disabled($indice === $this->statusDocumentos->count() - 1)>↓</button>
                        </td>
                        <td>{{ $status->nome }}</td>
                        <td class="text-muted small">{{ $status->codigo ?? '—' }}</td>
                        <td class="text-center">
                            <span class="d-inline-block rounded-circle border" style="width:20px;height:20px;background:{{ $status->cor ?? '#adb5bd' }}"></span>
                        </td>
                        <td class="text-center">
                            @if($status->conclusivo)
                            <i class="bx bx-check-circle text-success"></i>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end pe-3">
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'editar'))
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-1"
                                    wire:click="editar('{{ $status->id }}')">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @endif
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.status_documentos', 'excluir'))
                            <button class="btn btn-xs btn-outline-danger py-0 px-1"
                                    wire:click="excluir('{{ $status->id }}')"
                                    wire:confirm="Remover '{{ $status->nome }}'?">
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
                        {{ $editandoId ? 'Editar Status' : 'Novo Status' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome"
                               placeholder="ex: Em Elaboração">
                        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            Código <span class="text-muted">(opcional)</span>
                        </label>
                        <input type="text"
                               class="form-control @error('codigo') is-invalid @enderror"
                               wire:model="codigo"
                               placeholder="ex: AP">
                        <small class="text-muted">Sigla curta usada pra casar com a planilha na importação — não precisa ser igual ao Nome.</small>
                        @error('codigo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cor</label>
                        <input type="color" class="form-control form-control-color" wire:model="cor">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="conclusivo" wire:model="conclusivo">
                        <label class="form-check-label" for="conclusivo">
                            Marca esse status como "documento concluído" nos cards da Lista de Documentos
                        </label>
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
