<?php

use App\Models\Assinatura;
use App\Models\Tenant;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
  #[Computed]
  public function totalTenants(): int
  {
    return Tenant::count();
  }

  /**
   * Uma linha por tenant — a assinatura vigente (mais recente por
   * `inicio`), agrupando a tabela inteira em memória. Nesta escala
   * (poucas dezenas/centenas de tenants) é simples e correto; se crescer
   * muito, trocar por uma subquery.
   */
  #[Computed]
  public function assinaturasAtuais(): \Illuminate\Support\Collection
  {
    return Assinatura::with('plano')->get()
      ->groupBy('tenant_id')
      ->map(fn ($grupo) => $grupo->sortByDesc('inicio')->first());
  }

  #[Computed]
  public function assinaturasAtivasCount(): int
  {
    return $this->assinaturasAtuais->filter(fn ($a) => $a->estaAtiva())->count();
  }

  #[Computed]
  public function assinaturasPorStatus(): \Illuminate\Support\Collection
  {
    return $this->assinaturasAtuais->groupBy(fn ($a) => $a->status->value)->map->count();
  }

  #[Computed]
  public function mrrAproximado(): float
  {
    return (float) $this->assinaturasAtuais
      ->filter(fn ($a) => $a->estaAtiva())
      ->sum(fn ($a) => (float) $a->plano->preco_mensal);
  }
};
?>

<div>
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Contas (Tenants)</p>
                    <h3 class="mb-0">{{ $this->totalTenants }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Assinaturas Ativas</p>
                    <h3 class="mb-0">{{ $this->assinaturasAtivasCount }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">MRR Aproximado</p>
                    <h3 class="mb-0">R$ {{ number_format($this->mrrAproximado, 2, ',', '.') }}</h3>
                    <small class="text-muted">Estimativa administrativa — não é cobrança real.</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="text-muted mb-2">Por Status</p>
                    @forelse ($this->assinaturasPorStatus as $status => $qtd)
                        @php
                            $statusEnum = \App\Enums\StatusAssinatura::from($status);
                        @endphp
                        <span class="badge bg-label-{{ $statusEnum->corBadge() }} me-1 mb-1">
                            {{ $statusEnum->label() }}: {{ $qtd }}
                        </span>
                    @empty
                        <span class="text-muted small">Nenhuma assinatura cadastrada.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('admin.tenants.index') }}" class="btn btn-primary">
            <i class="bx bx-buildings me-1"></i> Gerenciar Contas
        </a>
        <a href="{{ route('admin.planos.index') }}" class="btn btn-outline-primary">
            <i class="bx bx-package me-1"></i> Gerenciar Planos
        </a>
    </div>
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
