@php
    $colunasData ??= [];
    $mostrarConcluido ??= true;
    $percentual = $mostrarConcluido && $indicador ? $indicador->percentualConcluido() : null;
    $corBarra = $percentual === null ? 'secondary' : ($percentual >= 80 ? 'success' : ($percentual >= 50 ? 'warning' : 'danger'));
@endphp

<div class="col-md-4 mb-3">
    <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <h6 class="mb-0"><i class="bx {{ $icone }} me-1"></i>{{ $titulo }}</h6>
            @if($indicador && $indicador->total_previsto > 0)
            <span class="badge bg-label-secondary">
                {{ $indicador->total_previsto }} previsto{{ $indicador->total_previsto === 1 ? '' : 's' }}
            </span>
            @endif
        </div>
        <div class="card-body">
            @if(!$indicador || $indicador->total_previsto === 0)
                <p class="text-muted small mb-0">{{ $mensagemVazia }}</p>
            @else
                @if($mostrarConcluido && $indicador->total_concluido !== null)
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="progress flex-grow-1" style="height: 6px;">
                        <div class="progress-bar bg-{{ $corBarra }}" style="width: {{ $percentual ?? 0 }}%"></div>
                    </div>
                    <small class="text-nowrap text-muted">
                        {{ $indicador->total_concluido }}/{{ $indicador->total_previsto }}
                        ({{ number_format($percentual ?? 0, 0) }}%)
                    </small>
                </div>
                @endif

                @if(!empty($indicador->detalhes))
                <div class="table-responsive" style="max-height: 240px; overflow-y: auto;">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                @foreach($colunas as $chave => $rotulo)
                                <th class="small text-muted text-nowrap">{{ $rotulo }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($indicador->detalhes as $item)
                            <tr>
                                @foreach($colunas as $chave => $rotulo)
                                <td class="small">
                                    @php $valor = $item[$chave] ?? null; @endphp
                                    @if($valor === null || $valor === '')
                                        —
                                    @elseif(in_array($chave, $colunasData))
                                        {{ \Illuminate\Support\Carbon::parse($valor)->format('d/m/y') }}
                                    @else
                                        {{ $valor }}
                                    @endif
                                </td>
                                @endforeach
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            @endif
        </div>
    </div>
</div>
