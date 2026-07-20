<?php

use App\Models\FluxoSuprimento;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
  use ExecutaComTransacaoSegura;

  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $nome = '';
  public string $descricao = '';
  public bool $ativo = true;

  /** @var array<int, array{nome: string, prazo_dias_uteis: int|null}> */
  public array $etapas = [];

  #[Computed]
  public function fluxos(): \Illuminate\Support\Collection
  {
    return FluxoSuprimento::withCount('itensSuprimento')
      ->with('etapas')
      ->orderBy('nome')
      ->get();
  }

  public function abrirCriar(): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', 'criar'), 403);

    $this->resetForm();
    $this->etapas = [['nome' => '', 'prazo_dias_uteis' => null]];
    $this->modalAberto = true;
  }

  public function editar(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', 'editar'), 403);

    $fluxo = FluxoSuprimento::with('etapas')->findOrFail($id);
    $this->editandoId = $id;
    $this->nome = $fluxo->nome;
    $this->descricao = $fluxo->descricao ?? '';
    $this->ativo = $fluxo->ativo;
    $this->etapas = $fluxo->etapas
      ->map(fn ($etapa) => ['nome' => $etapa->nome, 'prazo_dias_uteis' => $etapa->prazo_dias_uteis])
      ->all();
    $this->modalAberto = true;
  }

  public function adicionarEtapa(): void
  {
    $this->etapas[] = ['nome' => '', 'prazo_dias_uteis' => null];
  }

  public function removerEtapa(int $indice): void
  {
    unset($this->etapas[$indice]);
    $this->etapas = array_values($this->etapas);
  }

  public function moverEtapaCima(int $indice): void
  {
    if ($indice === 0) {
      return;
    }

    [$this->etapas[$indice - 1], $this->etapas[$indice]] = [$this->etapas[$indice], $this->etapas[$indice - 1]];
  }

  public function moverEtapaBaixo(int $indice): void
  {
    if ($indice >= count($this->etapas) - 1) {
      return;
    }

    [$this->etapas[$indice + 1], $this->etapas[$indice]] = [$this->etapas[$indice], $this->etapas[$indice + 1]];
  }

  public function salvar(): void
  {
    abort_unless(
      Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', $this->editandoId ? 'editar' : 'criar'),
      403
    );

    $this->validate(
      [
        'nome' => 'required|string|max:150',
        'descricao' => 'nullable|string|max:1000',
        'etapas' => 'required|array|min:1',
        'etapas.*.nome' => 'required|string|max:150',
        'etapas.*.prazo_dias_uteis' => 'required|integer|min:0',
      ],
      [
        'nome.required' => 'O nome do fluxo é obrigatório.',
        'etapas.required' => 'Cadastre ao menos uma etapa.',
        'etapas.min' => 'Cadastre ao menos uma etapa.',
        'etapas.*.nome.required' => 'Informe o nome de todas as etapas.',
        'etapas.*.prazo_dias_uteis.required' => 'Informe o prazo (em dias úteis) de todas as etapas.',
      ]
    );

    $dadosFluxo = [
      'nome' => $this->nome,
      'descricao' => $this->descricao ?: null,
      'ativo' => $this->ativo,
    ];
    $etapasForm = $this->etapas;
    $editandoId = $this->editandoId;

    $this->transacaoSegura(function () use ($dadosFluxo, $etapasForm, $editandoId) {
      $fluxo = $editandoId
        ? tap(FluxoSuprimento::findOrFail($editandoId))->update($dadosFluxo)
        : FluxoSuprimento::create($dadosFluxo);

      // Substitui as etapas do fluxo — só afeta itens de suprimento
      // FUTUROS (as etapas já congeladas de itens existentes são uma
      // cópia imutável em itens_suprimento_etapas, não são tocadas aqui).
      $fluxo->etapas()->delete();
      foreach ($etapasForm as $indice => $etapa) {
        $fluxo->etapas()->create([
          'ordem' => $indice + 1,
          'nome' => $etapa['nome'],
          'prazo_dias_uteis' => $etapa['prazo_dias_uteis'],
        ]);
      }
    });

    if ($this->transacaoSeguraFalhou()) {
      return;
    }

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->fluxos);
    $this->dispatch('show-toast', message: 'Fluxo de suprimento salvo com sucesso.');
  }

  public function excluir(string $id): void
  {
    abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', 'excluir'), 403);

    FluxoSuprimento::findOrFail($id)->delete();
    unset($this->fluxos);
    $this->dispatch('show-toast', message: 'Fluxo de suprimento removido.');
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->nome = '';
    $this->descricao = '';
    $this->ativo = true;
    $this->etapas = [];
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
                    <h4 class="mb-1 mt-3">Tipos de Fluxo de Suprimento</h4>
                    <p class="text-muted">Cada fluxo define a sequência de etapas e o prazo (em dias úteis) de cada uma, usado pra calcular as datas do Mapa de Suprimentos de trás pra frente, a partir da necessidade da obra.</p>
                </div>
                <div class="d-flex align-content-center flex-wrap gap-2">
                    @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', 'criar'))
                    <button class="btn btn-primary" wire:click="abrirCriar">
                        <i class="bx bx-plus me-1"></i>Novo Fluxo
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($this->fluxos->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bx bx-git-branch fs-1 text-muted d-block mb-2"></i>
            <p class="text-muted mb-3">Nenhum fluxo de suprimento cadastrado ainda.</p>
            <p class="text-muted small mb-3">
                Exemplo: "Padrão - Compra de Materiais", "Padrão - Contratação de Serviços"
            </p>
            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', 'criar'))
            <button class="btn btn-primary" wire:click="abrirCriar">
                <i class="bx bx-plus me-1"></i>Criar primeiro fluxo
            </button>
            @endif
        </div>
    </div>
    @else
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nome</th>
                        <th class="text-center">Etapas</th>
                        <th class="text-center">Itens vinculados</th>
                        <th class="text-center">Status</th>
                        <th style="width:15%"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->fluxos as $fluxo)
                    <tr style="height: 60px;">
                        <td>
                            {{ $fluxo->nome }}
                            @if($fluxo->descricao)
                                <br><small class="text-muted">{{ $fluxo->descricao }}</small>
                            @endif
                        </td>
                        <td class="text-center">{{ $fluxo->etapas->count() }}</td>
                        <td class="text-center">{{ $fluxo->itens_suprimento_count }}</td>
                        <td class="text-center">
                            <span class="badge bg-label-{{ $fluxo->ativo ? 'success' : 'secondary' }}">
                                {{ $fluxo->ativo ? 'Ativo' : 'Inativo' }}
                            </span>
                        </td>
                        <td class="text-end pe-3">
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', 'editar'))
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 me-1"
                                    wire:click="editar('{{ $fluxo->id }}')">
                                <i class="bx bx-pencil"></i>
                            </button>
                            @endif
                            @if(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.fluxos_suprimento', 'excluir'))
                            <button class="btn btn-xs btn-outline-danger py-0 px-1"
                                    wire:click="excluir('{{ $fluxo->id }}')"
                                    wire:confirm="Remover '{{ $fluxo->nome }}'? Itens de suprimento já criados não são afetados.">
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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ $editandoId ? 'Editar Fluxo de Suprimento' : 'Novo Fluxo de Suprimento' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control @error('nome') is-invalid @enderror"
                               wire:model="nome"
                               placeholder="ex: Padrão - Compra de Materiais">
                        @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control @error('descricao') is-invalid @enderror" rows="2" wire:model="descricao"></textarea>
                        @error('descricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="fluxoAtivo" wire:model="ativo">
                        <label class="form-check-label" for="fluxoAtivo">Ativo</label>
                    </div>

                    <label class="form-label d-flex align-items-center justify-content-between">
                        <span>Etapas <span class="text-danger">*</span></span>
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="adicionarEtapa">
                            <i class="bx bx-plus me-1"></i>Adicionar etapa
                        </button>
                    </label>
                    @error('etapas')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                    @foreach($etapas as $indice => $etapa)
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <div class="d-flex flex-column">
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1 mb-1"
                                    wire:click="moverEtapaCima({{ $indice }})"
                                    @disabled($indice === 0)>↑</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1"
                                    wire:click="moverEtapaBaixo({{ $indice }})"
                                    @disabled($indice === count($etapas) - 1)>↓</button>
                        </div>
                        <div class="flex-grow-1">
                            <input type="text" class="form-control form-control-sm @error("etapas.$indice.nome") is-invalid @enderror"
                                   wire:model="etapas.{{ $indice }}.nome"
                                   placeholder="Nome da etapa (ex: Cotação)">
                            @error("etapas.$indice.nome")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div style="width: 160px;">
                            <div class="input-group input-group-sm">
                                <input type="number" min="0" class="form-control @error("etapas.$indice.prazo_dias_uteis") is-invalid @enderror"
                                       wire:model="etapas.{{ $indice }}.prazo_dias_uteis"
                                       placeholder="Prazo">
                                <span class="input-group-text">dias úteis</span>
                            </div>
                            @error("etapas.$indice.prazo_dias_uteis")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1"
                                wire:click="removerEtapa({{ $indice }})"
                                title="Remover etapa">
                            <i class="bx bx-trash"></i>
                        </button>
                    </div>
                    @endforeach
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
