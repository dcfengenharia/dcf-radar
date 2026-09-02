<?php

use App\Models\Work;
use App\Support\Gestao\CockpitSuprimentosQuery;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ciclo 21, Etapa 21.6 — Cockpit de Suprimentos e Abastecimento. Tela
 * 100% SOMENTE LEITURA (Seção 2 do pedido) — o único método público além
 * de `mount()` é o computed que delega inteiramente pra
 * `App\Support\Gestao\CockpitSuprimentosQuery::resumo()`. Blade/Livewire
 * NUNCA calculam necessidade/atraso/cobertura/déficit/prontidão/folga/
 * status — só apresentam o que o read model já entrega pronto.
 *
 * **Permissão dedicada** (Seção 31): `gestao.suprimentos|ver`, mesmo
 * mecanismo/limiar do Cockpit Executivo (21.5) — auditado
 * adversarialmente antes de reaproveitar (ver `PermissaoVerGateAuditTest`).
 */
new class extends Component {
    public Work $obra;

    public int $horizontePrincipalDias = 28;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        abort_unless(
            Auth::user()->temPermissaoNaObra($this->obra->id, 'gestao.suprimentos', 'ver'),
            403
        );
    }

    public function updatedHorizontePrincipalDias(): void
    {
        unset($this->resumo);
    }

    #[Computed]
    public function resumo(): \App\DTOs\Gestao\Cockpit\CockpitSuprimentos
    {
        return CockpitSuprimentosQuery::resumo($this->obra, $this->horizontePrincipalDias);
    }
};

?>

<div>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="mb-0">Cockpit de Suprimentos e Abastecimento</h4>
            <small class="text-muted">{{ $obra->name }} — o que precisa da minha ação agora</small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('radar.cockpit') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bx bx-grid-alt me-1"></i>Cockpit Executivo
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

    {{-- Resumo superior — poucas exceções --}}
    @php($p = $this->resumo->panorama)
    <div class="row g-3 mb-4">
        <div class="col-6 col-md">
            <div class="card h-100 {{ $p['necessidades_sem_compra'] > 0 ? 'border-warning' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $p['necessidades_sem_compra'] }}</div>
                    <div class="small text-muted">Necessidades sem compra</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $p['pedidos_atrasados'] > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold {{ $p['pedidos_atrasados'] > 0 ? 'text-danger' : '' }}">{{ $p['pedidos_atrasados'] }}</div>
                    <div class="small text-muted">Pedidos atrasados</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $p['atividades_cobertura_insuficiente'] > 0 ? 'border-dark' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $p['atividades_cobertura_insuficiente'] }}</div>
                    <div class="small text-muted">Atividades com cobertura insuficiente</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $p['reservas_descobertas'] > 0 ? 'border-dark' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $p['reservas_descobertas'] }}</div>
                    <div class="small text-muted">Reservas descobertas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card h-100 {{ $p['recebimentos_vencidos'] > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold">{{ $p['recebimentos_vencidos'] }}</div>
                    <div class="small text-muted">Recebimentos vencidos</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Bloco de ação --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bx bx-error-circle me-1"></i> O que precisa da minha ação?</h5></div>
        <div class="card-body">
            @forelse ($this->resumo->necessidadesCriticas as $s)
                <div wire:key="nec-{{ $s->chaveLogica }}" class="d-flex align-items-start gap-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <span class="badge bg-label-{{ $s->severidade->cor() }} p-2"><i class="bx {{ $s->severidade->icone() }}"></i></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $s->descricao }}</div>
                        <div class="small text-muted">
                            {{ $s->tipo->label() }}
                            @if($s->dataRelevante) · Necessidade: {{ $s->dataRelevante->format('d/m/Y') }} @endif
                            @if($s->quantidade !== null) · Faltam {{ rtrim(rtrim(number_format($s->quantidade, 3, ',', '.'), '0'), ',') }} @endif
                        </div>
                    </div>
                    @if(!empty($s->deepLink['rota'] ?? null))
                        <a href="{{ route($s->deepLink['rota'], $s->deepLink['parametros'] ?? []) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">Nenhuma necessidade crítica sem cobertura neste horizonte.</p>
            @endforelse
        </div>
    </div>

    {{-- "O que chega tarde demais?" --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">O que chega tarde demais? <small class="text-muted fw-normal">— atendimento previsto após a necessidade</small></h5></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Pacote</th><th>Necessidade</th><th>Previsão</th><th>Folga</th><th>Faixa</th></tr></thead>
                <tbody>
                    @forelse ($this->resumo->chegaTardeDemais as $l)
                        <tr wire:key="tarde-{{ $l['pacote_id'] }}">
                            <td>{{ $l['pacote_nome'] }}</td>
                            <td>{{ $l['necessidade']?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $l['atendimento_projetado']?->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-danger fw-semibold">{{ $l['folga'] }} dia(s)</td>
                            <td><span class="badge bg-label-{{ $l['faixa']->cor() }}">{{ $l['faixa']->label() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">Nenhum pedido atrasado ligado a atividade futura.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Cobertura futura + Pedidos críticos --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Cobertura futura <small class="text-muted fw-normal">— atividades ameaçadas por material</small></h5></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Atividade</th><th>Início</th><th>Prontidão material</th><th>Faltante</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->coberturaFutura as $linha)
                                <tr wire:key="cob-{{ $linha->atividadeId }}">
                                    <td>{{ $linha->codigo }} — {{ $linha->descricao }}</td>
                                    <td>{{ $linha->inicioPlanejado?->format('d/m/Y') }} ({{ $linha->diasParaInicio }}d)</td>
                                    <td><span class="badge bg-label-warning">{{ $linha->prontidaoMaterial->label() }}</span></td>
                                    <td class="small">
                                        @foreach ($linha->materiaisCriticos as $m)
                                            <div>{{ $m['codigo'] ?? '—' }}: {{ rtrim(rtrim(number_format($m['faltante'], 3, ',', '.'), '0'), ',') }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">Nenhuma atividade ameaçada por material neste horizonte.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Pedidos críticos</h5></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Pedido</th><th>Fornecedor</th><th>Atraso</th><th>Folga mín.</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->pedidosCriticos as $l)
                                <tr wire:key="ped-{{ $l['pedido_id'] }}">
                                    <td>Nº{{ $l['numero'] }}</td>
                                    <td>{{ $l['fornecedor_nome'] ?? '—' }}</td>
                                    <td>{{ $l['dias_atraso'] !== null ? $l['dias_atraso'].'d' : '—' }}</td>
                                    <td class="{{ ($l['folga_minima_associada'] ?? 0) < 0 ? 'text-danger fw-semibold' : '' }}">
                                        {{ $l['folga_minima_associada'] ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">Nenhum pedido crítico no momento.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Pipeline --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Pipeline de Abastecimento <small class="text-muted fw-normal">— {{ $this->resumo->funilTotalMateriais }} material(is), top {{ $this->resumo->funilAbastecimento->count() }}</small></h5>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Material</th><th>Necess.</th><th>Requisitado</th><th>Em RC</th><th>Em Pedido</th><th>Recebido</th><th>Físico</th><th>Reservado</th><th>Déficit</th></tr></thead>
                <tbody>
                    @forelse ($this->resumo->funilAbastecimento as $l)
                        <tr wire:key="funil-{{ $l['material_codigo'] }}">
                            <td>{{ $l['material_codigo'] }}</td>
                            <td>{{ $l['necessidade'] }}</td>
                            <td>{{ $l['requisitado'] }}</td>
                            <td>{{ $l['em_rc'] }}</td>
                            <td>{{ $l['em_pedido'] }}</td>
                            <td>{{ $l['recebido'] }}</td>
                            <td>{{ $l['fisico'] }}</td>
                            <td>{{ $l['reservado'] }}</td>
                            <td class="{{ $l['deficit'] > 0 ? 'text-danger fw-semibold' : '' }}">{{ $l['deficit'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-3">Nenhum material com movimentação/reserva nesta obra.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Demanda ainda não comprada --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Demanda ainda não comprada</h5></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Material</th><th>Necess.</th><th>A requisitar</th><th>A alocar</th><th>A colocar em RC</th><th>A colocar em Pedido</th><th>% comprado</th></tr></thead>
                <tbody>
                    @forelse ($this->resumo->comprasPendentes as $l)
                        <tr wire:key="comprar-{{ $l['material_codigo'] }}">
                            <td>{{ $l['material_codigo'] }}</td>
                            <td>{{ $l['necessidade'] }}</td>
                            <td class="{{ $l['saldo_a_requisitar'] > 0 ? 'text-warning fw-semibold' : '' }}">{{ $l['saldo_a_requisitar'] }}</td>
                            <td>{{ $l['saldo_a_alocar'] }}</td>
                            <td>{{ $l['saldo_a_colocar_em_rc'] }}</td>
                            <td>{{ $l['saldo_a_colocar_em_pedido'] }}</td>
                            <td>{{ $l['percentual_comprado'] }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">Toda a demanda desta obra já foi comprada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Fornecedores --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Fornecedores que exigem ação</h5></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Fornecedor</th><th>Abertos</th><th>Atrasados</th><th>Folga mín.</th><th>Atividades próximas</th></tr></thead>
                <tbody>
                    @forelse ($this->resumo->fornecedores as $l)
                        <tr wire:key="forn-{{ $l['fornecedor_id'] }}" class="{{ $l['pedidos_atrasados'] > 0 ? 'table-danger' : '' }}">
                            <td>{{ $l['nome'] }}</td>
                            <td>{{ $l['pedidos_abertos'] }}</td>
                            <td>{{ $l['pedidos_atrasados'] }}</td>
                            <td>{{ $l['folga_minima'] ?? '—' }}</td>
                            <td class="small">{{ implode(', ', array_slice($l['materiais_atividades_proximas'], 0, 3)) ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">Nenhum fornecedor com pedidos nesta obra.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Estoque / Terceiros — exceções --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Estoque — exceções</h6></div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between py-1"><span>Reservas descobertas</span><span>{{ $this->resumo->estoque['reservas_descobertas']->count() }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span>Sem destinação</span><span>{{ $this->resumo->estoque['materiais_sem_destinacao']->count() }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span>Saídas sem conciliação</span><span>{{ $this->resumo->estoque['saidas_sem_conciliacao']->count() }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span>Materiais parados</span><span>{{ $this->resumo->estoque['materiais_parados']->count() }}</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Material em terceiros</h6></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Ordem</th><th>Fornecedor</th><th>Em terceiro</th><th>Pendente</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->terceiros as $l)
                                <tr wire:key="terc-{{ $l['ordem_industrializacao_id'] }}">
                                    <td>Nº{{ $l['numero'] ?? '—' }}</td>
                                    <td>{{ $l['fornecedor_nome'] ?? '—' }}</td>
                                    <td>{{ $l['em_poder_terceiro'] }}</td>
                                    <td>{{ $l['pendente'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">Nenhuma ordem em andamento.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
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
