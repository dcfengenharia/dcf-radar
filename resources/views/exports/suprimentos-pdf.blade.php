<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; }
        h2 { margin-bottom: 2px; }
        .subtitulo { color: #666; margin-top: 0; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #222; color: #fff; }
        .desvio-atraso { color: #c0392b; font-weight: bold; }
        .desvio-folga { color: #1c7c3c; }
    </style>
</head>
<body>
    <h2>Mapa de Suprimentos — {{ $obra->name }}</h2>
    <p class="subtitulo">{{ $linhas->count() }} item(ns)</p>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Fluxo</th>
                <th>Fornecedor</th>
                <th>Status</th>
                <th>Necessidade</th>
                <th>Previsto</th>
                <th>Tendência</th>
                <th>Desvio</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($linhas as $linha)
            @php $item = $linha['item']; @endphp
            <tr>
                <td>{{ $item->nome }}@if($item->codigo) ({{ $item->codigo }})@endif</td>
                <td>{{ $item->fluxo?->nome ?? '—' }}</td>
                <td>{{ $item->fornecedor?->nome ?? '—' }}</td>
                <td>{{ $item->status->label() }}</td>
                <td>{{ $linha['necessidade']?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $linha['previstoFinal']?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $linha['tendenciaFinal']?->format('d/m/Y') ?? '—' }}</td>
                <td class="{{ ($linha['desvioDias'] ?? 0) > 0 ? 'desvio-atraso' : (($linha['desvioDias'] ?? 0) < 0 ? 'desvio-folga' : '') }}">
                    {{ $linha['desvioDias'] !== null ? ($linha['desvioDias'] > 0 ? '+' : '') . $linha['desvioDias'] . 'd' : '—' }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
