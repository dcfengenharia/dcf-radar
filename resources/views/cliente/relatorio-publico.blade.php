@extends('layouts.blankLayout')

@section('title', 'Relatório — ' . $report->obra->name)

@section('content')
<div class="container py-5" style="max-width: 960px;">
    <div class="text-center mb-4">
        <h4 class="mb-0">{{ $report->obra->name }}</h4>
        <div class="text-muted">
            {{ $report->titulo ?: 'Report ' . $report->periodo_referencia->format('d/m/Y') }}
        </div>
    </div>

    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-4">
        <i class="bx bx-check-circle"></i>
        <small>
            Semana de {{ $report->periodo_referencia->format('d/m/Y') }}
            @if($report->data_status)
            — status em {{ $report->data_status->format('d/m/Y') }}
            @endif
            @if($report->emitido_em)
            — emitido em {{ $report->emitido_em->format('d/m/Y H:i') }}
            @endif
            — somente leitura, sem necessidade de login.
        </small>
    </div>

    @foreach($report->curvas as $curva)
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">{{ $curva->titulo_exibicao ?: 'Obra inteira' }}</h5>

            @if($curva->desvios->isNotEmpty())
            <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Peso</th>
                            <th class="text-end">% Previsto</th>
                            <th class="text-end">% Real</th>
                            <th class="text-end">% Desvio</th>
                            <th class="text-end">% Impacto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($curva->desvios as $desvio)
                        <tr class="{{ $desvio->eh_nivel_pai ? 'fw-semibold table-light' : '' }}">
                            <td>{{ $desvio->titulo_exibicao }}</td>
                            <td class="text-end">{{ number_format($desvio->peso * 100, 1) }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_previsto, 1) }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_real, 1) }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_desvio, 1) }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_impacto, 1) }}%</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            @php
                $mensal = \App\Support\ReportCurvaSerializer::serieParaGrafico($curva, \App\Enums\GranularidadePeriodo::Mensal);
                $semanal = \App\Support\ReportCurvaSerializer::serieParaGrafico($curva, \App\Enums\GranularidadePeriodo::Semanal);
            @endphp

            @if(!empty($mensal['tabela']))
            <h6 class="text-muted">Avanço mensal</h6>
            @include('pages.radar._partials.relatorio-tabela-curva', ['tabela' => $mensal['tabela']])
            @endif

            @if(!empty($semanal['tabela']))
            <h6 class="text-muted">Avanço semanal (últimas 4 semanas)</h6>
            @include('pages.radar._partials.relatorio-tabela-curva', ['tabela' => $semanal['tabela'], 'comAderencia' => true])
            @endif

            @if($curva->pontosAtencao->isNotEmpty())
            <h6 class="text-muted mt-3">Pontos de atenção</h6>
            <ul class="mb-0">
                @foreach($curva->pontosAtencao as $ponto)
                <li>
                    @if($ponto->categoria)<span class="badge bg-label-secondary me-1">{{ $ponto->categoria }}</span>@endif
                    {{ $ponto->texto }}
                </li>
                @endforeach
            </ul>
            @endif
        </div>
    </div>
    @endforeach

    @if($report->fotos->isNotEmpty())
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Fotos</h5>
            <div class="row g-3">
                @foreach($report->fotos as $foto)
                @if($foto->url)
                <div class="col-6 col-md-4">
                    <img src="{{ $foto->url }}" class="img-fluid rounded" alt="{{ $foto->legenda }}">
                    @if($foto->legenda)
                    <small class="text-muted d-block mt-1">{{ $foto->legenda }}</small>
                    @endif
                </div>
                @endif
                @endforeach
            </div>
        </div>
    </div>
    @endif

    <div class="text-center text-muted small mt-4">
        Relatório gerado por {{ config('app.name') }} — este link é somente leitura e expira automaticamente.
    </div>
</div>
@endsection
