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
        .rodape { margin-top: 30px; color: #888; font-size: 9px; }
        .aceite-box { display: table; width: 100%; margin-top: 10px; }
        .aceite-col { display: table-cell; vertical-align: top; padding: 6px; }
        .aceite-assinatura { max-width: 220px; max-height: 90px; border: 1px solid #ccc; }
        .aceite-aviso { font-size: 8px; color: #999; margin-top: 6px; }
        .qr-col { width: 110px; text-align: center; }
        .qr-col svg { width: 100px; height: 100px; }
    </style>
</head>
<body>
    <h2>Comprovante de Entrega</h2>
    <p class="subtitulo">
        GRD-{{ str_pad($grd->numero, 3, '0', STR_PAD_LEFT) }} — {{ $grdDestinatario->nome_snapshot }}
    </p>

    <div class="cabecalho">
        <div class="cabecalho-item"><strong>Obra:</strong> {{ $grd->obra->name }}</div>
        <div class="cabecalho-item"><strong>Emitida em:</strong> {{ $grd->emitida_em?->format('d/m/Y H:i') }}</div>
        <div class="cabecalho-item"><strong>Emitida por:</strong> {{ $grd->emitidoPor ? "{$grd->emitidoPor->first_name} {$grd->emitidoPor->last_name}" : 'Usuário removido' }}</div>
    </div>

    <h4>Destinatário</h4>
    <table>
        <thead>
            <tr><th>Nome</th><th>Empresa</th><th>Setor</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $grdDestinatario->nome_snapshot }}</td>
                <td>{{ $grdDestinatario->empresa_snapshot ?: '—' }}</td>
                <td>{{ $grdDestinatario->setor_snapshot ?: '—' }}</td>
            </tr>
        </tbody>
    </table>

    <h4>Itens Entregues</h4>
    <p class="subtitulo">Fato original desta entrega — o que foi de fato distribuído a este destinatário nesta GRD.</p>
    @if ($distribuicoes->isNotEmpty())
    <table>
        <thead>
            <tr><th>Documento</th><th>Descrição</th><th>Revisão</th><th>Quantidade</th></tr>
        </thead>
        <tbody>
            @foreach ($distribuicoes as $dist)
            <tr>
                <td>{{ $dist->item->codigo_documento_snapshot }}</td>
                <td>{{ $dist->item->descricao_documento_snapshot }}</td>
                <td>{{ $dist->item->revisao_snapshot }}</td>
                <td>{{ $dist->quantidade }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhum item entregue registrado para este destinatário.</p>
    @endif

    <h4>Aceite de Recebimento</h4>
    @if ($aceiteAtivo)
        <div class="aceite-box">
            <div class="aceite-col">
                <p style="margin: 0 0 4px;"><strong>Recebido por:</strong> {{ $aceiteAtivo->nome_recebedor_snapshot }}</p>
                <p style="margin: 0 0 4px;"><strong>Tipo:</strong> {{ $aceiteAtivo->tipo_aceite->label() }}</p>
                <p style="margin: 0 0 4px;"><strong>Data/hora:</strong> {{ $aceiteAtivo->ocorrido_em->format('d/m/Y H:i') }}</p>
                <p style="margin: 0 0 4px;"><strong>Registrado por:</strong> {{ $aceiteAtivo->registradoPor ? "{$aceiteAtivo->registradoPor->first_name} {$aceiteAtivo->registradoPor->last_name}" : 'Usuário removido' }}</p>
                @if ($assinaturaBase64)
                    <p style="margin: 8px 0 2px;"><strong>Assinatura:</strong></p>
                    <img class="aceite-assinatura" src="data:image/png;base64,{{ $assinaturaBase64 }}" alt="Assinatura">
                @elseif ($aceiteAtivo->tipo_aceite->value === 'assinatura' && $assinaturaIntegra === false)
                    <p class="aceite-aviso" style="color: #b71c1c;">Integridade do arquivo de assinatura não pôde ser confirmada.</p>
                @endif
                <p class="aceite-aviso">Aceite de recebimento capturado na plataforma — não é assinatura digital certificada (ICP-Brasil) nem possui valor jurídico de assinatura qualificada.</p>
            </div>
            @if ($qrSvg)
                <div class="aceite-col qr-col">
                    {!! $qrSvg !!}
                    <p style="font-size: 8px; margin: 4px 0 0;">Verificar registro</p>
                </div>
            @endif
        </div>
    @else
        <p class="vazio">Sem aceite ativo.</p>
    @endif

    <p class="rodape">Comprovante gerado em {{ $geradoEm->format('d/m/Y H:i') }}.</p>
</body>
</html>
