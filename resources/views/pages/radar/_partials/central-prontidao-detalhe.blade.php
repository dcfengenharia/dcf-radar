{{--
    Painel expansível inline da Central de Prontidão (Ciclo 15, Etapas B.2/B.3)
    — mesmo padrão visual de resources/views/pages/radar/_partials/plano-acao-detalhe.blade.php
    (painel inline, nunca modal, nunca nova página). SOMENTE LEITURA — nenhum
    controle de escrita aqui (sem marcar checklist, sem resolver Restrição,
    sem editar PlanoAcao/Suprimento/Engenharia). Toda ação real acontece via
    deep-link pra tela já existente.

    Espera: $view (App\Support\CentralProntidao\AtividadeProntidaoView) —
    já consolidado pela B.1/B.2, NENHUMA consulta nova é feita aqui. Todo
    dado exibido (inclusive "vencida" e a cor de risco/atraso de Suprimento)
    é derivado por computação pura (Carbon::isPast(), comparação de enum já
    carregado) sobre propriedades já presentes no DTO — nunca dispara
    lazy loading nem query adicional.

    Cores semânticas (Ciclo 15, B.3 — só dentro deste partial, a paleta da
    linha principal da B.2 NÃO foi tocada):
    - vermelho/danger  = bloqueio operacional (Restrição bloqueante, vencida, Suprimento Atrasado)
    - amarelo/warning  = pendência/risco (Checklist pendente, Suprimento EmRisco)
    - azul/info        = alerta contextual, nunca bloqueia (Restrição não-bloqueante, Plano de Ação)
    - cinza/secondary  = informação neutra (Engenharia não emitida, sem dado)
--}}
<div class="p-3 bg-light rounded">
    {{-- 1. Restrições bloqueantes --}}
    <div class="mb-3">
        <h6 class="fw-semibold mb-2">
            <i class="bx bx-block me-1 text-danger"></i>Restrições Bloqueantes Abertas
            @if (! empty($view->restricoesBloqueantes))
            <span class="badge bg-label-danger">{{ count($view->restricoesBloqueantes) }}</span>
            @endif
        </h6>
        @if (empty($view->restricoesBloqueantes))
        <p class="text-muted small mb-0">Nenhuma.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th>Responsável</th>
                        <th>Prazo</th>
                        <th>Origem</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($view->restricoesBloqueantes as $r)
                    @php $vencida = $r->prazoLimite?->isPast() ?? false; @endphp
                    <tr wire:key="cp-restr-bloq-{{ $view->atividadeId }}-{{ $r->id }}" class="{{ $vencida ? 'table-danger' : '' }}">
                        <td>{{ $r->descricao }}</td>
                        <td>{{ $r->responsavel ?? '—' }}</td>
                        <td>
                            {{ $r->prazoLimite?->format('d/m/Y') ?? '—' }}
                            @if ($vencida)
                            <span class="badge bg-danger ms-1">Vencida</span>
                            @endif
                        </td>
                        <td><span class="badge bg-label-secondary">{{ $r->origem->label() }}</span></td>
                        <td>
                            <a href="{{ route('radar.restricoes') }}" class="btn btn-sm btn-outline-primary" @click.stop>
                                Abrir Restrição
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 2. Checklist --}}
    <div class="mb-3">
        <h6 class="fw-semibold mb-2">
            <i class="bx bx-list-check me-1 {{ $view->checklistTotal > 0 && $view->checklistConcluido < $view->checklistTotal ? 'text-warning' : 'text-muted' }}"></i>
            Checklist de Prontidão
            @if ($view->checklistTotal > 0)
            <span class="badge bg-label-{{ $view->checklistConcluido === $view->checklistTotal ? 'success' : 'warning' }}">
                {{ $view->checklistConcluido }}/{{ $view->checklistTotal }}
            </span>
            @endif
        </h6>
        @if ($view->checklistTotal === 0)
        <p class="text-muted small mb-0">Esta obra não tem itens de checklist cadastrados.</p>
        @elseif (empty($view->checklistPendentes))
        <p class="text-success small mb-0"><i class="bx bx-check me-1"></i>Todos os itens concluídos.</p>
        @else
        <ul class="mb-0 small">
            @foreach ($view->checklistPendentes as $item)
            <li>{{ $item }}</li>
            @endforeach
        </ul>
        @endif
    </div>

    {{-- 3. Restrições não-bloqueantes — alerta contextual, NUNCA impeditivo --}}
    <div class="mb-3">
        <h6 class="fw-semibold mb-2">
            <i class="bx bx-info-circle me-1 text-info"></i>Restrições Não-Bloqueantes
        </h6>
        @if (empty($view->restricoesNaoBloqueantes))
        <p class="text-muted small mb-0">Nenhuma.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th>Responsável</th>
                        <th>Prazo</th>
                        <th>Origem</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($view->restricoesNaoBloqueantes as $r)
                    <tr wire:key="cp-restr-naobloq-{{ $view->atividadeId }}-{{ $r->id }}">
                        <td>{{ $r->descricao }}</td>
                        <td>{{ $r->responsavel ?? '—' }}</td>
                        <td>{{ $r->prazoLimite?->format('d/m/Y') ?? '—' }}</td>
                        <td><span class="badge bg-label-secondary">{{ $r->origem->label() }}</span></td>
                        <td>
                            <a href="{{ route('radar.restricoes') }}" class="btn btn-sm btn-outline-secondary" @click.stop>
                                Abrir Restrição
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 4. Planos de Ação relacionados — alerta contextual, NUNCA impeditivo --}}
    <div class="mb-3">
        <h6 class="fw-semibold mb-2">
            <i class="bx bx-task me-1 text-info"></i>Planos de Ação Relacionados
            @if (! empty($view->planoAcoesAbertas))
            <span class="badge bg-label-info">{{ count($view->planoAcoesAbertas) }}</span>
            @endif
        </h6>
        @if (empty($view->planoAcoesAbertas))
        <p class="text-muted small mb-0">Nenhum.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Severidade</th>
                        <th>Última Reconciliação</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($view->planoAcoesAbertas as $pa)
                    <tr wire:key="cp-planoacao-{{ $view->atividadeId }}-{{ $pa->id }}">
                        <td>{{ $pa->titulo }}</td>
                        <td>
                            @if ($pa->severidade)
                            <span class="badge bg-label-{{ $pa->severidade->cor() }}">
                                {{ $pa->severidade->emoji() }} {{ $pa->severidade->label() }}
                            </span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($pa->resultadoUltimaReconciliacao)
                            {{ $pa->resultadoUltimaReconciliacao->label() }}
                            @else
                            <span class="text-muted">Nunca reconciliada</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('radar.plano-acao', ['regra' => $pa->regraId]) }}" class="btn btn-sm btn-outline-primary" @click.stop>
                                Ver Plano de Ação
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 5. Suprimentos — alerta contextual, NUNCA impeditivo. Atrasado (fato
         consumado) é visualmente mais grave que EmRisco (ainda recuperável)
         — mesma distinção já usada por SuprimentoScheduler::calcularStatus(),
         só refletida aqui na cor, nunca recalculada. --}}
    <div class="mb-3">
        <h6 class="fw-semibold mb-2">
            <i class="bx bx-package me-1 text-warning"></i>Suprimentos em Risco/Atrasados
            @if (! empty($view->suprimentos))
            <span class="badge bg-label-warning">{{ count($view->suprimentos) }}</span>
            @endif
        </h6>
        @if (empty($view->suprimentos))
        <p class="text-muted small mb-0">Nenhum.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Status</th>
                        <th>Necessidade</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($view->suprimentos as $s)
                    @php $atrasado = $s->status === \App\Enums\StatusItemSuprimento::Atrasado; @endphp
                    <tr wire:key="cp-suprimento-{{ $view->atividadeId }}-{{ $s->itemId }}">
                        <td>{{ $s->nome }}</td>
                        <td><span class="badge bg-label-{{ $atrasado ? 'danger' : 'warning' }}">{{ $s->status->label() }}</span></td>
                        <td>{{ $s->necessidade?->format('d/m/Y') ?? '—' }}</td>
                        <td>
                            <a href="{{ route('radar.suprimentos') }}" class="btn btn-sm btn-outline-secondary" @click.stop>
                                Ver Suprimentos
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 6. Engenharia — alerta contextual, NUNCA impeditivo. Sem rota de
         Lista de Documentos disponível hoje (achado do Ciclo 15/B.2) —
         deliberadamente sem link, nunca uma rota inventada. --}}
    <div class="mb-3">
        <h6 class="fw-semibold mb-2">
            <i class="bx bx-file me-1 text-secondary"></i>Engenharia — Documentos Atrasados/Não Emitidos
            @if (! empty($view->engenharia))
            <span class="badge bg-label-secondary">{{ count($view->engenharia) }}</span>
            @endif
        </h6>
        @if (empty($view->engenharia))
        <p class="text-muted small mb-0">Nenhum.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Situação</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($view->engenharia as $doc)
                    <tr wire:key="cp-engenharia-{{ $view->atividadeId }}-{{ $doc->documentoId }}">
                        <td>{{ $doc->codigo ?? '—' }}</td>
                        <td>
                            @if (! $doc->emitido)
                            <span class="badge bg-label-secondary">Não emitido</span>
                            @endif
                            @if ($doc->atrasado)
                            <span class="badge bg-label-danger">Atrasado</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 7. Link para o Lookahead — somente navegação, rota já existente,
         sem parâmetro de atividade (Lookahead não tem deep-link por uid
         hoje — achado do Ciclo 15/B.2, não alterado nesta etapa). --}}
    <a href="{{ route('radar.lookahead') }}" class="btn btn-sm btn-outline-primary" @click.stop>
        <i class="bx bx-link-external me-1"></i>Ver atividade no Lookahead
    </a>
</div>
