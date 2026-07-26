<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h2 { margin-bottom: 2px; }
        h4 { margin-bottom: 4px; margin-top: 24px; }
        .subtitulo { color: #666; margin-top: 0; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #222; color: #fff; }
        .nivel-pai { background: #f2f2f2; font-weight: bold; }
        .desvio-neg { color: #c0392b; }
        .desvio-pos { color: #1c7c3c; }
        .pontos-atencao li { margin-bottom: 4px; }
        .fotos { display: block; }
        .foto { display: inline-block; width: 47%; margin: 1%; vertical-align: top; }
        .foto img { width: 100%; }
        .foto .legenda { font-size: 10px; color: #555; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <h2>Report Semanal — {{ $report->obra->name }}</h2>
    <p class="subtitulo">
        {{ $report->titulo ?: 'Semana de ' . $report->periodo_referencia->format('d/m/Y') }}
        @if($report->data_status)
        — status em {{ $report->data_status->format('d/m/Y') }}
        @endif
        — {{ $report->status->label() }}
    </p>

    @foreach($report->curvas as $curva)
    <h4>{{ $curva->titulo_exibicao }}</h4>
    <p class="subtitulo">
        Término Linha de Base: {{ $curva->termino_linha_base?->format('d/m/Y') ?? '—' }}
        &nbsp;|&nbsp;
        Término Tendência: {{ $curva->termino_tendencia?->format('d/m/Y') ?? '—' }}
    </p>

    <table>
        <thead>
            <tr>
                <th>Pacote</th>
                <th>Peso</th>
                <th>% Previsto</th>
                <th>% Real</th>
                <th>% Desvio</th>
                <th>% Impacto</th>
            </tr>
        </thead>
        <tbody>
            @foreach($curva->desvios as $desvio)
            <tr class="{{ $desvio->eh_nivel_pai ? 'nivel-pai' : '' }}">
                <td>{{ $desvio->titulo_exibicao }}</td>
                <td>{{ number_format($desvio->peso * 100, 1, ',', '.') }}%</td>
                <td>{{ number_format($desvio->percentual_previsto, 1, ',', '.') }}%</td>
                <td>{{ number_format($desvio->percentual_real, 1, ',', '.') }}%</td>
                <td class="{{ $desvio->percentual_desvio < 0 ? 'desvio-neg' : 'desvio-pos' }}">
                    {{ number_format($desvio->percentual_desvio, 1, ',', '.') }}%
                </td>
                <td class="{{ $desvio->percentual_impacto < 0 ? 'desvio-neg' : 'desvio-pos' }}">
                    {{ number_format($desvio->percentual_impacto, 1, ',', '.') }}%
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if($curva->pontosAtencao->isNotEmpty())
    <strong>Pontos de Atenção:</strong>
    <ul class="pontos-atencao">
        @foreach($curva->pontosAtencao as $ponto)
        <li>@if($ponto->categoria)<strong>[{{ $ponto->categoria }}]</strong>@endif {{ $ponto->texto }}</li>
        @endforeach
    </ul>
    @endif
    @endforeach

    @if($report->fotos->isNotEmpty())
    <div class="page-break"></div>
    <h4>Relatório Fotográfico</h4>
    <div class="fotos">
        @foreach($report->fotos as $foto)
        @if($foto->caminhoAbsoluto)
        <div class="foto">
            <img src="{{ $foto->caminhoAbsoluto }}">
            @if($foto->legenda)
            <div class="legenda">{{ $foto->legenda }}</div>
            @endif
        </div>
        @endif
        @endforeach
    </div>
    @endif
</body>
</html>
