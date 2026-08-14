{{--
    Histórico de Importações — partial único compartilhado entre
    ⚡cronograma.blade.php, ⚡relatorio-importar-avanco.blade.php e
    ⚡obra-detalhe.blade.php (Fase 3, Etapa 5). Cada página filtra as
    importações conforme o contexto (tipo/obra), mas a apresentação da
    lista é sempre esta mesma — nunca duplicar markup de tabela.

    Espera:
    - $importacoes: LengthAwarePaginator de CronogramaImportacao, com
      'autor' e 'healthCheck' já eager-loaded (evita N+1).

    Situação da análise (nunca inventa Score pra importação antiga):
    - sem healthCheck            -> "Sem análise registrada"
    - healthCheck sem score      -> "Análise disponível — Score indisponível"
    - healthCheck com score      -> "Analisado" + Score/Faixa/Cobertura reais

    Δ Score (Fase 3.1, item 6 — "Timeline da Saúde do Cronograma"): calculado
    em memória, comparando cada importação com a imediatamente anterior da
    MESMA obra e do MESMO tipo (mesma regra de App\Models\CronogramaImportacao::
    importacaoAnterior()), nunca recalculando Health Check/Score. Pra evitar
    N+1 (uma query por linha), 1 única query extra busca TODO o histórico de
    importações da obra (id/tipo/importado_em/score), ordenada, e o delta de
    cada linha da página atual é resolvido em PHP puro sobre essa lista —
    o custo é O(total de importações da obra), não O(linhas da página), mas
    ainda é 1 query, não N.
--}}
@if ($importacoes->isEmpty())
<p class="text-muted mb-0">Nenhuma importação de cronograma registrada ainda.</p>
@else
@php
    $obraIdHistorico = $importacoes->isNotEmpty() ? $importacoes->first()->obra_id : null;

    $deltaScorePorId = [];
    if ($obraIdHistorico) {
        $todasDoObra = \App\Models\CronogramaImportacao::where('obra_id', $obraIdHistorico)
            ->select('id', 'tipo', 'importado_em')
            ->with('healthCheck:cronograma_importacao_id,score')
            ->orderBy('importado_em')
            ->get();

        $ultimoPorTipo = [];
        foreach ($todasDoObra as $itemHistorico) {
            $tipoChave = $itemHistorico->tipo?->value;
            $scoreAtualHistorico = $itemHistorico->healthCheck?->score;
            $anteriorHistorico = $ultimoPorTipo[$tipoChave] ?? null;

            $deltaScorePorId[$itemHistorico->id] = ($anteriorHistorico && $anteriorHistorico['score'] !== null && $scoreAtualHistorico !== null)
                ? $scoreAtualHistorico - $anteriorHistorico['score']
                : null;

            $ultimoPorTipo[$tipoChave] = ['score' => $scoreAtualHistorico];
        }
    }
@endphp
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Data</th>
                <th>Arquivo</th>
                <th>Usuário</th>
                <th>Tipo</th>
                <th class="text-center">Criadas</th>
                <th class="text-center">Atualizadas</th>
                <th class="text-center">Removidas</th>
                <th class="text-center">Score</th>
                <th class="text-center">Δ Score</th>
                <th>Faixa</th>
                <th class="text-center">Cobertura</th>
                <th>Situação</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($importacoes as $imp)
                @php
                    $hc = $imp->healthCheck;
                    $score = $hc?->scoreResultado();
                @endphp
                <tr wire:key="historico-importacao-{{ $imp->id }}"
                    style="cursor:pointer"
                    onclick="window.location='{{ route('radar.importacoes.show', $imp) }}'">
                    <td>{{ $imp->importado_em->format('d/m/Y H:i') }}</td>
                    <td>{{ $imp->arquivo ?? '—' }}</td>
                    <td>{{ $imp->autor ? "{$imp->autor->first_name} {$imp->autor->last_name}" : '—' }}</td>
                    <td>{{ $imp->tipo?->label() ?? '—' }}</td>
                    <td class="text-center">{{ $imp->criadas }}</td>
                    <td class="text-center">{{ $imp->atualizadas }}</td>
                    <td class="text-center">{{ $imp->removidas }}</td>
                    <td class="text-center">
                        @if ($score)
                            <span class="fw-semibold">{{ $score->score }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @php $delta = $deltaScorePorId[$imp->id] ?? null; @endphp
                        @if ($delta === null)
                            <span class="text-muted" title="Primeira importação deste tipo, ou sem Score para comparar">—</span>
                        @elseif ($delta > 0)
                            <span class="text-success"><i class="bx bx-up-arrow-alt"></i> +{{ $delta }}</span>
                        @elseif ($delta < 0)
                            <span class="text-danger"><i class="bx bx-down-arrow-alt"></i> {{ $delta }}</span>
                        @else
                            <span class="text-muted"><i class="bx bx-minus"></i> 0</span>
                        @endif
                    </td>
                    <td>
                        @if ($score)
                            <span class="badge bg-{{ $score->faixa->cor() }}">{{ $score->faixa->label() }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if ($score && $score->cobertura !== null)
                            {{ $score->cobertura }}%
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if (! $hc)
                            <span class="badge bg-label-secondary">Sem análise registrada</span>
                        @elseif (! $score)
                            <span class="badge bg-label-warning">Análise disponível — Score indisponível</span>
                        @else
                            <span class="badge bg-label-success">Analisado</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($importacoes->hasPages())
<div class="mt-3">
    {{ $importacoes->links() }}
</div>
@endif
@endif
