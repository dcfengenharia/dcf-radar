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
        .pronta-sim { color: #1c7c3c; font-weight: bold; }
        .pronta-nao { color: #c0392b; font-weight: bold; }
    </style>
</head>
<body>
    <h2>Lookahead Lean — {{ $obra->name }}</h2>
    <p class="subtitulo">
        Janela: {{ $janelaDias }} dias · Fonte: {{ $fonteData === 'baseline' ? 'Linha de Base' : 'Tendência' }}
    </p>

    <table>
        <thead>
            <tr>
                <th>Tarefa</th>
                <th>Disciplina</th>
                <th>Frente de Trabalho</th>
                <th>Início LB</th>
                <th>Término LB</th>
                <th>Início</th>
                <th>Término</th>
                <th>Avanço</th>
                <th>Restr. Bloq.</th>
                <th>Restr. Não Bloq.</th>
                <th>Prontidão</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($linhas as $linha)
            @php $at = $linha['atividade']; @endphp
            <tr>
                <td>{{ $at->nome }}</td>
                <td>{{ $at->disciplina?->nome ?? '—' }}</td>
                <td>{{ $at->frenteTrabalho?->nome ?? '—' }}</td>
                <td>{{ $linha['inicioBaseline']?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $linha['terminoBaseline']?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $linha['inicioTendencia']?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $linha['terminoTendencia']?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $at->percentual_concluido !== null ? number_format((float) $at->percentual_concluido, 0) . '%' : '—' }}</td>
                <td>{{ $linha['restricoesBloq'] }}</td>
                <td>{{ $linha['restricoesNaoBloq'] }}</td>
                <td>{{ $linha['totalItens'] > 0 ? "{$linha['itensOk']}/{$linha['totalItens']}" : '—' }}</td>
                <td class="{{ $linha['pronta'] ? 'pronta-sim' : 'pronta-nao' }}">{{ $linha['pronta'] ? 'Pronta' : 'Não pronta' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
