<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: a4 landscape; margin: 10mm 8mm; }
        body { font-family: sans-serif; font-size: 8px; }
        h2 { margin-bottom: 2px; font-size: 14px; }
        .subtitulo { color: #666; margin-top: 0; margin-bottom: 10px; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #222; color: #fff; font-size: 8px; }
        .linha-pacote { background: #e9e9e9; font-weight: bold; }
        .pronta-sim { color: #1c7c3c; font-weight: bold; }
        .pronta-nao { color: #c0392b; font-weight: bold; }
        .text-center { text-align: center; }
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
                <th style="width: 26%">Tarefa</th>
                <th style="width: 8%">Disciplina</th>
                <th style="width: 12%">Frente de Trabalho</th>
                <th class="text-center" style="width: 6%">Início LB</th>
                <th class="text-center" style="width: 6%">Término LB</th>
                <th class="text-center" style="width: 6%">Início</th>
                <th class="text-center" style="width: 6%">Término</th>
                <th class="text-center" style="width: 4%">%</th>
                <th class="text-center" style="width: 6%">Restr. Bloq.</th>
                <th class="text-center" style="width: 6%">Restr. Não Bloq.</th>
                <th class="text-center" style="width: 7%">Prontidão</th>
                <th class="text-center" style="width: 7%">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($linhas as $linha)
            @if ($linha['tipo'] === 'pacote')
            <tr class="linha-pacote">
                <td colspan="12" style="padding-left: {{ $linha['nivel'] * 12 }}px">
                    {{ $linha['pacote']->codigo }} · {{ $linha['pacote']->nome }}
                </td>
            </tr>
            @else
            @php
                $row = $linha['row'];
                $at = $row['atividade'];
            @endphp
            <tr>
                <td style="padding-left: 10px">{{ $at->nome }}</td>
                <td>{{ $at->disciplina?->nome ?? '—' }}</td>
                <td>{{ $at->frenteTrabalho?->nome ?? '—' }}</td>
                <td class="text-center">{{ $row['inicioBaseline']?->format('d/m/Y') ?? '—' }}</td>
                <td class="text-center">{{ $row['terminoBaseline']?->format('d/m/Y') ?? '—' }}</td>
                <td class="text-center">{{ $temImportacaoAvanco ? ($row['inicioTendencia']?->format('d/m/Y') ?? '—') : 'N/A' }}</td>
                <td class="text-center">{{ $temImportacaoAvanco ? ($row['terminoTendencia']?->format('d/m/Y') ?? '—') : 'N/A' }}</td>
                <td class="text-center">{{ $row['percentualRealizado'] !== null ? number_format($row['percentualRealizado'], 0) . '%' : '—' }}</td>
                <td class="text-center">{{ $row['restricoesBloq'] }}</td>
                <td class="text-center">{{ $row['restricoesNaoBloq'] }}</td>
                <td class="text-center">{{ $row['totalItens'] > 0 ? "{$row['itensOk']}/{$row['totalItens']}" : '—' }}</td>
                <td class="text-center {{ $row['pronta'] ? 'pronta-sim' : 'pronta-nao' }}">{{ $row['pronta'] ? 'Pronta' : 'Não pronta' }}</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>
</body>
</html>
