{{--
    Lista de ocorrências do Health Check — compartilhado entre
    ⚡cronograma.blade.php (seção Obra) e ⚡relatorio-importar-avanco.blade.php
    (seção Relatórios), mesma lógica de renderização nas duas telas.
    Extraído como partial (Fase 2B.1) porque a lógica de layout por tipo de
    finding cresceu o suficiente pra não valer mais duplicar (mesmo
    critério já usado em relatorio-tabela-curva.blade.php/
    relatorio-grafico-config.blade.php).

    Espera:
    - $findings: array de findings já serializados (formato HealthCheckFinding::toArray())
    - $idPrefix: string usada na wire:key de cada item (única por página, evita colisão)

    Expandir/recolher usa Alpine puro (x-data="{ aberto: false }" + @click
    alternando o valor) — não o data-bs-toggle="collapse" nativo do
    Bootstrap, que mantém estado próprio em JS fora do alcance do Livewire
    e podia ficar dessincronizado quando o Livewire remonta o DOM (achado
    de UX, corrigido nesta rodada — ver CLAUDE.md).
--}}
@foreach ($findings as $hcFinding)
    @php
        $hcSev = \App\Enums\HealthCheckSeveridade::from($hcFinding['severidade']);
        $hcRegraId = $hcFinding['regra_id'];
        $hcEhComponente = $hcRegraId === 'STRUCT-004';
        $hcEhCiclo = $hcRegraId === 'STRUCT-005';
        $hcEhAtividadeEstrutural = in_array($hcRegraId, ['STRUCT-001', 'STRUCT-002', 'STRUCT-003'], true);
        $hcEhVinculosDuplicados = $hcRegraId === 'LOGIC-005';
        $hcEhDatasReaisFS = $hcRegraId === 'LOGIC-008';
        $hcEhVinculoResumo = $hcRegraId === 'LOGIC-009';
        $hcEhPredecessoraInativa = $hcRegraId === 'LOGIC-010';
        $hcEhSlack = in_array($hcRegraId, ['SLACK-001', 'SLACK-002', 'SLACK-005'], true);
        $hcRotuloContagem = match (true) {
            $hcEhComponente => count($hcFinding['atividades']) . ' rede(s) desconectada(s)',
            $hcEhCiclo => count($hcFinding['atividades']) . ' ciclo(s)',
            $hcEhVinculosDuplicados => count($hcFinding['atividades']) . ' par(es)',
            $hcEhDatasReaisFS => count($hcFinding['atividades']) . ' relação(ões)',
            $hcEhVinculoResumo => count($hcFinding['atividades']) . ' vínculo(s)',
            $hcEhPredecessoraInativa => count($hcFinding['atividades']) . ' atividade(s)',
            default => count($hcFinding['atividades']) . ' atividade(s)',
        };
    @endphp
    <div class="border rounded mb-2" x-data="{ aberto: false }" wire:key="{{ $idPrefix }}-{{ $loop->index }}">
        <div class="d-flex align-items-center gap-2 p-2" style="cursor:pointer" @click="aberto = !aberto">
            <span class="badge bg-{{ $hcSev->cor() }}">{{ $hcSev->emoji() }} {{ $hcSev->label() }}</span>
            <span class="fw-semibold">{{ $hcFinding['titulo'] }}</span>
            <span class="text-muted small ms-auto">
                {{ $hcRotuloContagem }}
                <i class="bx" :class="aberto ? 'bx-chevron-up' : 'bx-chevron-down'"></i>
            </span>
        </div>
        <div x-show="aberto" x-transition x-cloak>
            <div class="p-3 border-top">
                <p class="mb-2">{{ $hcFinding['descricao'] }}</p>
                <p class="mb-2 small"><strong>Impacto:</strong> {{ $hcFinding['impacto'] }}</p>
                <p class="mb-3 small"><strong>Recomendação:</strong> {{ $hcFinding['recomendacao'] }}</p>

                @if($hcEhComponente)
                    @foreach ($hcFinding['atividades'] as $hcComp)
                        <p class="small mb-2">
                            <strong>Rede {{ $hcComp['componente_id'] }}</strong> —
                            {{ $hcComp['quantidade_atividades'] }} atividade(s),
                            {{ $hcComp['quantidade_relacoes'] }} relação(ões) internas
                        </p>
                        <ul class="list-group list-group-flush mb-2">
                            @foreach ($hcComp['atividades'] as $hcAt)
                                <li class="list-group-item px-0">{{ $hcAt['codigo'] ?? '—' }} — {{ $hcAt['nome'] }}</li>
                            @endforeach
                        </ul>
                    @endforeach
                @elseif($hcEhCiclo)
                    @foreach ($hcFinding['atividades'] as $hcCiclo)
                        <p class="small mb-2"><strong>Ciclo {{ $hcCiclo['ciclo_id'] }}</strong></p>
                        <ul class="list-group list-group-flush mb-2">
                            @foreach ($hcCiclo['atividades'] as $hcAt)
                                <li class="list-group-item px-0">{{ $hcAt['codigo'] ?? '—' }} — {{ $hcAt['nome'] }}</li>
                            @endforeach
                        </ul>
                        @if(!empty($hcCiclo['relacoes']))
                            <p class="small text-muted mb-0">
                                Relações que fecham o ciclo:
                                @foreach ($hcCiclo['relacoes'] as $hcRel)
                                    {{ $hcRel['de'] }} → {{ $hcRel['para'] }}{{ !$loop->last ? ', ' : '' }}
                                @endforeach
                            </p>
                        @endif
                    @endforeach
                @elseif($hcEhVinculosDuplicados)
                    @foreach ($hcFinding['atividades'] as $hcPar)
                        <p class="small mb-2">
                            <strong>{{ $hcPar['predecessora']['codigo'] ?? '—' }} — {{ $hcPar['predecessora']['nome'] ?? $hcPar['predecessora']['uid'] }}</strong>
                            →
                            <strong>{{ $hcPar['sucessora']['codigo'] ?? '—' }} — {{ $hcPar['sucessora']['nome'] }}</strong>
                        </p>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Tipo</th><th>Código original</th><th>LinkLag</th><th>LagFormat</th></tr></thead>
                                <tbody>
                                    @foreach ($hcPar['vinculos'] as $hcVinculo)
                                    <tr>
                                        <td>{{ $hcVinculo['tipo'] ?? '—' }}</td>
                                        <td>{{ $hcVinculo['tipo_codigo_original'] }}</td>
                                        <td>{{ $hcVinculo['link_lag'] ?? '—' }}</td>
                                        <td>{{ $hcVinculo['lag_format'] ?? '—' }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @elseif($hcEhDatasReaisFS)
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr>
                                <th>Predecessora</th><th class="text-end">Término Real</th>
                                <th>Sucessora</th><th class="text-end">Início Real</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($hcFinding['atividades'] as $hcPar)
                                <tr>
                                    <td>{{ $hcPar['predecessora']['codigo'] ?? '—' }} — {{ $hcPar['predecessora']['nome'] }}</td>
                                    <td class="text-end">{{ \Illuminate\Support\Carbon::parse($hcPar['predecessora']['real_termino'])->format('d/m/y') }}</td>
                                    <td>{{ $hcPar['sucessora']['codigo'] ?? '—' }} — {{ $hcPar['sucessora']['nome'] }}</td>
                                    <td class="text-end">{{ \Illuminate\Support\Carbon::parse($hcPar['sucessora']['real_inicio'])->format('d/m/y') }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($hcEhVinculoResumo)
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Predecessora</th><th>Sucessora</th><th>Tipo</th><th>Lado resumo</th></tr></thead>
                            <tbody>
                                @foreach ($hcFinding['atividades'] as $hcVinc)
                                <tr>
                                    <td>{{ $hcVinc['predecessora']['codigo'] ?? '—' }} — {{ $hcVinc['predecessora']['nome'] }}</td>
                                    <td>{{ $hcVinc['sucessora']['codigo'] ?? '—' }} — {{ $hcVinc['sucessora']['nome'] }}</td>
                                    <td>{{ $hcVinc['tipo'] ?? '—' }}</td>
                                    <td>
                                        @switch($hcVinc['direcao'])
                                            @case('predecessora_e_resumo') Predecessora @break
                                            @case('sucessora_e_resumo') Sucessora @break
                                            @default Ambas
                                        @endswitch
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($hcEhPredecessoraInativa)
                    @foreach ($hcFinding['atividades'] as $hcReg)
                        <p class="small mb-1">
                            <strong>{{ $hcReg['sucessora']['codigo'] ?? '—' }} — {{ $hcReg['sucessora']['nome'] }}</strong>
                        </p>
                        <ul class="list-group list-group-flush mb-2">
                            @foreach ($hcReg['predecessoras_inativas'] as $hcAt)
                                <li class="list-group-item px-0 text-danger">{{ $hcAt['codigo'] ?? '—' }} — {{ $hcAt['nome'] }} (inativa)</li>
                            @endforeach
                            @foreach ($hcReg['predecessoras_ativas'] as $hcAt)
                                <li class="list-group-item px-0">{{ $hcAt['codigo'] ?? '—' }} — {{ $hcAt['nome'] }} (ativa)</li>
                            @endforeach
                        </ul>
                    @endforeach
                @elseif($hcEhSlack)
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr>
                                <th>Código</th><th>Atividade</th><th>UID</th>
                                <th class="text-end">TotalSlack (bruto)</th><th class="text-end">FreeSlack (bruto)</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($hcFinding['atividades'] as $hcAt)
                                <tr>
                                    <td>{{ $hcAt['codigo'] ?? '—' }}</td>
                                    <td>{{ $hcAt['nome'] }}</td>
                                    <td>{{ $hcAt['uid'] }}</td>
                                    <td class="text-end">{{ $hcAt['total_slack'] ?? '—' }}</td>
                                    <td class="text-end">{{ $hcAt['free_slack'] ?? '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <small class="text-muted d-block mt-2">Valores brutos do XML (décimos de minuto, sem conversão) — usados aqui só pra comparação de sinal/relação, nunca como magnitude convertida.</small>
                    </div>
                @elseif($hcEhAtividadeEstrutural)
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr>
                                <th>Código</th><th>Atividade</th><th>UID</th><th>Tipo</th><th>Disciplina</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($hcFinding['atividades'] as $hcAt)
                                <tr>
                                    <td>{{ $hcAt['codigo'] ?? '—' }}</td>
                                    <td>{{ $hcAt['nome'] }}</td>
                                    <td>{{ $hcAt['uid'] }}</td>
                                    <td>{{ $hcAt['tipo'] }}</td>
                                    <td>{{ $hcAt['disciplina'] ?? '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif(count($hcFinding['atividades']) > 0 && array_key_exists('uid', $hcFinding['atividades'][0]))
                    {{-- Layout original (Fase 1 — 24 regras por atividade) --}}
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr>
                                <th>Código</th><th>UID</th><th>Atividade</th><th>Início</th><th>Término</th>
                                <th>Início Real</th><th>Término Real</th><th class="text-end">%</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($hcFinding['atividades'] as $hcAt)
                                <tr>
                                    <td>{{ $hcAt['codigo'] ?? '—' }}</td>
                                    <td>{{ $hcAt['uid'] }}</td>
                                    <td>{{ $hcAt['nome'] }}</td>
                                    <td>{{ $hcAt['data_inicio'] ? \Illuminate\Support\Carbon::parse($hcAt['data_inicio'])->format('d/m/y') : '—' }}</td>
                                    <td>{{ $hcAt['data_termino'] ? \Illuminate\Support\Carbon::parse($hcAt['data_termino'])->format('d/m/y') : '—' }}</td>
                                    <td>{{ $hcAt['real_inicio'] ? \Illuminate\Support\Carbon::parse($hcAt['real_inicio'])->format('d/m/y') : '—' }}</td>
                                    <td>{{ $hcAt['real_termino'] ? \Illuminate\Support\Carbon::parse($hcAt['real_termino'])->format('d/m/y') : '—' }}</td>
                                    <td class="text-end">{{ $hcAt['percentual_concluido'] !== null ? $hcAt['percentual_concluido'].'%' : '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif(count($hcFinding['atividades']) > 0)
                    <ul class="list-group list-group-flush">
                        @foreach ($hcFinding['atividades'] as $hcAt)
                        <li class="list-group-item px-0">{{ $hcAt['nome'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endforeach
