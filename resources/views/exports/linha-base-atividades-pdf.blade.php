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
        .critico-sim { color: #c0392b; font-weight: bold; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <h2>Linha de Base — {{ $lb->nome }} · {{ $obra->name }}</h2>
    <p class="subtitulo">
        Importação de {{ $lb->importacao?->importado_em?->format('d/m/Y H:i') }}
    </p>

    <table>
        <thead>
            <tr>
                <th style="width: 7%">ID</th>
                <th style="width: 30%">Nome da Tarefa</th>
                <th style="width: 12%">Disciplina</th>
                <th class="text-center" style="width: 8%">Duração LB</th>
                <th class="text-center" style="width: 8%">Início LB</th>
                <th class="text-center" style="width: 8%">Término LB</th>
                <th class="text-center" style="width: 10%">Caminho Crítico</th>
                <th class="text-center" style="width: 8%">Restrições</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($linhas as $linha)
            @if ($linha['tipo'] === 'pacote')
            <tr class="linha-pacote">
                <td colspan="8" style="padding-left: {{ $linha['nivel'] * 12 }}px">
                    {{ $linha['pacote']->codigo }} · {{ $linha['pacote']->nome }}
                </td>
            </tr>
            @else
            @php
                $row = $linha['row'];
                $at = $row['atividade'];
            @endphp
            <tr>
                <td>{{ $at->external_uid ?? '—' }}</td>
                <td style="padding-left: 10px">{{ $at->nome }}</td>
                <td>{{ $at->disciplina?->nome ?? '—' }}</td>
                <td class="text-center">{{ $row['duracaoBaseline'] ?? '—' }}</td>
                <td class="text-center">{{ $row['inicioBaseline']?->format('d/m/Y') ?? '—' }}</td>
                <td class="text-center">{{ $row['terminoBaseline']?->format('d/m/Y') ?? '—' }}</td>
                <td class="text-center {{ $at->caminho_critico ? 'critico-sim' : '' }}">{{ $at->caminho_critico ? 'Sim' : 'Não' }}</td>
                <td class="text-center">{{ $at->restricoes_count }}</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>
</body>
</html>
