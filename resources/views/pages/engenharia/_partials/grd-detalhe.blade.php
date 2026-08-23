{{--
    Ciclo 18, Etapa 18.5.2 — detalhe de uma GRD: editor de Rascunho (3
    blocos: Documentos / Destinatários / Matriz) ou visão somente-leitura
    de uma Emitida (snapshots + estado de recolhimento derivado). Toda
    mutação delega para as Actions já aprovadas (18.5.1/18.5.1.HARDENING)
    — nenhuma regra de negócio nova aqui.
--}}
@php
    $grd = $this->grdAberta;
    $podeEditar = Auth::user()->temPermissaoNaObra($obraId, 'engenharia.pacotes', 'editar');
@endphp

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <button class="btn btn-outline-secondary btn-sm mb-2" wire:click="fecharGrd">
            <i class="bx bx-arrow-back me-1"></i>Voltar
        </button>
        <h5 class="mb-0">
            @if ($grd->estaEmitida())
                GRD-{{ str_pad($grd->numero, 3, '0', STR_PAD_LEFT) }}
                <span class="badge bg-label-success ms-2">Emitida</span>
            @else
                Rascunho de GRD
                <span class="badge bg-label-secondary ms-2">Número será atribuído na emissão</span>
            @endif
        </h5>
    </div>
    @if ($grd->estaRascunho() && $podeEditar)
        <button class="btn btn-success" wire:click="abrirModalEmitir">
            <i class="bx bx-send me-1"></i>Emitir GRD
        </button>
    @elseif ($grd->estaEmitida())
        <button class="btn btn-outline-danger" wire:click="exportarPdfGrd('{{ $grd->id }}')">
            <i class="bx bxs-file-pdf me-1"></i>PDF
        </button>
    @endif
</div>

@if ($grd->estaEmitida())
    {{-- ===================== VISÃO SOMENTE LEITURA (histórico imutável) ===================== --}}
    <div class="card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-3"><strong>Emitida em:</strong> {{ $grd->emitida_em?->format('d/m/Y H:i') }}</div>
            {{-- Este bloco só renderiza quando estaEmitida() — EmitirGrd sempre grava
                 emitida_por no fluxo normal, então null aqui só pode significar que o
                 usuário foi removido depois (FK emitida_por é nullOnDelete), nunca "nunca
                 informado". Mesmo texto/precedente já usado em
                 ⚡inconsistencias-avanco.blade.php / ⚡lookahead.blade.php / ⚡documentos-engenharia.blade.php. --}}
            <div class="col-md-3"><strong>Emitida por:</strong> {{ $grd->emitidoPor ? "{$grd->emitidoPor->first_name} {$grd->emitidoPor->last_name}" : 'Usuário removido' }}</div>
            <div class="col-md-6"><strong>Observação:</strong> {{ $grd->observacao ?: '—' }}</div>
        </div>
    </div>

    {{-- Etapa 18.5.8/18.5.9 — Comprovantes de Entrega + Aceite de Recebimento,
         1 linha por destinatário (nunca 1 por linha da matriz abaixo, pra "não
         poluir a matriz" — instrução explícita). Existe só porque a GRD já
         está Emitida (emissão == fato de entrega neste domínio, ver docblock
         de MontarDadosComprovanteEntrega). Aceite/assinatura capturada aqui
         NUNCA é assinatura digital ICP-Brasil. --}}
    <div class="card mb-3">
        <div class="card-body">
            <h6 class="mb-2">Entregas e Aceites de Recebimento</h6>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Destinatário</th>
                            <th>Status do aceite</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grd->destinatarios as $gd)
                            <tr wire:key="aceite-linha-{{ $gd->id }}">
                                <td>{{ $gd->nome_snapshot }}</td>
                                <td>
                                    @if ($this->aceitesAtivosDaGrdAberta->get($gd->id))
                                        <span class="badge bg-label-success">Aceite registrado</span>
                                        <span class="text-muted small d-block">{{ $this->aceitesAtivosDaGrdAberta->get($gd->id)->tipo_aceite->label() }} — {{ $this->aceitesAtivosDaGrdAberta->get($gd->id)->ocorrido_em->format('d/m/Y H:i') }}</span>
                                    @else
                                        <span class="badge bg-label-secondary">Sem aceite</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary" wire:click="exportarComprovanteEntrega('{{ $gd->id }}')">
                                        <i class="bx bxs-file-pdf me-1"></i>Comprovante
                                    </button>
                                    @if ($this->aceitesAtivosDaGrdAberta->get($gd->id) && $podeEditar)
                                        <button class="btn btn-sm btn-outline-danger" wire:click="abrirModalInvalidarAceite('{{ $this->aceitesAtivosDaGrdAberta->get($gd->id)->id }}')">Invalidar</button>
                                    @elseif ($podeEditar)
                                        <button class="btn btn-sm btn-primary" wire:click="abrirModalAceite('{{ $gd->id }}')">Registrar recebimento</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Descrição</th>
                        <th>Revisão</th>
                        <th>Destinatário</th>
                        <th class="text-center">Entregue</th>
                        <th class="text-center">Recolhido</th>
                        <th class="text-center">Pendente</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grd->itens as $item)
                        @foreach ($grd->destinatarios as $gd)
                            @php
                                $dist = $this->distribuicoesDaGrdAberta->get($item->id . '|' . $gd->id);
                            @endphp
                            @continue(! $dist)
                            <tr wire:key="dist-{{ $dist->id }}">
                                <td class="fw-semibold">{{ $item->codigo_documento_snapshot }}</td>
                                <td>{{ $item->descricao_documento_snapshot }}</td>
                                <td>{{ $item->revisao_snapshot }}</td>
                                <td>
                                    {{ $gd->nome_snapshot }}
                                    @if ($gd->empresa_snapshot) <br><span class="text-muted small">{{ $gd->empresa_snapshot }}</span> @endif
                                </td>
                                <td class="text-center">{{ $dist->quantidadeEntregue() }}</td>
                                <td class="text-center">{{ $dist->quantidadeRecolhida() }}</td>
                                <td class="text-center">{{ $dist->quantidadePendente() }}</td>
                                <td>
                                    @php($estado = $dist->estado())
                                    @if ($estado === 'recolhido')
                                        <span class="badge bg-label-success">Recolhido</span>
                                    @elseif ($estado === 'nao_localizado')
                                        <span class="badge bg-label-warning">Não localizado</span>
                                    @else
                                        <span class="badge bg-label-secondary">Pendente</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($dist->quantidadePendente() > 0 && $podeEditar)
                                        <button class="btn btn-sm btn-outline-primary" wire:click="abrirModalRecolhimento('{{ $dist->id }}')">
                                            Registrar recolhimento
                                        </button>
                                    @endif
                                </td>
                            </tr>
                            @if ($dist->recolhimentos->isNotEmpty())
                                <tr wire:key="hist-{{ $dist->id }}">
                                    <td colspan="9" class="bg-light">
                                        <div class="small">
                                            <strong>Histórico de tentativas de recolhimento:</strong>
                                            <ul class="mb-0 mt-1">
                                                @foreach ($dist->recolhimentos->sortByDesc('created_at') as $evento)
                                                    <li>
                                                        {{ $evento->ocorrido_em?->format('d/m/Y H:i') }} —
                                                        @if ($evento->resultado->value === 'recolhido')
                                                            <span class="text-success">Recolhido</span>
                                                        @else
                                                            <span class="text-warning">Não localizado</span>
                                                        @endif
                                                        (qtd. {{ $evento->quantidade }})
                                                        — registrado por {{ $evento->registradoPor ? "{$evento->registradoPor->first_name} {$evento->registradoPor->last_name}" : 'Usuário removido' }}
                                                        @if ($evento->observacao) — "{{ $evento->observacao }}" @endif
                                                        <button type="button" class="btn btn-link btn-sm p-0 ms-1" wire:key="comprovante-recolhimento-{{ $evento->id }}" wire:click="exportarComprovanteRecolhimento('{{ $evento->id }}')">Comprovante</button>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    {{-- ===================== EDITOR DE RASCUNHO ===================== --}}

    @if ($grd->itens->isEmpty() || $grd->destinatarios->isEmpty() || $this->distribuicoesDaGrdAberta->isEmpty())
        <div class="alert alert-info">
            Adicione ao menos 1 documento, 1 destinatário e marque ao menos 1 distribuição na matriz antes de emitir.
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Documentos</span>
                    @if ($podeEditar)
                        <button class="btn btn-sm btn-primary" wire:click="abrirModalAdicionarDocumento">
                            <i class="bx bx-plus"></i> Adicionar
                        </button>
                    @endif
                </div>
                <ul class="list-group list-group-flush">
                    @forelse ($grd->itens as $item)
                        <li class="list-group-item d-flex justify-content-between align-items-start" wire:key="item-{{ $item->id }}">
                            <div>
                                <strong>{{ $item->revisao->documento->codigo }}</strong> — {{ $item->revisao->documento->descricao }}
                                <br><span class="text-muted small">Revisão {{ $item->revisao->revisao }}</span>
                                @if (! $item->revisao->estaLiberadaParaConstrucao())
                                    <span class="badge bg-label-warning ms-1">Não liberada</span>
                                @else
                                    <span class="badge bg-label-success ms-1">Liberada</span>
                                @endif
                                @if (isset($this->alertasPorItem[$item->id]))
                                    <div class="text-danger small mt-1"><i class="bx bx-error-circle me-1"></i>{{ $this->alertasPorItem[$item->id] }}</div>
                                @endif
                            </div>
                            @if ($podeEditar)
                                <button class="btn btn-sm btn-outline-danger" wire:click="removerDocumento('{{ $item->id }}')">
                                    <i class="bx bx-trash"></i>
                                </button>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Nenhum documento adicionado ainda.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Destinatários</span>
                    @if ($podeEditar)
                        <button class="btn btn-sm btn-primary" wire:click="abrirModalDestinatario">
                            <i class="bx bx-plus"></i> Adicionar
                        </button>
                    @endif
                </div>
                <ul class="list-group list-group-flush">
                    @forelse ($grd->destinatarios as $gd)
                        <li class="list-group-item d-flex justify-content-between align-items-start" wire:key="gd-{{ $gd->id }}">
                            <div>
                                <strong>{{ $gd->destinatario->nome }}</strong>
                                @if ($gd->destinatario->empresa) <br><span class="text-muted small">{{ $gd->destinatario->empresa }}</span> @endif
                                @if ($gd->destinatario->setor) <span class="text-muted small"> — {{ $gd->destinatario->setor }}</span> @endif
                            </div>
                            @if ($podeEditar)
                                <button class="btn btn-sm btn-outline-danger" wire:click="removerDestinatarioDaGrd('{{ $gd->id }}')">
                                    <i class="bx bx-trash"></i>
                                </button>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Nenhum destinatário adicionado ainda.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    @if ($grd->itens->isNotEmpty() && $grd->destinatarios->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header">Matriz de distribuição — marque quem recebe cada documento</div>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 text-center align-middle">
                    <thead>
                        <tr>
                            <th class="text-start">Documento</th>
                            @foreach ($grd->destinatarios as $gd)
                                <th wire:key="col-{{ $gd->id }}">{{ $gd->destinatario->nome }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grd->itens as $item)
                            <tr wire:key="row-{{ $item->id }}">
                                <td class="text-start">{{ $item->revisao->documento->codigo }} ({{ $item->revisao->revisao }})</td>
                                @foreach ($grd->destinatarios as $gd)
                                    @php($dist = $this->distribuicoesDaGrdAberta->get($item->id . '|' . $gd->id))
                                    <td wire:key="cell-{{ $item->id }}-{{ $gd->id }}">
                                        @if ($dist)
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <input type="number" min="1" class="form-control form-control-sm text-center" style="width:64px"
                                                    value="{{ $dist->quantidade }}"
                                                    @if ($podeEditar)
                                                        wire:change="atualizarQuantidadeCelula('{{ $item->id }}', '{{ $gd->id }}', $event.target.value)"
                                                    @else
                                                        disabled
                                                    @endif
                                                >
                                                @if ($podeEditar)
                                                    <button class="btn btn-sm btn-outline-danger" wire:click="desmarcarCelula('{{ $item->id }}', '{{ $gd->id }}')" title="Remover desta distribuição">
                                                        <i class="bx bx-x"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        @elseif ($podeEditar)
                                            <button class="btn btn-sm btn-outline-secondary" wire:click="marcarCelula('{{ $item->id }}', '{{ $gd->id }}')">
                                                <i class="bx bx-plus"></i>
                                            </button>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <label class="form-label mb-1">Observação</label>
            <textarea class="form-control" rows="2" wire:change="atualizarObservacaoRascunho($event.target.value)" @disabled(! $podeEditar)>{{ $grd->observacao }}</textarea>
        </div>
    </div>

    @include('pages.engenharia._partials.grd-modal-adicionar-documento')
    @include('pages.engenharia._partials.grd-modal-destinatario')
    @include('pages.engenharia._partials.grd-modal-emitir')
@endif
