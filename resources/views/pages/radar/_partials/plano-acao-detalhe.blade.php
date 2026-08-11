{{--
    Painel expansível inline de um PlanoAcao (Fase 4.3, Etapa C — somente
    leitura — e Etapa D — botão "Editar ação", o formulário em si vive no
    modal de ⚡plano-acao.blade.php) — incluído de dentro de
    ⚡plano-acao.blade.php, uma vez por ação, dentro da linha de detalhe
    controlada por Alpine (x-show="aberto").

    Espera:
    - $acao: PlanoAcao já com ultimaReconciliacao.importacao,
      importacaoOrigem.healthCheck, reconciliacoes (ordenadas desc) +
      reconciliacoes.importacao eager-loaded pela página (⚡plano-acao.blade.php).

    `atividadesRelacionadas()` (Etapa A) é chamado aqui, 1x por ação
    renderizada na página atual (nunca por toda a obra — a listagem já é
    paginada) — reaproveitado como já existe, sem duplicar a resolução
    uid→Atividade em Blade.

    Ciclo 11 (Etapa B) — "Transformar em Restrição": espera também
    $foraDoCronogramaPorUid (Collection external_uid=>bool) e
    $restricoesVinculadas (Collection de Restricao já filtrada pra ESTA
    ação) — ambas calculadas 1x pra TODA a página em
    ⚡plano-acao.blade.php (#[Computed] foraDoCronogramaPorUid()/
    restricoesVinculadasPorAcao()), nunca com uma query nova aqui dentro
    do partial (achado de N+1 corrigido antes de entrar em produção —
    ver CLAUDE.md).
--}}
@php
    $paDetSeveridade = $acao->severidadeDaRegra();
    $paDetUltimaReconciliacao = $acao->ultimaReconciliacao;
    $paDetImportacaoAnalisada = $paDetUltimaReconciliacao?->importacao ?? $acao->importacaoOrigem;
    $paDetAtividades = $acao->atividadesRelacionadas();
    $paDetUidsEncontrados = $paDetAtividades->pluck('external_uid')->all();
    $paDetUidsSemAtividade = array_values(array_diff($acao->uids_referencia ?? [], $paDetUidsEncontrados));

    $paDetRestricoesVinculadas = $restricoesVinculadas->keyBy('atividade_id');
    $paDetPodeTransformar = auth()->user()->can('update', $acao)
        && auth()->user()->can('create', [\App\Models\Restricao::class, $acao->obra_id]);
@endphp
<div class="p-3 bg-light rounded">
    {{-- A. Problema --}}
    <div class="d-flex align-items-start justify-content-between mb-3">
        <div>
            <h6 class="fw-semibold mb-1">Problema</h6>
            <div>{{ $acao->titulo }}</div>
            <div class="text-muted small mt-1">{{ $acao->recomendacao }}</div>
        </div>
        {{-- Só UX — a proteção real é o authorize('update', ...) dentro de confirmarEditar(). --}}
        @can('update', $acao)
        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap ms-3"
                wire:click="abrirModalEditar('{{ $acao->id }}')">
            <i class="bx bx-edit me-1"></i>Editar ação
        </button>
        @endcan
    </div>

    {{-- B. Identificação --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-2">
            <div class="text-muted small">Regra</div>
            <code>{{ $acao->regra_id }}</code>
        </div>
        <div class="col-6 col-md-2">
            <div class="text-muted small">Severidade</div>
            @if ($paDetSeveridade)
            <span class="badge bg-label-{{ $paDetSeveridade->cor() }}">
                {{ $paDetSeveridade->emoji() }} {{ $paDetSeveridade->label() }}
            </span>
            @else
            <span class="text-muted">—</span>
            @endif
        </div>
        <div class="col-6 col-md-2">
            <div class="text-muted small">Status da Ação</div>
            <span class="badge bg-label-{{ match ($acao->status) {
                \App\Enums\StatusPlanoAcao::Aberta => 'primary',
                \App\Enums\StatusPlanoAcao::Resolvida => 'success',
                \App\Enums\StatusPlanoAcao::Cancelada => 'secondary',
            } }}">{{ $acao->status->label() }}</span>
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted small">Situação da Última Análise</div>
            @if (! $paDetUltimaReconciliacao)
            <span class="badge bg-label-light text-muted">Nunca reconciliada</span>
            @else
            <span class="badge bg-label-{{ match ($paDetUltimaReconciliacao->resultado) {
                \App\Enums\ResultadoReconciliacaoPlanoAcao::Persistente => 'secondary',
                \App\Enums\ResultadoReconciliacaoPlanoAcao::Agravado => 'danger',
                \App\Enums\ResultadoReconciliacaoPlanoAcao::Alterado => 'warning',
                \App\Enums\ResultadoReconciliacaoPlanoAcao::Resolvido => 'success',
            } }}">{{ $paDetUltimaReconciliacao->resultado->label() }}</span>
            @endif
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted small">UIDs Referenciados</div>
            {{ count($acao->uids_referencia ?? []) }}
        </div>
    </div>

    <div class="row g-3 mb-3">
        {{-- C. Importação de origem --}}
        <div class="col-md-6">
            <div class="text-muted small">Importação de Origem</div>
            @if ($acao->importacaoOrigem)
            <a href="{{ route('radar.importacoes.show', $acao->importacaoOrigem) }}">
                {{ $acao->importacaoOrigem->importado_em->format('d/m/Y H:i') }}
                @if ($acao->importacaoOrigem->arquivo)
                — {{ $acao->importacaoOrigem->arquivo }}
                @endif
            </a>
            @else
            <span class="text-muted">—</span>
            @endif
        </div>

        {{-- D. Última importação analisada --}}
        <div class="col-md-6">
            <div class="text-muted small">Última Importação Analisada</div>
            @if ($paDetImportacaoAnalisada)
            <a href="{{ route('radar.importacoes.show', $paDetImportacaoAnalisada) }}">
                {{ $paDetImportacaoAnalisada->importado_em->format('d/m/Y H:i') }}
            </a>
            @else
            <span class="text-muted">—</span>
            @endif
        </div>
    </div>

    {{-- E. Atividades relacionadas --}}
    <div class="mb-3">
        <h6 class="fw-semibold mb-2">Atividades Relacionadas</h6>
        @if (empty($acao->uids_referencia))
        <p class="text-muted small mb-0">Nenhum UID referenciado.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        @if ($paDetPodeTransformar)
                        <th style="width:2rem"></th>
                        @endif
                        <th>UID</th>
                        <th>Código/EAP</th>
                        <th>Nome</th>
                        <th>Situação</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paDetAtividades as $paDetAtividade)
                    @php
                        $paDetEhForaDoCronograma = (bool) ($foraDoCronogramaPorUid[$paDetAtividade->external_uid] ?? false);
                        $paDetRestricaoVinculada = $paDetRestricoesVinculadas[$paDetAtividade->id] ?? null;
                        $paDetElegivel = $paDetPodeTransformar && ! $paDetEhForaDoCronograma && ! $paDetRestricaoVinculada;
                    @endphp
                    <tr wire:key="pa-atividade-{{ $acao->id }}-{{ $paDetAtividade->id }}">
                        @if ($paDetPodeTransformar)
                        <td>
                            @if ($paDetElegivel)
                            <input type="checkbox" class="form-check-input" x-model="selecionadas" value="{{ $paDetAtividade->id }}">
                            @endif
                        </td>
                        @endif
                        <td><code>{{ $paDetAtividade->external_uid }}</code></td>
                        <td>{{ $paDetAtividade->codigo_cronograma ?? '—' }}</td>
                        <td>{{ $paDetAtividade->nome }}</td>
                        <td>
                            @if ($paDetEhForaDoCronograma)
                            <span class="badge bg-label-secondary">Fora do cronograma — não elegível</span>
                            @elseif ($paDetRestricaoVinculada)
                            <span class="badge bg-label-info">Restrição: {{ $paDetRestricaoVinculada->status->label() }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    @foreach ($paDetUidsSemAtividade as $paDetUidAusente)
                    <tr>
                        @if ($paDetPodeTransformar)
                        <td></td>
                        @endif
                        <td><code>{{ $paDetUidAusente }}</code></td>
                        <td colspan="3" class="text-muted">Atividade não encontrada no cadastro atual</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($paDetPodeTransformar)
        <div class="mt-2">
            {{-- wire:target com o id da ação (não só o nome do método) evita
                 que o botão de OUTRA linha entre em estado de loading quando
                 esta ação é transformada — mesmo idioma já usado no botão
                 "Salvar" do modal de edição (wire:loading.attr="disabled" +
                 spinner via wire:target), só escopado por linha aqui. --}}
            <button type="button" class="btn btn-sm btn-outline-primary"
                    x-bind:disabled="selecionadas.length === 0"
                    wire:loading.attr="disabled"
                    wire:target="transformarEmRestricoes('{{ $acao->id }}')"
                    @click="$wire.call('transformarEmRestricoes', '{{ $acao->id }}', selecionadas); selecionadas = []">
                <span wire:loading wire:target="transformarEmRestricoes('{{ $acao->id }}')">
                    <span class="spinner-border spinner-border-sm me-1"></span>
                </span>
                <i class="bx bx-shield-plus me-1"></i>Transformar selecionadas em Restrição
            </button>
        </div>
        @endif
        @endif
    </div>

    {{-- F/G. Histórico de reconciliações --}}
    <div>
        <h6 class="fw-semibold mb-2">Histórico de Reconciliações</h6>
        @if ($acao->reconciliacoes->isEmpty())
        <p class="text-muted small mb-0">Esta ação ainda não passou por nenhuma reconciliação.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Importação</th>
                        <th>Resultado</th>
                        <th>Status Anterior → Novo</th>
                        <th>Qtd. Anterior → Atual</th>
                        <th>UIDs Anteriores</th>
                        <th>UIDs Atuais</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($acao->reconciliacoes as $paDetEvento)
                    <tr wire:key="plano-acao-reconciliacao-{{ $paDetEvento->id }}">
                        <td>{{ $paDetEvento->created_at->format('d/m/Y H:i') }}</td>
                        <td>
                            @if ($paDetEvento->importacao)
                            <a href="{{ route('radar.importacoes.show', $paDetEvento->importacao) }}">
                                {{ $paDetEvento->importacao->importado_em->format('d/m/Y H:i') }}
                            </a>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $paDetEvento->resultado->label() }}</td>
                        <td>{{ $paDetEvento->status_anterior->label() }} → {{ $paDetEvento->status_novo->label() }}</td>
                        <td>{{ $paDetEvento->quantidade_anterior }} → {{ $paDetEvento->quantidade_atual }}</td>
                        <td>
                            <span class="text-truncate d-inline-block" style="max-width:160px" title="{{ implode(', ', $paDetEvento->uids_anteriores ?? []) }}">
                                {{ implode(', ', $paDetEvento->uids_anteriores ?? []) }}
                            </span>
                        </td>
                        <td>
                            <span class="text-truncate d-inline-block" style="max-width:160px" title="{{ implode(', ', $paDetEvento->uids_atuais ?? []) }}">
                                {{ implode(', ', $paDetEvento->uids_atuais ?? []) }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
