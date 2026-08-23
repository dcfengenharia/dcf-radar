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
        .status-pronta { color: #1c7c3c; font-weight: bold; }
        .status-atencao { color: #b8860b; font-weight: bold; }
        .status-nao_pronta { color: #c0392b; font-weight: bold; }
        .status-concluida { color: #666; font-weight: bold; }
        .vencida { color: #c0392b; font-weight: bold; }
        .quebra { page-break-before: always; }
    </style>
</head>
<body>
    <h2>Central de Prontidão — {{ $obra->name }}</h2>
    <p class="subtitulo">
        Gerado em {{ $geradoEm->format('d/m/Y H:i') }} ·
        Horizonte: {{ $horizonteLabel }} ·
        Pacote/EAP: {{ $filtros['pacote'] ?? 'Todos' }} ·
        Disciplina: {{ $filtros['disciplina'] ?? 'Todas' }} ·
        Frente: {{ $filtros['frente'] ?? 'Todas' }} ·
        Responsável: {{ $filtros['responsavel'] ?? 'Todos' }}
        @if ($filtros['busca'] ?? null)
        · Busca: "{{ $filtros['busca'] }}"
        @endif
    </p>

    <div class="kpis">
        <div class="kpi"><strong>{{ $resumo['total'] }}</strong>Total de Atividades</div>
        <div class="kpi"><strong>{{ $resumo['pronta'] }}</strong>Prontas</div>
        <div class="kpi"><strong>{{ $resumo['atencao'] }}</strong>Atenção</div>
        <div class="kpi"><strong>{{ $resumo['nao_pronta'] }}</strong>Não Prontas</div>
        <div class="kpi"><strong>{{ $resumo['concluida'] }}</strong>Concluídas</div>
    </div>

    <h4>Atividades</h4>
    @if ($views->isNotEmpty())
    <table>
        <thead>
            <tr>
                <th>Código</th><th>Atividade</th><th>Pacote/EAP</th><th>Disciplina</th>
                <th>Frente</th><th>Responsável</th><th>Início Planejado</th><th>Status</th><th>Motivos</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($views as $v)
            <tr>
                <td>{{ $v->codigoCronograma ?? '—' }}</td>
                <td>{{ $v->nome }}</td>
                <td>{{ $v->pacoteNome ?? '—' }}</td>
                <td>{{ $v->disciplinaNome ?? '—' }}</td>
                <td>{{ $v->frenteNome ?? '—' }}</td>
                <td>{{ $v->responsavelNome ?? '—' }}</td>
                <td>{{ $v->inicioPlanejado?->format('d/m/Y') ?? '—' }}</td>
                <td class="status-{{ $v->statusOperacional->value }}">{{ $v->statusOperacional->label() }}</td>
                <td>{{ empty($v->resumoMotivos) ? '—' : implode('; ', $v->resumoMotivos) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma atividade neste horizonte/filtro.</p>
    @endif

    <div class="quebra"></div>

    <h4>Restrições Bloqueantes</h4>
    @php $restricoesBloq = $views->flatMap->restricoesBloqueantes; @endphp
    @if ($restricoesBloq->isNotEmpty())
    <table>
        <thead><tr><th>Atividade</th><th>Descrição</th><th>Responsável</th><th>Prazo</th><th>Origem</th></tr></thead>
        <tbody>
            @foreach ($views as $v)
            @foreach ($v->restricoesBloqueantes as $r)
            @php $vencida = $r->prazoLimite?->isPast() ?? false; @endphp
            <tr>
                <td>{{ $v->nome }}</td>
                <td>{{ $r->descricao }}</td>
                <td>{{ $r->responsavel ?? '—' }}</td>
                <td class="{{ $vencida ? 'vencida' : '' }}">{{ $r->prazoLimite?->format('d/m/Y') ?? '—' }}{{ $vencida ? ' (vencida)' : '' }}</td>
                <td>{{ $r->origem->label() }}</td>
            </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição bloqueante aberta.</p>
    @endif

    <h4>Restrições Não-Bloqueantes</h4>
    @php $restricoesNaoBloq = $views->flatMap->restricoesNaoBloqueantes; @endphp
    @if ($restricoesNaoBloq->isNotEmpty())
    <table>
        <thead><tr><th>Atividade</th><th>Descrição</th><th>Responsável</th><th>Prazo</th><th>Origem</th></tr></thead>
        <tbody>
            @foreach ($views as $v)
            @foreach ($v->restricoesNaoBloqueantes as $r)
            <tr>
                <td>{{ $v->nome }}</td>
                <td>{{ $r->descricao }}</td>
                <td>{{ $r->responsavel ?? '—' }}</td>
                <td>{{ $r->prazoLimite?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $r->origem->label() }}</td>
            </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhuma restrição não-bloqueante aberta.</p>
    @endif

    <div class="quebra"></div>

    <h4>Checklist Pendente</h4>
    @php $checklistPendente = $views->filter(fn ($v) => ! empty($v->checklistPendentes)); @endphp
    @if ($checklistPendente->isNotEmpty())
    <table>
        <thead><tr><th>Atividade</th><th>Item Pendente</th></tr></thead>
        <tbody>
            @foreach ($views as $v)
            @foreach ($v->checklistPendentes as $item)
            <tr><td>{{ $v->nome }}</td><td>{{ $item }}</td></tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhum item de checklist pendente.</p>
    @endif

    <h4>Plano de Ação</h4>
    @php $planosAcao = $views->flatMap->planoAcoesAbertas; @endphp
    @if ($planosAcao->isNotEmpty())
    <table>
        <thead><tr><th>Atividade</th><th>Título</th><th>Regra</th><th>Severidade</th><th>Última Reconciliação</th></tr></thead>
        <tbody>
            @foreach ($views as $v)
            @foreach ($v->planoAcoesAbertas as $pa)
            <tr>
                <td>{{ $v->nome }}</td>
                <td>{{ $pa->titulo }}</td>
                <td>{{ $pa->regraId }}</td>
                <td>{{ $pa->severidade?->label() ?? '—' }}</td>
                <td>{{ $pa->resultadoUltimaReconciliacao?->label() ?? 'Nunca reconciliada' }}</td>
            </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhum Plano de Ação aberto relacionado.</p>
    @endif

    <div class="quebra"></div>

    <h4>Suprimentos</h4>
    @php $suprimentos = $views->flatMap->suprimentos; @endphp
    @if ($suprimentos->isNotEmpty())
    <table>
        <thead><tr><th>Atividade</th><th>Item</th><th>Status</th><th>Necessidade</th></tr></thead>
        <tbody>
            @foreach ($views as $v)
            @foreach ($v->suprimentos as $s)
            <tr>
                <td>{{ $v->nome }}</td>
                <td>{{ $s->nome }}</td>
                <td>{{ $s->status->label() }}</td>
                <td>{{ $s->necessidade?->format('d/m/Y') ?? '—' }}</td>
            </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhum item de suprimento em risco/atrasado.</p>
    @endif

    <h4>Engenharia</h4>
    @php $engenharia = $views->flatMap->engenharia; @endphp
    @if ($engenharia->isNotEmpty())
    <table>
        <thead><tr><th>Atividade</th><th>Código</th><th>Emitido</th><th>Atrasado</th></tr></thead>
        <tbody>
            @foreach ($views as $v)
            @foreach ($v->engenharia as $doc)
            <tr>
                <td>{{ $v->nome }}</td>
                <td>{{ $doc->codigo ?? '—' }}</td>
                <td>{{ $doc->emitido ? 'Sim' : 'Não' }}</td>
                <td>{{ $doc->atrasado ? 'Sim' : 'Não' }}</td>
            </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhum documento de engenharia atrasado/não emitido.</p>
    @endif

    <h4>Documentos GED Bloqueantes</h4>
    @php $documentosBloqueantes = $views->flatMap->documentosBloqueantes; @endphp
    @if ($documentosBloqueantes->isNotEmpty())
    <table>
        <thead><tr><th>Atividade</th><th>Código</th><th>Revisão Vigente</th><th>Motivo</th></tr></thead>
        <tbody>
            @foreach ($views as $v)
            @foreach ($v->documentosBloqueantes as $d)
            <tr>
                <td>{{ $v->nome }}</td>
                <td>{{ $d->codigo ?? '—' }}</td>
                <td>{{ $d->revisaoVigente ?? '—' }}</td>
                <td>{{ $d->motivo === 'sem_revisao' ? 'Ainda não emitido' : 'Revisão vigente não liberada' }}</td>
            </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @else
    <p class="vazio">Nenhum documento de engenharia bloqueando liberação para construção.</p>
    @endif
</body>
</html>
