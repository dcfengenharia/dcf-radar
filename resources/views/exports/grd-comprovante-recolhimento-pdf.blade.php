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
        .resultado-recolhido { color: #1c7c3c; font-weight: bold; }
        .resultado-nao_localizado { color: #b8860b; font-weight: bold; }
        .rodape { margin-top: 30px; color: #888; font-size: 9px; }
    </style>
</head>
<body>
    <h2>Comprovante de Recolhimento</h2>
    <p class="subtitulo">
        GRD-{{ str_pad($grd->numero, 3, '0', STR_PAD_LEFT) }} —
        {{ $distribuicao->item->codigo_documento_snapshot }} (Rev. {{ $distribuicao->item->revisao_snapshot }}) —
        {{ $distribuicao->grdDestinatario->nome_snapshot }}
    </p>

    <div class="cabecalho">
        <div class="cabecalho-item"><strong>Obra:</strong> {{ $grd->obra->name }}</div>
        <div class="cabecalho-item"><strong>GRD emitida em:</strong> {{ $grd->emitida_em?->format('d/m/Y H:i') }}</div>
    </div>

    <h4>Documento / Destinatário</h4>
    <table>
        <thead>
            <tr><th>Documento</th><th>Descrição</th><th>Revisão</th><th>Destinatário</th><th>Empresa/Setor</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $distribuicao->item->codigo_documento_snapshot }}</td>
                <td>{{ $distribuicao->item->descricao_documento_snapshot }}</td>
                <td>{{ $distribuicao->item->revisao_snapshot }}</td>
                <td>{{ $distribuicao->grdDestinatario->nome_snapshot }}</td>
                <td>{{ trim(($distribuicao->grdDestinatario->empresa_snapshot ?: '') . ' ' . ($distribuicao->grdDestinatario->setor_snapshot ? "({$distribuicao->grdDestinatario->setor_snapshot})" : '')) ?: '—' }}</td>
            </tr>
        </tbody>
    </table>

    <h4>Evento de Recolhimento</h4>
    <p class="subtitulo">Este comprovante reproduz EXATAMENTE o que este evento registrou — nunca o estado atual da distribuição, que pode ter mudado depois.</p>
    <table>
        <thead>
            <tr><th>Quantidade distribuída (original)</th><th>Resultado deste evento</th><th>Quantidade deste evento</th><th>Ocorrido em</th><th>Registrado por</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $distribuicao->quantidade }}</td>
                <td class="resultado-{{ $evento->resultado->value }}">
                    {{ $evento->resultado->value === 'recolhido' ? 'Recolhido' : 'Não localizado' }}
                </td>
                <td>{{ $evento->quantidade }}</td>
                <td>{{ $evento->ocorrido_em?->format('d/m/Y H:i') }}</td>
                <td>{{ $evento->registradoPor ? "{$evento->registradoPor->first_name} {$evento->registradoPor->last_name}" : 'Usuário removido' }}</td>
            </tr>
        </tbody>
    </table>

    @if ($evento->observacao)
    <p><strong>Observação:</strong> {{ $evento->observacao }}</p>
    @endif

    <p class="rodape">Comprovante gerado em {{ $geradoEm->format('d/m/Y H:i') }} — não confundir com a data de ocorrência do evento acima.</p>
</body>
</html>
