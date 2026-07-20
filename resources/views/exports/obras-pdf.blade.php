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
    </style>
</head>
<body>
    <h2>Obras — {{ $tenant->name }}</h2>
    <p class="subtitulo">{{ $aba }} · {{ $works->count() }} obra(s)</p>

    <table>
        <thead>
            <tr>
                <th>Obra</th>
                <th>Cliente</th>
                <th>Localização</th>
                <th>Início</th>
                <th>Término</th>
                <th>Avanço</th>
                <th>Status</th>
                <th>Membros</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($works as $work)
            <tr>
                <td>{{ $work->name }}</td>
                <td>{{ $work->client?->name ?? '—' }}</td>
                <td>{{ $work->location ?? '—' }}</td>
                <td>{{ $work->start_date_baseline?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $work->end_date_baseline?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ number_format((float) ($work->avanco_realizado ?? 0), 1, ',', '') }}%</td>
                <td>
                    {{ match($work->status) {
                        'planejamento' => 'Planejamento',
                        'em_andamento' => 'Em Andamento',
                        'paralisada'   => 'Paralisada',
                        'concluida'    => 'Concluída',
                        default        => $work->status,
                    } }}
                </td>
                <td>{{ $work->users_count ?? $work->users->count() }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
