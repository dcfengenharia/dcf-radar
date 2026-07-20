<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h2 { margin-bottom: 2px; }
        .subtitulo { color: #666; margin-top: 0; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
        th { background: #222; color: #fff; }
        .pronta-sim { color: #1c7c3c; font-weight: bold; }
        .pronta-nao { color: #c0392b; font-weight: bold; }
    </style>
</head>
<body>
    <h2>Plano Semanal — {{ $obra->name }}</h2>
    <p class="subtitulo">Semana de {{ $semanaInicio }} até {{ $semanaFim }}</p>

    <table>
        <thead>
            <tr>
                <th>Atividade</th>
                <th>Frente de Trabalho</th>
                <th>Disciplina</th>
                <th>Responsável</th>
                <th>Início</th>
                <th>Término</th>
                <th>Pronta</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($atividades as $at)
            <tr>
                <td>{{ $at->nome }}</td>
                <td>{{ $at->pacoteTrabalho?->nome ?? '—' }}</td>
                <td>{{ $at->disciplina?->nome ?? '—' }}</td>
                <td>{{ $at->responsavel ? "{$at->responsavel->first_name} {$at->responsavel->last_name}" : '—' }}</td>
                <td>{{ $at->inicio_planejado?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $at->data_termino?->format('d/m/Y') ?? '—' }}</td>
                <td class="{{ $at->estaPronta() ? 'pronta-sim' : 'pronta-nao' }}">{{ $at->estaPronta() ? 'Sim' : 'Não' }}</td>
                <td>{{ $at->status->value }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
