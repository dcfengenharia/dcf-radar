<?php

use App\Models\Work;
use App\Support\Gestao\CockpitEngenhariaQuery;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ciclo 22, Etapa 22.2 — Cockpit de Engenharia e Liberação para
 * Construção. Tela 100% SOMENTE LEITURA (Seção 2 do pedido) — o único
 * método público além de `mount()` é o computed que delega inteiramente
 * pra `App\Support\Gestao\CockpitEngenhariaQuery::resumo()`. Blade/
 * Livewire NUNCA calculam revisão vigente/liberação/documento
 * bloqueante/prontidão documental/GRD pendente/cópia obsoleta/mudança
 * de revisão/criticidade temporal — só apresentam o que o read model já
 * entrega pronto.
 *
 * **Permissão dedicada** (Seção 25): `gestao.engenharia|ver`, mesmo
 * mecanismo/limiar dos 2 Cockpits irmãos — auditado adversarialmente
 * antes de reaproveitar (ver `PermissaoVerGateAuditTest`, estendido
 * nesta etapa pro 3º slug gated).
 */
new class extends Component {
    public Work $obra;

    public int $horizontePrincipalDias = 28;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        abort_unless(
            Auth::user()->temPermissaoNaObra($this->obra->id, 'gestao.engenharia', 'ver'),
            403
        );
    }

    public function updatedHorizontePrincipalDias(): void
    {
        unset($this->resumo);
    }

    #[Computed]
    public function resumo(): \App\DTOs\Gestao\Cockpit\CockpitEngenharia
    {
        return CockpitEngenhariaQuery::resumo($this->obra, $this->horizontePrincipalDias);
    }
};

?>

<div>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="mb-0">Cockpit de Engenharia e Liberação para Construção</h4>
            <small class="text-muted">{{ $obra->name }} — o que a Engenharia precisa liberar para a obra executar</small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('radar.cockpit') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bx bx-grid-alt me-1"></i>Cockpit Executivo
            </a>
            <a href="{{ route('radar.cockpit-suprimentos') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bx bx-package me-1"></i>Cockpit de Suprimentos
            </a>
            <div>
                <label class="form-label small mb-0 me-2">Horizonte</label>
                <select wire:model.live="horizontePrincipalDias" class="form-select form-select-sm d-inline-block w-auto">
                    <option value="14">2 semanas</option>
                    <option value="28">4 semanas</option>
                    <option value="56">8 semanas</option>
                </select>
            </div>
        </div>
    </div>

    {{-- Resumo executivo (Seção 7) — indicadores objetivos, nunca um score --}}
    @php($r = $this->resumo->resumoExecutivo)
    <div class="row g-3 mb-4">
        <div class="col-6 col-md">
            <div class="card h-100 {{ $r['atividades_bloqueadas'] > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold {{ $r['atividades_bloqueadas'] > 0 ? 'text-danger' : '' }}">{{ $r['atividades_bloqueadas'] }}</div>
                    <div class="small text-muted">Atividades bloqueadas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $r['atividades_parciais'] > 0 ? 'border-warning' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $r['atividades_parciais'] }}</div>
                    <div class="small text-muted">Parcialmente liberadas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $r['atividades_informacao_insuficiente'] > 0 ? 'border-secondary' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $r['atividades_informacao_insuficiente'] }}</div>
                    <div class="small text-muted">Informação insuficiente</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $r['documentos_bloqueantes'] > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $r['documentos_bloqueantes'] }}</div>
                    <div class="small text-muted">Documentos bloqueantes</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $r['grds_aguardando_aceite'] > 0 ? 'border-secondary' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $r['grds_aguardando_aceite'] }}</div>
                    <div class="small text-muted">GRDs aguardando aceite</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $r['copias_obsoletas_pendentes'] > 0 ? 'border-warning' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $r['copias_obsoletas_pendentes'] }}</div>
                    <div class="small text-muted">Cópias obsoletas pendentes</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Bloco principal — "O que precisa da minha ação?" (Seção 8) --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bx bx-error-circle me-1"></i> O que precisa da minha ação?</h5></div>
        <div class="card-body">
            @forelse ($this->resumo->acaoPrioritaria as $i => $l)
                <div wire:key="acao-{{ $i }}" class="d-flex align-items-start gap-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <span class="badge bg-label-{{ $l['severidade']->cor() }} p-2"><i class="bx {{ $l['severidade']->icone() }}"></i></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $l['descricao'] }}</div>
                        <div class="small text-muted">
                            @if($l['diasParaRelevante'] !== null)
                                {{ $l['diasParaRelevante'] >= 0 ? "em {$l['diasParaRelevante']} dia(s)" : "atrasado há ".abs($l['diasParaRelevante'])." dia(s)" }}
                            @endif
                            @if($l['duplaRestricao'])
                                <span class="badge bg-label-dark ms-1">Também possui restrição de Suprimentos</span>
                            @endif
                        </div>
                    </div>
                    @if(!empty($l['deepLink']['rota'] ?? null))
                        <a href="{{ route($l['deepLink']['rota'], $l['deepLink']['parametros'] ?? []) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">Nenhuma atividade com bloqueio documental neste horizonte.</p>
            @endforelse
        </div>
    </div>

    {{-- Prontidão Documental 2/4/8 semanas (Seção 10) --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Prontidão Documental por Horizonte</h5></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr><th>Horizonte</th><th>Total</th><th>Liberadas</th><th>Parciais</th><th>Bloqueadas</th><th>Informação Insuficiente</th><th>% liberado (avaliável)</th></tr>
                </thead>
                <tbody>
                    @foreach ($this->resumo->prontidaoPorHorizonte as $h)
                        <tr wire:key="horiz-{{ $h->horizonteSemanas }}">
                            <td>{{ $h->horizonteSemanas }} semana(s)</td>
                            <td>{{ $h->total }}</td>
                            <td class="text-success">{{ $h->liberadas }}</td>
                            <td class="text-warning">{{ $h->parciais }}</td>
                            <td class="text-danger">{{ $h->bloqueadas }}</td>
                            <td class="text-secondary">{{ $h->informacaoInsuficiente }}</td>
                            <td>
                                @if($h->percentualLiberadoAvaliavel !== null)
                                    {{ $h->percentualLiberadoAvaliavel }}% <small class="text-muted">(de {{ $h->total - $h->informacaoInsuficiente }} avaliáveis)</small>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Matriz de Atividades (Seção 11-13) --}}
    <div class="card mb-4" x-data="{ aberto: null }">
        <div class="card-header">
            <h5 class="mb-0">Matriz de Atividades <small class="text-muted fw-normal">— top {{ $this->resumo->matrizAtividades->count() }} de {{ $this->resumo->matrizTotalAtividades }} no horizonte</small></h5>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th></th><th>Atividade</th><th>Início</th><th>Frente</th><th>Pacote</th><th>Documentos</th><th>Estado</th></tr></thead>
                <tbody>
                    @forelse ($this->resumo->matrizAtividades as $linha)
                        <tr wire:key="matriz-{{ $linha->atividadeId }}" style="cursor:pointer" @click="aberto = (aberto === '{{ $linha->atividadeId }}' ? null : '{{ $linha->atividadeId }}')">
                            <td><i class="bx" :class="aberto === '{{ $linha->atividadeId }}' ? 'bx-chevron-down' : 'bx-chevron-right'"></i></td>
                            <td>{{ $linha->codigo }} — {{ $linha->nome }}</td>
                            <td>{{ $linha->inicioPlanejado?->format('d/m/Y') }} @if($linha->diasParaInicio !== null) ({{ $linha->diasParaInicio }}d) @endif</td>
                            <td>{{ $linha->frenteNome ?? '—' }}</td>
                            <td>{{ $linha->pacoteNome ?? '—' }}</td>
                            <td>{{ $linha->totalLiberados() }}/{{ $linha->totalDocumentos() }} liberados</td>
                            <td>
                                <span class="badge bg-label-{{ match($linha->estado->value) {
                                    'liberada' => 'success', 'parcial' => 'warning', 'bloqueada' => 'danger', default => 'secondary' } }}">
                                    {{ $linha->estado->label() }}
                                </span>
                            </td>
                        </tr>
                        <tr wire:key="matriz-detalhe-{{ $linha->atividadeId }}" x-show="aberto === '{{ $linha->atividadeId }}'" x-cloak>
                            <td colspan="7" class="bg-light">
                                @forelse ($linha->documentos as $doc)
                                    <div class="d-flex justify-content-between align-items-center py-1 small">
                                        <span>
                                            {{ $doc->codigo }} @if($doc->revisaoTexto) ({{ $doc->revisaoTexto }}) @endif
                                            @if(!$doc->liberado)
                                                — {{ $doc->totalRevisoesDocumento > 1 ? 'existe uma revisão mais recente ainda não liberada para construção' : 'aguardando liberação para construção' }}
                                            @endif
                                        </span>
                                        <span class="badge bg-label-{{ $doc->liberado ? 'success' : 'danger' }}">{{ $doc->liberado ? 'Liberado' : 'Bloqueante' }}</span>
                                    </div>
                                @empty
                                    <span class="text-muted">Nenhum documento vinculado a esta atividade (informação insuficiente).</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">Nenhuma atividade relevante neste horizonte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- GRD / Aceite / Cópias obsoletas --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">GRDs aguardando aceite</h5></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Situação</th><th>Aguardando</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->grdAguardandoAceite as $f)
                                <tr wire:key="grd-{{ $f->entidadeId }}">
                                    <td>{{ $f->descricao }}</td>
                                    <td>{{ $f->diasParaRelevante !== null ? $f->diasParaRelevante.'d' : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-center text-muted py-3">Nenhuma GRD aguardando aceite.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Cópias obsoletas aguardando recolhimento</h5></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Situação</th><th>Deep-link</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->copiasObsoletasPendentes as $f)
                                <tr wire:key="obs-{{ $f->entidadeId }}">
                                    <td>{{ $f->descricao }}</td>
                                    <td>
                                        @if(!empty($f->deepLink['rota'] ?? null))
                                            <a href="{{ route($f->deepLink['rota'], $f->deepLink['parametros'] ?? []) }}" class="btn btn-sm btn-outline-primary">Recolher</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-center text-muted py-3">Nenhuma cópia obsoleta pendente de recolhimento.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Industrialização / Suprimentos — fatos neutros --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Industrialização <small class="text-muted fw-normal">— mudanças de revisão</small></h5></div>
                <div class="card-body">
                    @forelse ($this->resumo->industrializacaoComMudancaRevisao as $f)
                        <div wire:key="ind-{{ $f->entidadeId }}" class="py-1 small {{ !$loop->last ? 'border-bottom' : '' }}">{{ $f->descricao }}</div>
                    @empty
                        <p class="text-muted mb-0 small">Nenhum produto industrializado com mudança de revisão.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Suprimentos <small class="text-muted fw-normal">— pacotes dependendo de documento não liberado</small></h5></div>
                <div class="card-body">
                    @forelse ($this->resumo->suprimentoBloqueadoPorDocumento as $f)
                        <div wire:key="sup-{{ $f->entidadeId }}" class="py-1 small {{ !$loop->last ? 'border-bottom' : '' }}">{{ $f->descricao }}</div>
                    @empty
                        <p class="text-muted mb-0 small">Nenhum Pacote de Compra bloqueado por documento nesta obra.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Informação Insuficiente — NUNCA junto do bloco "saudável" (Seção 5/35) --}}
    <div class="card mb-4 border-secondary">
        <div class="card-header"><h5 class="mb-0 text-secondary"><i class="bx bx-question-mark me-1"></i> Informação Insuficiente</h5></div>
        <div class="card-body">
            @if($this->resumo->informacaoInsuficiente->isEmpty())
                <p class="text-muted mb-0">Todas as atividades do horizonte possuem documentação suficiente para determinar a prontidão documental.</p>
            @else
                <p class="mb-2">Existem atividades cuja prontidão documental não pode ser determinada (nenhum documento vinculado):</p>
                @foreach ($this->resumo->informacaoInsuficiente as $linha)
                    <div wire:key="info-{{ $linha->atividadeId }}" class="py-1 small {{ !$loop->last ? 'border-bottom' : '' }}">
                        {{ $linha->codigo }} — {{ $linha->nome }} ({{ $linha->inicioPlanejado?->format('d/m/Y') }})
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    @if(!empty($this->resumo->gaps))
        <div class="alert alert-secondary small">
            <strong>Limitações conhecidas desta versão:</strong>
            <ul class="mb-0 mt-1">
                @foreach ($this->resumo->gaps as $gap)
                    <li>{{ $gap }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
