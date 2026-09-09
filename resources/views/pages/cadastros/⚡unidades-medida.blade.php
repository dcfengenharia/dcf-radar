<?php

use App\Models\UnidadeMedida;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ajuste de arquitetura de navegação — UnidadeMedida é cadastro
 * corporativo tenant-wide (mesmo escopo/model já usado desde o Ciclo
 * 19.1), reposicionado de `⚡estoque.blade.php` (abas administrativas,
 * removidas) para `Configurações → Cadastros`, mesmo padrão exato de
 * `⚡fluxos-suprimento.blade.php`/`⚡fornecedores.blade.php` — permissão via
 * `temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', $acao)`,
 * nunca `temPermissaoNaObra()` (essa é para telas obra-scoped).
 *
 * Zero mudança de model/tabela/tenant scope — `App\Models\UnidadeMedida`
 * continua sendo a única fonte de verdade, consumida por Material
 * (Catálogo Mestre), Take Off e pela importação de Material.
 */
new class extends Component {
  use ExecutaComTransacaoSegura;

  public string $busca = '';

  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $codigo = '';
  public string $nome = '';

  #[Computed]
  public function unidades(): \Illuminate\Support\Collection
  {
    return UnidadeMedida::query()
      ->when($this->busca !== '', fn ($q) => $q->where(fn ($qq) => $qq
        ->where('codigo', 'like', '%' . $this->busca . '%')
        ->orWhere('nome', 'like', '%' . $this->busca . '%')))
      ->orderBy('codigo')
      ->get();
  }

  public function abrirCriar(): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', 'criar'), 403);

    $this->resetForm();
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', 'editar'), 403);

    $unidade = UnidadeMedida::findOrFail($id);
    $this->editandoId = $id;
    $this->codigo = $unidade->codigo;
    $this->nome = $unidade->nome;
    $this->modalAberto = true;
  }

  /**
   * Duplicidade de código (`unidades_medida_tenant_codigo_unique`)
   * tratada via captura do erro 1062 — o catch precisa ficar DENTRO do
   * closure de transacaoSegura(), nunca por fora: o trait já engole
   * qualquer Throwable internamente (nunca rethrow), então um try/catch
   * externo nunca veria a QueryException de duplicidade (achado real da
   * implementação original, ver CLAUDE.md).
   */
  public function salvar(): void
  {
    abort_unless(
      Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', $this->editandoId ? 'editar' : 'criar'),
      403
    );

    $this->validate([
      'codigo' => 'required|string|max:20',
      'nome' => 'required|string|max:255',
    ]);

    $duplicado = false;
    $editandoId = $this->editandoId;
    $dados = ['codigo' => $this->codigo, 'nome' => $this->nome];

    $this->transacaoSegura(function () use (&$duplicado, $editandoId, $dados) {
      try {
        if ($editandoId) {
          UnidadeMedida::findOrFail($editandoId)->update($dados);
        } else {
          UnidadeMedida::create($dados + ['ativo' => true]);
        }
      } catch (\Illuminate\Database\QueryException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
          $duplicado = true;

          return;
        }

        throw $e;
      }
    }, 'Não foi possível salvar a Unidade de Medida.');

    if ($duplicado) {
      $this->addError('codigo', 'Já existe uma Unidade de Medida com este código neste tenant.');

      return;
    }

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->unidades);
    $this->dispatch('show-toast', message: 'Unidade de Medida salva com sucesso.');
  }

  public function alternarStatus(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', 'excluir'), 403);

    $this->transacaoSegura(function () use ($id) {
      $unidade = UnidadeMedida::findOrFail($id);
      $unidade->update(['ativo' => ! $unidade->ativo]);
      unset($this->unidades);
    }, 'Não foi possível atualizar o status da Unidade de Medida.');

    if (! $this->transacaoSeguraFalhou()) {
      $this->dispatch('show-toast', message: 'Status da Unidade de Medida atualizado.');
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
                    <h4 class="mb-1 mt-3">Unidades de Medida</h4>
                    <p class="text-muted">Cadastro corporativo do tenant — reutilizado por todas as obras no Catálogo Mestre de Materiais, no Take Off e na importação de Materiais via Excel.</p>
                </div>
                <div class="d-flex align-content-center flex-wrap gap-2">
                    @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', 'criar'))
                    <button class="btn btn-primary" wire:click="abrirCriar">
                        <i class="bx bx-plus me-1"></i>Nova Unidade
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($this->unidades->isEmpty() && $busca === '')
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-ruler fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhuma Unidade de Medida cadastrada ainda.</p>
            <p class="text-muted small mb-3">
                Exemplo: UN (Unidade), M (Metro), M2 (Metro quadrado), KG (Quilograma)
            </p>
            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', 'criar'))
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Cadastrar primeira Unidade
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
                    @forelse($this->unidades as $unidade)
                    <tr wire:key="unidade-{{ $unidade->id }}" style="height: 52px;">
                        <td>{{ $unidade->codigo }}</td>
                        <td>{{ $unidade->nome }}</td>
                        <td class="text-center">
                            <span class="badge bg-label-{{ $unidade->ativo ? 'success' : 'secondary' }}">
                                {{ $unidade->ativo ? 'Ativa' : 'Inativa' }}
                            </span>
                        </td>
                        <td class="text-end pe-3">
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', 'editar'))
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-1" wire:click="editar('{{ $unidade->id }}')">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @endif
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.unidades_medida', 'excluir'))
                            <button type="button" class="btn btn-xs btn-outline-{{ $unidade->ativo ? 'warning' : 'success' }} py-0 px-1"
                                    onclick="confirmarAcao(this, {
                                        mensagem: '{{ $unidade->ativo ? 'Inativar' : 'Reativar' }} a Unidade \'{{ $unidade->codigo }}\'?',
                                        metodo: 'alternarStatus',
                                        args: ['{{ $unidade->id }}'],
                                        icone: '{{ $unidade->ativo ? 'bx-block' : 'bx-check-circle' }}',
                                    })">
                                <i class="bx {{ $unidade->ativo ? 'bx-block' : 'bx-check-circle' }}"></i>
                            </button>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">Nenhuma Unidade encontrada para "{{ $busca }}".</td></tr>
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
                        {{ $editandoId ? 'Editar Unidade de Medida' : 'Nova Unidade de Medida' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Código <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('codigo') is-invalid @enderror"
                               wire:model="codigo"
                               placeholder="Ex.: UN, M, M2, M3, KG, T, L">
                        @error('codigo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome"
                               placeholder="Ex.: Unidade, Metro, Metro quadrado, Quilograma">
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
