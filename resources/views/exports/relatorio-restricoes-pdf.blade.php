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
        .kpis { display: table; width: 100%; margin-bottom: 16px; }
        .kpi { display: table-cell; text-align: center; border: 1px solid #ccc; padding: 8px; }
        .kpi strong { display: block; font-size: 16px; }
        .vazio { color: #888; font-style: italic; }
    </style>
</head>
<body>
    <h2>Relatório de Restrições — {{ $obra->name }}</h2>
    <p class="subtitulo">
        Período: {{ \Illuminate\Support\Carbon::parse($periodo[0])->format('d/m/Y') }}
        &rarr; {{ \Illuminate\Support\Carbon::parse($periodo[1])->format('d/m/Y') }}
    </p>

    <div class="kpis">
        <div class="kpi"><strong>{{ $totais['total'] }}</strong>Total no Filtro</div>
        <div class="kpi"><strong>{{ $totais['abertas'] }}</strong>Abertas</div>
        <div class="kpi"><strong>{{ $totais['atrasadas'] }}</strong>Atrasadas</div>
        <div class="kpi"><strong>{{ $totais['tempoMedioGeral'] !== null ? $totais['tempoMedioGeral'] . 'd' : '—' }}</strong>Tempo Médio de Resolução</div>
        <div class="kpi"><strong>{{ $totais['riscoAlto'] }}</strong>Risco Alto (P&times;I)</div>
    </div>

    <h4>Restrições por Responsável</h4>
    @if(count($dados['porResponsavel']) > 0)
    <table>
        <thead><tr><th>Responsável</th><th>Total</th><th>Abertas</th><th>Bloqueantes</th></tr></thead>
        <tbody>
            @foreach($dados['porResponsavel'] as $r)
            <tr><td>{{ $r['nome'] }}</td><td>{{ $r['total'] }}</td><td>{{ $r['abertas'] }}</td><td>{{ $r['bloqueantes'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição encontrada.</p>
    @endif

    <h4>Restrições por Período</h4>
    @if(count($dados['porPeriodo']) > 0)
    <table>
        <thead><tr><th>Período</th><th>Total</th><th>Resolvidas</th></tr></thead>
        <tbody>
            @foreach($dados['porPeriodo'] as $p)
            <tr><td>{{ $p['label'] }}</td><td>{{ $p['total'] }}</td><td>{{ $p['resolvidas'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição com data dentro do intervalo selecionado.</p>
    @endif

    <h4>Restrições Atrasadas</h4>
    @if(count($dados['atrasadas']) > 0)
    <table>
        <thead><tr><th>Atividade</th><th>Descrição</th><th>Responsável</th><th>Prazo</th><th>Dias em Atraso</th></tr></thead>
        <tbody>
            @foreach($dados['atrasadas'] as $a)
            <tr><td>{{ $a[0] }}</td><td>{{ $a[1] }}</td><td>{{ $a[2] }}</td><td>{{ $a[3] }}</td><td>{{ $a[4] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição atrasada.</p>
    @endif

    <h4>Tempo Médio de Resolução por Categoria</h4>
    @if(count($dados['tempoMedioResolucao']['porCategoria']) > 0)
    <table>
        <thead><tr><th>Categoria</th><th>Média (dias)</th><th>Resolvidas</th></tr></thead>
        <tbody>
            @foreach($dados['tempoMedioResolucao']['porCategoria'] as $t)
            <tr><td>{{ $t['categoria'] }}</td><td>{{ $t['mediaDias'] }}</td><td>{{ $t['total'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição resolvida.</p>
    @endif

    <h4>Itens de Prontidão por Disciplina</h4>
    @if(count($dados['prontidaoPorDisciplina']) > 0)
    <table>
        <thead><tr><th>Disciplina</th><th>Total Itens</th><th>Concluídos</th><th>% Concluído</th></tr></thead>
        <tbody>
            @foreach($dados['prontidaoPorDisciplina'] as $d)
            <tr><td>{{ $d['disciplina'] }}</td><td>{{ $d['total'] }}</td><td>{{ $d['concluidos'] }}</td><td>{{ $d['percentual'] }}%</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhum item de prontidão configurado ou instanciado.</p>
    @endif

    <h4>Restrições por Pilar Lean</h4>
    @if(count($dados['porPilar']) > 0)
    <table>
        <thead><tr><th>Pilar</th><th>Total</th><th>Abertas</th></tr></thead>
        <tbody>
            @foreach($dados['porPilar'] as $p)
            <tr><td>{{ $p['label'] }}</td><td>{{ $p['total'] }}</td><td>{{ $p['abertas'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição categorizada por pilar.</p>
    @endif

    <h4>Restrições por Categoria</h4>
    @if(count($dados['porCategoria']) > 0)
    <table>
        <thead><tr><th>Categoria</th><th>Total</th><th>Abertas</th></tr></thead>
        <tbody>
            @foreach($dados['porCategoria'] as $c)
            <tr><td>{{ $c['categoria'] }}</td><td>{{ $c['total'] }}</td><td>{{ $c['abertas'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição encontrada.</p>
    @endif

    <h4>Status Geral</h4>
    @if(count($dados['statusGeral']) > 0)
    <table>
        <thead><tr><th>Status</th><th>Total</th></tr></thead>
        <tbody>
            @foreach($dados['statusGeral'] as $s)
            <tr><td>{{ $s['status'] }}</td><td>{{ $s['total'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição encontrada.</p>
    @endif

    <h4>Distribuição de Risco (P&times;I)</h4>
    <table>
        <thead><tr><th>Risco</th><th>Total</th></tr></thead>
        <tbody>
            @foreach($dados['riscoDistribuicao'] as $r)
            <tr><td>{{ $r['label'] }}</td><td>{{ $r['total'] }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h4>PPC — Aderência ao Planejamento Semanal</h4>
    @if(count($dados['ppcPorSemana']) > 0)
    <table>
        <thead><tr><th>Semana</th><th>Comprometidas</th><th>Concluídas no Prazo</th><th>PPC</th></tr></thead>
        <tbody>
            @foreach($dados['ppcPorSemana'] as $p)
            <tr><td>{{ $p['semana_label'] }}</td><td>{{ $p['comprometidas'] }}</td><td>{{ $p['concluidas_no_prazo'] }}</td><td>{{ $p['ppc_percentual'] }}%</td></tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma semana comprometida já fechada dentro do intervalo selecionado.</p>
    @endif
</body>
</html>
