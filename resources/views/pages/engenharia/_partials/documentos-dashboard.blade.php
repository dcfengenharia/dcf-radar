{{--
    Aba "Dashboard" da Lista de Documentos — curva S de emissões (Previsto
    x Realizado, mensal e semanal com zoom por mês), gráfico por disciplina,
    por status e extras (aging de atraso, top reprogramados).

    IDs de canvas são ESTÁVEIS (não incorporam obraId/mês) — os gráficos são
    montados/atualizados via listener persistente ($wire.on('dashboard-dados-
    atualizados', ...) no @script principal de ⚡documentos-engenharia.blade.php),
    que destrói e recria a instância Chart.js na mesma id a cada evento (nunca
    depende do Livewire trocar o nó do DOM). Isso é necessário porque os
    containers ficam em wire:ignore (pra Livewire nunca tocar o que o Chart.js
    gerencia) — e wire:ignore também congela pra sempre qualquer id dinâmico
    embutido no elemento, então um id tipo chart-semanal-{obraId}-{mes} nunca
    seria atualizado pelo morph mesmo que o mês mude (bug já visto e corrigido
    nesta sessão). Containers com altura fixa em CSS (nunca só o atributo
    height do canvas) evitam o loop de redimensionamento conhecido do Chart.js
    responsivo.
--}}
@php
    $curva = $this->curvaEmissoes;
    $semanal = $this->curvaSemanalFiltrada;
    $disciplina = $this->graficoDisciplina;
    $status = $this->graficoStatus;
    $aging = $this->agingAtraso;
    $topReprogramados = $this->topReprogramados;
@endphp

@if($curva['total'] === 0)
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="bx bx-bar-chart-alt-2 fs-1 d-block mb-2"></i>
        Nenhum documento cadastrado nesta obra ainda — o dashboard aparece assim que houver dados.
    </div>
</div>
@else

{{-- Curva S mensal --}}
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">Curva S de Emissões — Mensal</h6>
    </div>
    <div class="card-body">
        <div wire:ignore style="height: 320px;">
            <canvas id="chart-mensal"></canvas>
        </div>
    </div>
</div>

{{-- Curva S semanal, com zoom por mês --}}
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="mb-0">Curva S de Emissões — Semanal</h6>
        <select class="form-select form-select-sm" style="width:auto" wire:model.live="mesSelecionadoDashboard">
            @foreach($curva['mesesDisponiveis'] as $mes)
            <option value="{{ $mes }}">{{ \Carbon\Carbon::parse($mes . '-01')->translatedFormat('F/Y') }}</option>
            @endforeach
        </select>
    </div>
    <div class="card-body">
        @if(empty($semanal['labels']))
        <p class="text-muted text-center py-4 mb-0">Nenhuma semana com dado no mês escolhido.</p>
        @else
        <div wire:ignore style="height: 320px;">
            <canvas id="chart-semanal"></canvas>
        </div>
        @endif
    </div>
</div>

{{-- Disciplina + Status --}}
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Documentos por Disciplina</h6></div>
            <div class="card-body">
                <div wire:ignore style="height: 280px;">
                    <canvas id="chart-disciplina"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Documentos por Status</h6></div>
            <div class="card-body">
                <div wire:ignore style="height: 280px;">
                    <canvas id="chart-status"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Aging de atraso + Top reprogramados --}}
<div class="row g-4">
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Aging dos Documentos Atrasados</h6></div>
            <div class="card-body">
                @if(empty($aging['labels']) || array_sum($aging['valores']) === 0)
                <p class="text-muted text-center py-4 mb-0">Nenhum documento atrasado — nada pra mostrar aqui.</p>
                @else
                <div wire:ignore style="height: 240px;">
                    <canvas id="chart-aging"></canvas>
                </div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Top 5 Mais Reprogramados</h6></div>
            <div class="card-body p-0">
                @if($topReprogramados->isEmpty())
                <p class="text-muted text-center py-4 mb-0">Nenhum documento foi reprogramado ainda.</p>
                @else
                <table class="table table-sm mb-0">
                    <tbody>
                        @foreach($topReprogramados as $doc)
                        <tr>
                            <td class="fw-semibold">{{ $doc->codigo }}</td>
                            <td class="small text-muted">{{ $doc->descricao }}</td>
                            <td class="text-end">
                                <span class="badge rounded-pill bg-danger">{{ $doc->reprogramacoes_count }}</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
    </div>
</div>

{{--
    O JS que desenha esses gráficos NÃO fica aqui — @script dentro de um
    @include não executa (Livewire só reconhece @script no template do
    componente Livewire raiz, não em sub-views incluídas; confirmado
    manualmente: o script sequer aparecia no DOM). A montagem dos
    Chart.js fica no bloco @script principal de
    ⚡documentos-engenharia.blade.php.
--}}
@endif
