<?php

use App\Enums\StatusAssinatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
  public Tenant $tenant;

  public bool $modalAssinaturaAberto = false;
  public ?string $planoId = null;
  public string $status = '';
  public ?string $inicio = null;
  public ?string $fimTrial = null;
  public ?string $motivoCancelamento = null;

  public function mount(Tenant $tenant): void
  {
    $this->tenant = $tenant;
  }

  #[Computed]
  public function planosAtivos(): Collection
  {
    return Plano::where('ativo', true)->orderBy('nome')->get();
  }

  #[Computed]
  public function statusDisponiveis(): array
  {
    return StatusAssinatura::cases();
  }

  #[Computed]
  public function assinaturaAtual(): ?\App\Models\Assinatura
  {
    return $this->tenant->assinaturaAtual();
  }

  #[Computed]
  public function historicoAssinaturas(): Collection
  {
    return $this->tenant->assinaturas()->with('plano')->orderByDesc('inicio')->get();
  }

  #[Computed]
  public function usuarios(): Collection
  {
    return $this->tenant->users;
  }

  #[Computed]
  public function totalObras(): int
  {
    return TenantContext::actingAs($this->tenant, fn () => Work::count());
  }

  public function abrirModalAssinatura(): void
  {
    $atual = $this->assinaturaAtual;
    $this->planoId = $atual?->plano_id;
    $this->status = $atual?->status->value ?? StatusAssinatura::Trial->value;
    $this->inicio = now()->toDateString();
    $this->fimTrial = now()->addDays(14)->toDateString();
    $this->motivoCancelamento = null;
    $this->resetValidation();
    $this->modalAssinaturaAberto = true;
  }

  public function salvarAssinatura(): void
  {
    $this->validate(
      [
        'planoId' => 'required|exists:planos,id',
        'status' => 'required|in:' . implode(',', array_column(StatusAssinatura::cases(), 'value')),
        'inicio' => 'required|date',
        'motivoCancelamento' => $this->status === StatusAssinatura::Cancelada->value ? 'required|string|max:255' : 'nullable|string|max:255',
      ],
      [],
      ['planoId' => 'plano', 'motivoCancelamento' => 'motivo do cancelamento']
    );

    $this->tenant->assinaturas()->create([
      'plano_id' => $this->planoId,
      'status' => $this->status,
      'inicio' => $this->inicio,
      'fim_trial' => $this->status === StatusAssinatura::Trial->value ? $this->fimTrial : null,
      'cancelada_em' => $this->status === StatusAssinatura::Cancelada->value ? now() : null,
      'motivo_cancelamento' => $this->status === StatusAssinatura::Cancelada->value ? $this->motivoCancelamento : null,
    ]);

    $this->modalAssinaturaAberto = false;
    unset($this->assinaturaAtual, $this->historicoAssinaturas);
    $this->dispatch('show-toast', message: 'Assinatura registrada.');
  }

  /**
   * Autoatendimento pra marcar/desmarcar esta conta como a operadora
   * da própria plataforma — nunca fazemos essa alteração por conta
   * própria em dado real de tenant, quem decide qual conta é a sua é
   * sempre o admin, com um clique aqui.
   */
  public function alternarContaOperadora(): void
  {
    $this->tenant->update(['eh_conta_operadora' => ! $this->tenant->eh_conta_operadora]);

    $mensagem = $this->tenant->eh_conta_operadora
      ? 'Marcada como Conta Operadora — sai das métricas de clientes do dashboard.'
      : 'Removida a marcação de Conta Operadora.';

    $this->dispatch('show-toast', message: $mensagem);
  }
};
?>

<div>
    @if ($tenant->trashed())
        <div class="alert alert-secondary">
            <i class="bx bx-archive"></i> Esta conta foi excluída (soft delete) em {{ $tenant->deleted_at->format('d/m/Y H:i') }}.
        </div>
    @endif

    <div class="row g-4">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        Dados da Conta
                        @if ($tenant->eh_conta_operadora)
                            <span class="badge bg-label-dark ms-1">Conta Operadora</span>
                        @endif
                    </h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="alternarContaOperadora" wire:confirm="{{ $tenant->eh_conta_operadora ? 'Remover a marcação de Conta Operadora?' : 'Marcar esta conta como a Conta Operadora da plataforma? Ela sai das métricas de clientes e nunca ganha trial automático.' }}">
                            <i class="bx bx-crown"></i> {{ $tenant->eh_conta_operadora ? 'Desmarcar' : 'Marcar' }} Conta Operadora
                        </button>
                        <form method="POST" action="{{ route('admin.tenants.impersonar', $tenant) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bx bx-user-voice"></i> Entrar como
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>Nome:</strong> {{ $tenant->name }}</p>
                    <p class="mb-1"><strong>Criado em:</strong> {{ $tenant->created_at->format('d/m/Y') }}</p>
                    <p class="mb-0"><strong>Usuários:</strong> {{ $this->usuarios->count() }} — <strong>Obras:</strong> {{ $this->totalObras }}</p>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Histórico de Assinaturas</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Início</th>
                                <th>Plano</th>
                                <th>Status</th>
                                <th>Origem</th>
                                <th>Cancelada em</th>
                                <th>Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->historicoAssinaturas as $assinatura)
                                <tr>
                                    <td>{{ $assinatura->inicio->format('d/m/Y') }}</td>
                                    <td>{{ $assinatura->plano->nome }}</td>
                                    <td>
                                        <span class="badge bg-label-{{ $assinatura->status->corBadge() }}">
                                            {{ $assinatura->status->label() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($assinatura->origem === 'mercadopago')
                                            <span class="badge bg-label-info">Mercado Pago</span>
                                            @if ($assinatura->metodo_pagamento)
                                                <span class="badge bg-label-secondary text-capitalize">{{ $assinatura->metodo_pagamento }}</span>
                                            @endif
                                        @elseif ($assinatura->origem === 'sistema')
                                            <span class="badge bg-label-secondary">Trial automático</span>
                                        @else
                                            <span class="badge bg-label-dark">Manual (admin)</span>
                                        @endif
                                    </td>
                                    <td>{{ $assinatura->cancelada_em?->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td>{{ $assinatura->motivo_cancelamento ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">Nenhuma assinatura registrada ainda.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-body">
                    <button class="btn btn-primary" wire:click="abrirModalAssinatura">
                        <i class="bx bx-plus me-1"></i> Atribuir Plano / Alterar Status
                    </button>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Usuários</h5>
                </div>
                <ul class="list-group list-group-flush">
                    @forelse ($this->usuarios as $usuario)
                        <li class="list-group-item">
                            {{ $usuario->first_name }} {{ $usuario->last_name }}
                            <br><small class="text-muted">{{ $usuario->email }}</small>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Nenhum usuário cadastrado.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    {{-- Modal: Atribuir Plano / Alterar Status --}}
    @if ($modalAssinaturaAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Atribuir Plano / Alterar Status</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAssinaturaAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Cada atribuição cria uma nova linha no histórico — nada é sobrescrito.</p>

                    <div class="mb-3">
                        <label class="form-label">Plano <span class="text-danger">*</span></label>
                        <select class="form-select @error('planoId') is-invalid @enderror" wire:model="planoId">
                            <option value="">— Selecione —</option>
                            @foreach ($this->planosAtivos as $plano)
                                <option value="{{ $plano->id }}">{{ $plano->nome }} (R$ {{ number_format($plano->preco_mensal, 2, ',', '.') }}/mês)</option>
                            @endforeach
                        </select>
                        @error('planoId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select class="form-select @error('status') is-invalid @enderror" wire:model.live="status">
                            @foreach ($this->statusDisponiveis as $statusOpcao)
                                <option value="{{ $statusOpcao->value }}">{{ $statusOpcao->label() }}</option>
                            @endforeach
                        </select>
                        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Início <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('inicio') is-invalid @enderror" wire:model="inicio">
                        @error('inicio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @if ($status === \App\Enums\StatusAssinatura::Trial->value)
                    <div class="mb-3">
                        <label class="form-label">Fim do Trial</label>
                        <input type="date" class="form-control" wire:model="fimTrial">
                    </div>
                    @endif

                    @if ($status === \App\Enums\StatusAssinatura::Cancelada->value)
                    <div class="mb-0">
                        <label class="form-label">Motivo do Cancelamento <span class="text-danger">*</span></label>
                        <textarea class="form-control @error('motivoCancelamento') is-invalid @enderror" wire:model="motivoCancelamento" rows="2"></textarea>
                        @error('motivoCancelamento')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="$set('modalAssinaturaAberto', false)">Cancelar</button>
                    <button class="btn btn-primary" wire:click="salvarAssinatura">
                        <i class="bx bx-check me-1"></i> Salvar
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
