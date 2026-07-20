<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Restricao;
use App\Models\Work;

new class extends Component {

    public Work $obra;
    public ?string $celulaAtiva = null; // "P-I"

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
    }

    #[Computed]
    public function mapa(): array
    {
        $restricoes = Restricao::with('atividade')
            ->whereHas('atividade', fn($q) => $q->where('obra_id', $this->obra->id))
            ->whereIn('status', ['aberta', 'em_tratamento'])
            ->whereNotNull('probabilidade')
            ->whereNotNull('impacto')
            ->get();

        $mapa = [];
        foreach ($restricoes as $r) {
            $key = "{$r->probabilidade}-{$r->impacto}";
            $mapa[$key] = ($mapa[$key] ?? 0) + 1;
        }

        return $mapa;
    }

    #[Computed]
    public function restricoesCelula(): array
    {
        if (! $this->celulaAtiva) return [];

        [$p, $i] = explode('-', $this->celulaAtiva);

        return Restricao::with(['atividade'])
            ->whereHas('atividade', fn($q) => $q->where('obra_id', $this->obra->id))
            ->whereIn('status', ['aberta', 'em_tratamento'])
            ->where('probabilidade', $p)
            ->where('impacto', $i)
            ->get()
            ->toArray();
    }

    public function selecionarCelula(string $chave): void
    {
        $this->celulaAtiva = ($this->celulaAtiva === $chave) ? null : $chave;
        unset($this->restricoesCelula);
    }
};
?>

<div>
    <div class="row g-4">
        {{-- Matriz --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Matriz de Risco P×I — Restrições abertas</h5>
                    <small class="text-muted">Clique em uma célula para ver as restrições</small>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2 gap-2">
                        <small class="text-muted" style="width: 60px; text-align: right;">Prob. ↑</small>
                        <div class="flex-grow-1"></div>
                    </div>

                    {{-- Grid 10×10 (P = linhas, I = colunas) --}}
                    <div style="display: grid; grid-template-columns: 40px repeat(10, 1fr); gap: 3px;">
                        {{-- Cabeçalho de colunas: Impacto --}}
                        <div></div>
                        @for ($i = 1; $i <= 10; $i++)
                            <div class="text-center text-muted" style="font-size: 10px;">{{ $i }}</div>
                        @endfor

                        {{-- Linhas: Probabilidade (10 = topo) --}}
                        @for ($p = 10; $p >= 1; $p--)
                            <div class="d-flex align-items-center justify-content-end text-muted pe-1"
                                 style="font-size: 10px;">{{ $p }}</div>
                            @for ($i = 1; $i <= 10; $i++)
                                @php
                                    $key   = "{$p}-{$i}";
                                    $risco = $p * $i;
                                    $count = $this->mapa[$key] ?? 0;
                                    $ativa = $this->celulaAtiva === $key;

                                    $bg = $risco >= 50
                                        ? ($count > 0 ? '#dc3545' : '#f8d7da')
                                        : ($risco >= 25
                                            ? ($count > 0 ? '#ffc107' : '#fff3cd')
                                            : ($count > 0 ? '#198754' : '#d1e7dd'));

                                    $textColor = ($count > 0 && $risco >= 25 && $risco < 50)
                                        ? '#000'
                                        : ($count > 0 ? '#fff' : '#888');
                                @endphp
                                <div
                                    wire:click="selecionarCelula('{{ $key }}')"
                                    style="
                                        background: {{ $bg }};
                                        color: {{ $textColor }};
                                        height: 38px;
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        font-size: 12px;
                                        font-weight: {{ $count > 0 ? 'bold' : 'normal' }};
                                        border-radius: 4px;
                                        cursor: {{ $count > 0 ? 'pointer' : 'default' }};
                                        border: {{ $ativa ? '2px solid #0d6efd' : '1px solid transparent' }};
                                    "
                                    title="{{ $count > 0 ? "$count restrição(ões) em P=$p × I=$i" : "P=$p × I=$i (sem restrições)" }}"
                                >
                                    {{ $count > 0 ? $count : '' }}
                                </div>
                            @endfor
                        @endfor
                    </div>

                    {{-- Legenda --}}
                    <div class="d-flex gap-3 mt-3 flex-wrap">
                        <div class="d-flex align-items-center gap-1">
                            <div style="width:14px;height:14px;background:#dc3545;border-radius:3px;"></div>
                            <small class="text-muted">Alto risco (≥50)</small>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <div style="width:14px;height:14px;background:#ffc107;border-radius:3px;"></div>
                            <small class="text-muted">Médio (25–49)</small>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <div style="width:14px;height:14px;background:#198754;border-radius:3px;"></div>
                            <small class="text-muted">Baixo (&lt;25)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Painel lateral --}}
        <div class="col-lg-4">
            @if ($this->celulaAtiva && count($this->restricoesCelula) > 0)
                @php [$pa, $ia] = explode('-', $this->celulaAtiva); @endphp
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">P={{ $pa }} × I={{ $ia }}</h6>
                        <small class="text-muted">{{ count($this->restricoesCelula) }} restrição(ões)</small>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach ($this->restricoesCelula as $r)
                            <div class="list-group-item py-2">
                                <small class="text-muted d-block">{{ $r['atividade']['nome'] ?? '—' }}</small>
                                <span class="small">{{ \Illuminate\Support\Str::limit($r['descricao'], 80) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($this->celulaAtiva)
                <div class="alert alert-secondary">Nenhuma restrição nessa célula.</div>
            @else
                <div class="card border-dashed">
                    <div class="card-body text-center text-muted py-5">
                        <i class="bx bx-mouse-alt display-4"></i>
                        <p class="mt-2 mb-0 small">Clique em uma célula colorida para ver as restrições</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
