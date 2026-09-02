<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; margin: 0; }
        .pagina-titulo { font-size: 13px; margin: 0 0 10px 0; }

        /* Template PEQUENA — grade de etiquetas pequenas, várias por página
           (Seção 11: "poucos templates úteis", pensado pra folha de
           etiquetas adesivas comuns). */
        .grade-pequena { }
        .etiqueta-pequena {
            display: inline-block; width: 160px; height: 100px;
            border: 1px solid #999; margin: 4px; padding: 6px;
            vertical-align: top; page-break-inside: avoid;
        }
        .etiqueta-pequena .qr { float: left; width: 60px; height: 60px; margin-right: 6px; }
        .etiqueta-pequena .qr svg { width: 60px; height: 60px; }
        .etiqueta-pequena .texto { font-size: 9px; }
        .etiqueta-pequena .titulo { font-weight: bold; font-size: 10px; }

        /* Template MÉDIA — 1 etiqueta em destaque, mais legível, ainda
           várias por página em grade maior. */
        .etiqueta-media {
            display: inline-block; width: 320px; height: 180px;
            border: 1px solid #666; margin: 8px; padding: 12px;
            vertical-align: top; page-break-inside: avoid;
        }
        .etiqueta-media .qr { float: left; width: 130px; height: 130px; margin-right: 14px; }
        .etiqueta-media .qr svg { width: 130px; height: 130px; }
        .etiqueta-media .titulo { font-weight: bold; font-size: 15px; margin-bottom: 4px; }
        .etiqueta-media .subtitulo { font-size: 11px; color: #444; margin-bottom: 4px; }
        .etiqueta-media .codigo-texto { font-size: 8px; color: #888; word-break: break-all; }

        /* Template A4 — listagem tabular, uma linha por entidade, pra
           conferência/arquivo (nunca pra colar fisicamente). */
        table.listagem { width: 100%; border-collapse: collapse; }
        table.listagem th, table.listagem td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; vertical-align: middle; }
        table.listagem th { background: #222; color: #fff; }
        table.listagem .qr-col { width: 60px; }
        table.listagem .qr-col svg { width: 50px; height: 50px; }

        .rodape { margin-top: 16px; color: #999; font-size: 8px; }
    </style>
</head>
<body>
    <p class="pagina-titulo">Etiquetas de Estoque — {{ now()->format('d/m/Y H:i') }}</p>

    @if ($tamanho === 'a4')
        <table class="listagem">
            <thead>
                <tr>
                    <th class="qr-col">QR</th>
                    <th>Título</th>
                    <th>Detalhe</th>
                    <th>Código</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($etiquetas as $etiqueta)
                    <tr>
                        <td class="qr-col">{!! $etiqueta['qr_svg'] !!}</td>
                        <td>{{ $etiqueta['titulo'] }}</td>
                        <td>{{ $etiqueta['subtitulo'] ?: '—' }}</td>
                        <td style="font-size: 8px; color: #888;">{{ $etiqueta['codigo_texto'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($tamanho === 'media')
        <div class="grade-media">
            @foreach ($etiquetas as $etiqueta)
                <div class="etiqueta-media">
                    <div class="qr">{!! $etiqueta['qr_svg'] !!}</div>
                    <div class="titulo">{{ $etiqueta['titulo'] }}</div>
                    @if ($etiqueta['subtitulo'])
                        <div class="subtitulo">{{ $etiqueta['subtitulo'] }}</div>
                    @endif
                    <div class="codigo-texto">{{ $etiqueta['codigo_texto'] }}</div>
                </div>
            @endforeach
        </div>
    @else
        <div class="grade-pequena">
            @foreach ($etiquetas as $etiqueta)
                <div class="etiqueta-pequena">
                    <div class="qr">{!! $etiqueta['qr_svg'] !!}</div>
                    <div class="texto">
                        <div class="titulo">{{ $etiqueta['titulo'] }}</div>
                        @if ($etiqueta['subtitulo'])
                            <div>{{ Str::limit($etiqueta['subtitulo'], 40) }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <p class="rodape">
        Etiquetas identificam a entidade — nunca representam saldo ou localização atual, que sempre mudam. Consulte o sistema para o estado corrente.
    </p>
</body>
</html>
