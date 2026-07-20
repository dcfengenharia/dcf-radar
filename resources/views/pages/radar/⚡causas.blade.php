<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\CausaNaoCumprimento;
use App\Models\Work;

new class extends Component {

    public Work $obra;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
    }

    #[Computed]
    public function causas()
    {
        return CausaNaoCumprimento::with('atividade')
            ->whereHas('atividade', fn($q) => $q->where('obra_id', $this->obra->id))
            ->whereNull('deleted_at')
            ->get()
            ->groupBy(fn($c) => mb_strtolower(trim($c->descricao)))
            ->map(fn($group) => [
                'descricao' => $group->first()->descricao,
                'total'     => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();
    }

    #[Computed]
    public function total(): int
    {
        return $this->causas->sum('total');
    }
};
?>

<div>
    @if ($this->causas->count() > 0)
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Causas de Não Cumprimento — Pareto</h5>
                <small class="text-muted">{{ $this->total }} ocorrências registradas</small>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Causa</th>
                            <th class="text-center">Ocorrências</th>
                            <th style="width: 35%">Frequência acumulada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $acumulado = 0; @endphp
                        @foreach ($this->causas as $i => $causa)
                            @php
                                $acumulado += $causa['total'];
                                $percentual = $this->total > 0 ? round(($causa['total'] / $this->total) * 100) : 0;
                                $acumuladoPct = $this->total > 0 ? round(($acumulado / $this->total) * 100) : 0;
                                $cor = $acumuladoPct <= 80 ? 'danger' : 'warning';
                            @endphp
                            <tr>
                                <td class="text-muted">{{ $i + 1 }}</td>
                                <td>{{ $causa['descricao'] }}</td>
                                <td class="text-center fw-bold">{{ $causa['total'] }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <div class="progress-bar bg-{{ $cor }}"
                                                 style="width: {{ $acumuladoPct }}%"></div>
                                        </div>
                                        <small class="text-muted text-nowrap">{{ $percentual }}%</small>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="text-center py-5">
            <i class="bx bx-bar-chart-alt-2 display-3 text-muted"></i>
            <h5 class="fw-bold mt-3">Nenhuma causa registrada</h5>
            <p class="text-muted">Causas aparecem aqui quando atividades são marcadas como "Não Concluído" no Plano Semanal.</p>
        </div>
    @endif
</div>
