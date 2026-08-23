<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; }
        h2 { margin-bottom: 2px; }
        h4 { margin-top: 22px; margin-bottom: 6px; }
        .subtitulo { color: #666; margin-top: 0; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #222; color: #fff; }
        .cabecalho { display: table; width: 100%; margin-bottom: 16px; }
        .cabecalho-item { display: table-cell; padding: 6px; border: 1px solid #ccc; }
        .vazio { color: #888; font-style: italic; }
        .estado-recolhido { color: #1c7c3c; font-weight: bold; }
        .estado-nao_localizado { color: #b8860b; font-weight: bold; }
        .estado-pendente { color: #c0392b; font-weight: bold; }
        .historico { font-size: 9px; color: #555; }
        .historico li { margin-bottom: 2px; }
    </style>
</head>
<body>
    <h2>GRD-{{ str_pad($grd->numero, 3, '0', STR_PAD_LEFT) }}</h2>
    <p class="subtitulo">Guia de Remessa de Documentos — gerada em {{ $geradoEm->format('d/m/Y H:i') }}</p>

    <div class="cabecalho">
        <div class="cabecalho-item"><strong>Obra:</strong> {{ $grd->obra->name }}</div>
        <div class="cabecalho-item"><strong>Status:</strong> EMITIDA</div>
        <div class="cabecalho-item"><strong>Emitida em:</strong> {{ $grd->emitida_em?->format('d/m/Y H:i') }}</div>
        <div class="cabecalho-item"><strong>Emitida por:</strong> {{ $grd->emitidoPor ? "{$grd->emitidoPor->first_name} {$grd->emitidoPor->last_name}" : 'Usuário removido' }}</div>
    </div>

    @if ($grd->observacao)
    <p><strong>Observação:</strong> {{ $grd->observacao }}</p>
    @endif

    <h4>Documentos / Revisões</h4>
    <table>
        <thead>
            <tr><th>Código</th><th>Descrição</th><th>Revisão</th></tr>
        </thead>
        <tbody>
            @foreach ($grd->itens as $item)
            <tr>
                <td>{{ $item->codigo_documento_snapshot }}</td>
                <td>{{ $item->descricao_documento_snapshot }}</td>
                <td>{{ $item->revisao_snapshot }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h4>Destinatários</h4>
    <table>
        <thead>
            <tr><th>Nome</th><th>Empresa</th><th>Setor</th></tr>
        </thead>
        <tbody>
            @foreach ($grd->destinatarios as $gd)
            <tr>
                <td>{{ $gd->nome_snapshot }}</td>
                <td>{{ $gd->empresa_snapshot ?: '—' }}</td>
                <td>{{ $gd->setor_snapshot ?: '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h4>Distribuição</h4>
    <p class="subtitulo">Fato original desta GRD — o que foi de fato entregue na emissão.</p>
    @if ($distribuicoes->isNotEmpty())
    <table>
        <thead>
            <tr><th>Documento</th><th>Rev.</th><th>Destinatário</th><th>Empresa/Setor</th><th>Quantidade</th></tr>
        </thead>
        <tbody>
            @foreach ($distribuicoes as $dist)
            <tr>
                <td>{{ $dist->item->codigo_documento_snapshot }}</td>
                <td>{{ $dist->item->revisao_snapshot }}</td>
                <td>{{ $dist->grdDestinatario->nome_snapshot }}</td>
                <td>{{ trim(($dist->grdDestinatario->empresa_snapshot ?: '') . ' ' . ($dist->grdDestinatario->setor_snapshot ? "({$dist->grdDestinatario->setor_snapshot})" : '')) ?: '—' }}</td>
                <td>{{ $dist->quantidade }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma distribuição registrada.</p>
    @endif

    <h4>Situação Atual / Recolhimentos</h4>
    <p class="subtitulo">Estado ATUAL das cópias em campo — eventos posteriores à emissão, nunca alteram a distribuição original acima.</p>
    <table>
        <thead>
            <tr><th>Documento</th><th>Destinatário</th><th>Entregue</th><th>Recolhido</th><th>Pendente</th><th>Estado</th></tr>
        </thead>
        <tbody>
            @foreach ($distribuicoes as $dist)
            <tr>
                <td>{{ $dist->item->codigo_documento_snapshot }} ({{ $dist->item->revisao_snapshot }})</td>
                <td>{{ $dist->grdDestinatario->nome_snapshot }}</td>
                <td>{{ $dist->quantidadeEntregue() }}</td>
                <td>{{ $dist->quantidadeRecolhida() }}</td>
                <td>{{ $dist->quantidadePendente() }}</td>
                <td class="estado-{{ $dist->estado() }}">{{ ucfirst(str_replace('_', ' ', $dist->estado())) }}</td>
            </tr>
            @if ($dist->recolhimentos->isNotEmpty())
            <tr>
                <td colspan="6" class="historico">
                    <strong>Histórico:</strong>
                    <ul>
                        @foreach ($dist->recolhimentos->sortByDesc('created_at') as $evento)
                        <li>
                            {{ $evento->ocorrido_em?->format('d/m/Y H:i') }} —
                            {{ $evento->resultado->value === 'recolhido' ? 'Recolhido' : 'Não localizado' }}
                            (qtd. {{ $evento->quantidade }})
                            — registrado por {{ $evento->registradoPor ? "{$evento->registradoPor->first_name} {$evento->registradoPor->last_name}" : 'Usuário removido' }}
                            @if ($evento->observacao) — "{{ $evento->observacao }}" @endif
                        </li>
                        @endforeach
                    </ul>
                </td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>
</body>
</html>
