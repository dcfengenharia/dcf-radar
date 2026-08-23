<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificação de Registro — Radar EPC</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        body { font-family: sans-serif; background: #f4f5f7; margin: 0; padding: 24px 12px; color: #333; }
        .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 24px; }
        .marca { color: #696cff; font-weight: bold; font-size: 14px; margin-bottom: 4px; }
        h1 { font-size: 18px; margin: 0 0 16px; }
        .status { display: inline-block; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 13px; margin-bottom: 16px; }
        .status-valido { background: #e7f8ee; color: #1c7c3c; }
        .status-invalidado { background: #fdecea; color: #b71c1c; }
        .linha { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; font-size: 14px; }
        .linha:last-child { border-bottom: none; }
        .label { color: #888; }
        .valor { font-weight: 600; text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        th, td { text-align: left; padding: 4px 0; }
        .codigo { text-align: center; margin-top: 20px; font-size: 12px; color: #aaa; letter-spacing: 1px; }
        .aviso { margin-top: 16px; font-size: 11px; color: #999; }
    </style>
</head>
<body>
    <div class="card">
        <div class="marca">Radar EPC</div>
        <h1>Verificação de Registro de Entrega</h1>

        @if ($aceite->estaAtivo())
            <span class="status status-valido">Registro válido</span>
        @else
            <span class="status status-invalidado">Registro invalidado</span>
        @endif

        <div class="linha"><span class="label">Obra</span><span class="valor">{{ $aceite->grdDestinatario->grd->obra->name }}</span></div>
        <div class="linha"><span class="label">GRD</span><span class="valor">GRD-{{ str_pad($aceite->grdDestinatario->grd->numero, 3, '0', STR_PAD_LEFT) }}</span></div>
        <div class="linha"><span class="label">Destinatário</span><span class="valor">{{ $aceite->grdDestinatario->nome_snapshot }}</span></div>
        <div class="linha"><span class="label">Recebido por</span><span class="valor">{{ $aceite->nome_recebedor_snapshot }}</span></div>
        <div class="linha"><span class="label">Tipo</span><span class="valor">{{ $aceite->tipo_aceite->label() }}</span></div>
        <div class="linha"><span class="label">Data/hora</span><span class="valor">{{ $aceite->ocorrido_em->format('d/m/Y H:i') }}</span></div>

        @if (! $aceite->estaAtivo())
            <div class="linha"><span class="label">Invalidado em</span><span class="valor">{{ $aceite->invalidado_em->format('d/m/Y H:i') }}</span></div>
        @endif

        <div style="margin-top: 12px;">
            <span class="label" style="font-size: 13px;">Documentos desta entrega:</span>
            <table>
                <thead><tr><th>Documento</th><th>Revisão</th></tr></thead>
                <tbody>
                    @foreach ($distribuicoes as $dist)
                        <tr><td>{{ $dist->item->codigo_documento_snapshot }}</td><td>{{ $dist->item->revisao_snapshot }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="codigo">CÓDIGO DE VERIFICAÇÃO: {{ strtoupper(substr($aceite->token, 0, 8)) }}</div>
        <p class="aviso">Este registro comprova o aceite de recebimento informado na plataforma Radar EPC. Não constitui assinatura digital certificada (ICP-Brasil) nem possui valor jurídico de assinatura qualificada.</p>
    </div>
</body>
</html>
