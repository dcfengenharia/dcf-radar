<?php

use App\Models\FamiliaMaterial;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ajuste de arquitetura de navegação — mesma decisão de
 * `⚡unidades-medida.blade.php`, aplicada a FamiliaMaterial. Zero mudança
 * de model/tabela/tenant scope — Família continua opcional em Material,
 * nunca obrigatória.
 */
new class extends Component {
  use ExecutaComTransacaoSegura;

  public string $busca = '';

  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $codigo = '';
  public string $nome = '';

  #[Computed]
  public function familias(): \Illuminate\Support\Collection
  {
    return FamiliaMaterial::query()
      ->when($this->busca !== '', fn ($q) => $q->where(fn ($qq) => $qq
        ->where('codigo', 'like', '%' . $this->busca . '%')
        ->orWhere('nome', 'like', '%' . $this->busca . '%')))
      ->orderBy('nome')
      ->get();
  }

  public function abrirCriar(): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', 'criar'), 403);

    $this->resetForm();
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', 'editar'), 403);

    $familia = FamiliaMaterial::findOrFail($id);
    $this->editandoId = $id;
    $this->codigo = $familia->codigo ?? '';
    $this->nome = $familia->nome;
    $this->modalAberto = true;
  }

  /**
   * Duplicidade de nome (`familias_material_tenant_nome_unique`) tratada
   * via captura do erro 1062, dentro do closure de transacaoSegura() —
   * mesmo idioma de `⚡unidades-medida.blade.php::salvar()`.
   */
  public function salvar(): void
  {
    abort_unless(
      Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', $this->editandoId ? 'editar' : 'criar'),
      403
    );

    $this->validate([
      'codigo' => 'nullable|string|max:20',
      'nome' => 'required|string|max:255',
    ]);

    $duplicado = false;
    $editandoId = $this->editandoId;
    $dados = ['codigo' => $this->codigo !== '' ? $this->codigo : null, 'nome' => $this->nome];

    $this->transacaoSegura(function () use (&$duplicado, $editandoId, $dados) {
      try {
        if ($editandoId) {
          FamiliaMaterial::findOrFail($editandoId)->update($dados);
        } else {
          FamiliaMaterial::create($dados + ['ativo' => true]);
        }
      } catch (\Illuminate\Database\QueryException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
          $duplicado = true;

          return;
        }

        throw $e;
      }
    }, 'Não foi possível salvar a Família de Materiais.');

    if ($duplicado) {
      $this->addError('nome', 'Já existe uma Família de Materiais com este nome neste tenant.');

      return;
    }

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->familias);
    $this->dispatch('show-toast', message: 'Família de Materiais salva com sucesso.');
  }

  public function alternarStatus(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', 'excluir'), 403);

    $this->transacaoSegura(function () use ($id) {
      $familia = FamiliaMaterial::findOrFail($id);
      $familia->update(['ativo' => ! $familia->ativo]);
      unset($this->familias);
    }, 'Não foi possível atualizar o status da Família de Materiais.');

    if (! $this->transacaoSeguraFalhou()) {
      $this->dispatch('show-toast', message: 'Status da Família de Materiais atualizado.');
    }
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->codigo = '';
    $this->nome = '';
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
                    <h4 class="mb-1 mt-3">Famílias de Materiais</h4>
                    <p class="text-muted">Cadastro corporativo do tenant — classificação opcional dos Materiais do Catálogo Mestre. Nunca obrigatória.</p>
                </div>
                <div class="d-flex align-content-center flex-wrap gap-2">
                    @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', 'criar'))
                    <button class="btn btn-primary" wire:click="abrirCriar">
                        <i class="bx bx-plus me-1"></i>Nova Família
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($this->familias->isEmpty() && $busca === '')
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-category fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhuma Família de Materiais cadastrada ainda.</p>
            <p class="text-muted small mb-3">
                Exemplo: Tubulação, Elétrica, Instrumentação
            </p>
            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', 'criar'))
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Cadastrar primeira Família
            </button>
            @endif
        </div>
    </div>
    @else
    <div class="card">
        <div class="card-header">
            <input type="text" class="form-control" style="max-width: 320px" wire:model.live.debounce.300ms="busca" placeholder="Pesquisar por código ou nome...">
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Código</th>
                        <th>Nome</th>
                        <th class="text-center">Status</th>
                        <th style="width:15%"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->familias as $familia)
                    <tr wire:key="familia-{{ $familia->id }}" style="height: 52px;">
                        <td>{{ $familia->codigo ?? '—' }}</td>
                        <td>{{ $familia->nome }}</td>
                        <td class="text-center">
                            <span class="badge bg-label-{{ $familia->ativo ? 'success' : 'secondary' }}">
                                {{ $familia->ativo ? 'Ativa' : 'Inativa' }}
                            </span>
                        </td>
                        <td class="text-end pe-3">
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', 'editar'))
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-1" wire:click="editar('{{ $familia->id }}')">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @endif
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.familias_material', 'excluir'))
                            <button type="button" class="btn btn-xs btn-outline-{{ $familia->ativo ? 'warning' : 'success' }} py-0 px-1"
                                    onclick="confirmarAcao(this, {
                                        mensagem: '{{ $familia->ativo ? 'Inativar' : 'Reativar' }} a Família \'{{ $familia->nome }}\'?',
                                        metodo: 'alternarStatus',
                                        args: ['{{ $familia->id }}'],
                                        icone: '{{ $familia->ativo ? 'bx-block' : 'bx-check-circle' }}',
                                    })">
                                <i class="bx {{ $familia->ativo ? 'bx-block' : 'bx-check-circle' }}"></i>
                            </button>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">Nenhuma Família encontrada para "{{ $busca }}".</td></tr>
                    @endforelse
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
                        {{ $editandoId ? 'Editar Família de Materiais' : 'Nova Família de Materiais' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Código (opcional)</label>
                        <input type="text"
                               class="form-control @error('codigo') is-invalid @enderror"
                               wire:model="codigo">
                        @error('codigo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome">
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
