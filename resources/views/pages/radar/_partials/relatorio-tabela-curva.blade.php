{{--
    Tabela de valores da curva S (mensal ou semanal) — sempre em
    PERCENTUAL, nunca HH. "$tabela" vem de
    ⚡relatorio-detalhe.blade.php::serieParaGrafico(), uma linha por
    período com % do período e % acumulado das 3 séries.

    "$comAderencia" (opcional, default false) acrescenta a coluna de
    Aderência da semana (%realizado do período ÷ %previsto do período) —
    só faz sentido na tabela SEMANAL, por isso é opt-in.

    "$limiaresAderencia" (opcional, [atencao, otimo]) — Fase 5,
    Consolidação: colore a célula de Aderência com os MESMOS limiares já
    usados pelo antigo velocímetro (App\...::ADERENCIA_LIMIAR_ATENCAO/
    _OTIMO, expostos via limiaresAderencia() do componente) — nenhum
    limiar novo, só reaproveitado aqui como cor de texto em vez de
    zona de gauge. Sem o parâmetro, a célula fica sem cor (comportamento
    anterior preservado).
--}}
@php
    $comAderencia = $comAderencia ?? false;
    [$limiarAtencaoCel, $limiarOtimoCel] = $limiaresAderencia ?? [null, null];
@endphp
@if(!empty($tabela))
<div class="table-responsive mb-4">
    <table class="table table-sm table-bordered mb-0">
        <thead class="table-light">
            <tr>
                <th rowspan="2" class="align-middle">Período</th>
                <th colspan="3" class="text-center">% do Período</th>
                <th colspan="3" class="text-center">% Acumulado</th>
                @if($comAderencia)
                <th rowspan="2" class="align-middle text-center">Aderência</th>
                @endif
            </tr>
            <tr>
                <th class="text-end">Previsto</th>
                <th class="text-end">Tendência</th>
                <th class="text-end">Realizado</th>
                <th class="text-end">Previsto</th>
                <th class="text-end">Tendência</th>
                <th class="text-end">Realizado</th>
            </tr>
        </thead>
        <tbody>
            @foreach($tabela as $linha)
            <tr>
                <td>{{ $linha['label'] }}</td>
                <td class="text-end">{{ $linha['previsto_pct_periodo'] !== null ? number_format($linha['previsto_pct_periodo'], 1, ',', '.') . '%' : '—' }}</td>
                <td class="text-end">{{ $linha['tendencia_pct_periodo'] !== null ? number_format($linha['tendencia_pct_periodo'], 1, ',', '.') . '%' : '—' }}</td>
                <td class="text-end">{{ $linha['realizado_pct_periodo'] !== null ? number_format($linha['realizado_pct_periodo'], 1, ',', '.') . '%' : '—' }}</td>
                <td class="text-end">{{ $linha['previsto_pct'] !== null ? number_format($linha['previsto_pct'], 1, ',', '.') . '%' : '—' }}</td>
                <td class="text-end">{{ $linha['tendencia_pct'] !== null ? number_format($linha['tendencia_pct'], 1, ',', '.') . '%' : '—' }}</td>
                <td class="text-end">{{ $linha['realizado_pct'] !== null ? number_format($linha['realizado_pct'], 1, ',', '.') . '%' : '—' }}</td>
                @if($comAderencia)
                @php
                    $aderenciaClasse = '';
                    if ($linha['aderencia_periodo'] !== null && $limiarOtimoCel !== null && $limiarAtencaoCel !== null) {
                        $aderenciaClasse = match(true) {
                            $linha['aderencia_periodo'] >= $limiarOtimoCel => 'text-success',
                            $linha['aderencia_periodo'] >= $limiarAtencaoCel => 'text-warning',
                            default => 'text-danger',
                        };
                    }
                @endphp
                <td class="text-end fw-semibold {{ $aderenciaClasse }}">{{ $linha['aderencia_periodo'] !== null ? number_format($linha['aderencia_periodo'], 0, ',', '.') . '%' : '—' }}</td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
