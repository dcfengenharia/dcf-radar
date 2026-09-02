<?php

use App\Models\Work;
use App\Support\Gestao\CockpitObraQuery;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ciclo 21, Etapa 21.5 — Cockpit Executivo da Obra. Tela 100%
 * SOMENTE LEITURA (sem nenhuma ação de criar/editar/excluir — Seção 2 do
 * pedido: `fatos operacionais → Queries/Services de Gestão → DTO
 * (App\DTOs\Gestao\Cockpit\CockpitObra) → Livewire → Blade`). Este
 * componente NUNCA calcula nenhuma regra de negócio — o único método
 * público além de `mount()` é o computed que delega inteiramente pra
 * `App\Support\Gestao\CockpitObraQuery::resumo()`.
 *
 * **Permissão dedicada, NUNCA aberta por padrão** (Seção 34 do pedido):
 * `gestao.cockpit|ver`, único slug do catálogo cujo `ver` não é
 * concedido automaticamente a todo perfil com vínculo na obra — ver
 * `App\Models\Perfil::seedPadrao()` (mínimo `Papel::GerentePlanejamento`).
 *
 * **Filtro único: horizonte principal** (Seção 20 — "não encher a
 * primeira tela de filtros"; obra já vem do contexto ativo). Frente/
 * disciplina/pacote (também sugeridos na Seção 20) foram deliberadamente
 * NÃO implementados nesta primeira versão — decisão de escopo registrada
 * no CLAUDE.md, não uma omissão silenciosa.
 */
new class extends Component {
    public Work $obra;

    public int $horizontePrincipalDias = 28;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        abort_unless(
            Auth::user()->temPermissaoNaObra($this->obra->id, 'gestao.cockpit', 'ver'),
            403
        );
    }

    public function updatedHorizontePrincipalDias(): void
    {
        unset($this->resumo);
    }

    #[Computed]
    public function resumo(): \App\DTOs\Gestao\Cockpit\CockpitObra
    {
        return CockpitObraQuery::resumo($this->obra, $this->horizontePrincipalDias);
    }
};

?>

<div>
    {{-- ============================================================
         LINHA 1 — Estado executivo
    ============================================================= --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="mb-0">Cockpit Executivo</h4>
            <small class="text-muted">{{ $obra->name }} — visão consolidada pra decisão, atualizada agora</small>
        </div>
        <div>
            <label class="form-label small mb-0 me-2">Horizonte de análise</label>
            <select wire:model.live="horizontePrincipalDias" class="form-select form-select-sm d-inline-block w-auto">
                <option value="14">2 semanas</option>
                <option value="28">4 semanas</option>
                <option value="56">8 semanas</option>
            </select>
        </div>
    </div>

    @php($p = $this->resumo->panorama)
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100 {{ $p['criticas'] > 0 ? 'border-dark' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold {{ $p['criticas'] > 0 ? 'text-dark' : 'text-muted' }}">{{ $p['criticas'] }}</div>
                    <div class="small text-muted">Situações críticas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 {{ $p['altas'] > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold {{ $p['altas'] > 0 ? 'text-danger' : 'text-muted' }}">{{ $p['altas'] }}</div>
                    <div class="small text-muted">Situações altas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-warning">{{ $p['atencao'] }}</div>
                    <div class="small text-muted">Aguardando decisão</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-secondary">{{ $this->resumo->totalInformativas }}</div>
                    <div class="small text-muted">Informativas</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         LINHA 2 — O que pode parar a obra
    ============================================================= --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bx bx-error-circle text-dark me-1"></i> O que pode parar a obra</h5>
        </div>
        <div class="card-body">
            @forelse ($this->resumo->riscos as $s)
                <div wire:key="risco-{{ $s->chaveLogica }}" class="d-flex align-items-start gap-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <span class="badge bg-label-{{ $s->severidade->cor() }} p-2"><i class="bx {{ $s->severidade->icone() }}"></i></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $s->descricao }}</div>
                        <div class="small text-muted">
                            {{ $s->tipo->label() }}
                            @if($s->dataRelevante) · Início: {{ $s->dataRelevante->format('d/m/Y') }} @endif
                            @if($s->diasParaRelevante !== null) ({{ $s->diasParaRelevante }} dia(s)) @endif
                            @if($s->quantidade !== null) · Faltam {{ rtrim(rtrim(number_format($s->quantidade, 3, ',', '.'), '0'), ',') }} @endif
                        </div>
                    </div>
                    @if(!empty($s->deepLink['rota'] ?? null))
                        <a href="{{ route($s->deepLink['rota'], $s->deepLink['parametros'] ?? []) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">Nenhuma situação crítica ativa neste horizonte.</p>
            @endforelse
        </div>
    </div>

    {{-- ============================================================
         LINHA 2b — Onde preciso agir hoje
    ============================================================= --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bx bx-task text-warning me-1"></i> Onde preciso agir hoje</h5>
        </div>
        <div class="card-body">
            @forelse ($this->resumo->acoesHoje as $s)
                <div wire:key="acao-{{ $s->chaveLogica }}" class="d-flex align-items-start gap-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <span class="badge bg-label-{{ $s->severidade->cor() }} p-2"><i class="bx {{ $s->severidade->icone() }}"></i></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $s->descricao }}</div>
                        <div class="small text-muted">{{ $s->tipo->label() }}</div>
                    </div>
                    @if(!empty($s->deepLink['rota'] ?? null))
                        <a href="{{ route($s->deepLink['rota'], $s->deepLink['parametros'] ?? []) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">Nenhuma decisão pendente no momento.</p>
            @endforelse
        </div>
    </div>

    {{-- ============================================================
         LINHA 3 — Prontidão 2/4/8 semanas
    ============================================================= --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bx bx-calendar-check me-1"></i> Prontidão material — próximas semanas</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach ($this->resumo->prontidao as $semanas => $h)
                    <div class="col-md-4" wire:key="prontidao-{{ $semanas }}">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold mb-2">{{ $semanas }} semanas ({{ $h->horizonteDias }} dias)</div>
                            @if($h->total === 0)
                                <p class="text-muted small mb-0">Nenhuma atividade neste horizonte.</p>
                            @else
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-success">Cobertas</span><span>{{ $h->cobertas }}</span>
                                </div>
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-warning">Parcial</span><span>{{ $h->parcial }}</span>
                                </div>
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-danger">Descobertas</span><span>{{ $h->descobertas }}</span>
                                </div>
                                <div class="d-flex justify-content-between small mb-2">
                                    <span class="text-muted">Informação insuficiente</span><span>{{ $h->informacaoInsuficiente }}</span>
                                </div>
                                <div class="progress" style="height: 6px;" title="Cobertura entre atividades avaliáveis">
                                    <div class="progress-bar bg-success" style="width: {{ $h->percentualCoberturaAvaliavel ?? 0 }}%"></div>
                                </div>
                                <div class="small text-muted mt-1">
                                    @if($h->percentualCoberturaAvaliavel !== null)
                                        {{ $h->percentualCoberturaAvaliavel }}% cobertas / atividades avaliáveis (exclui informação insuficiente)
                                    @else
                                        Sem atividades avaliáveis pra calcular percentual
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Matriz de Prontidão Futura — drill-down --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Matriz de Prontidão Futura <small class="text-muted fw-normal">— por que essa atividade não está pronta?</small></h5>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Código</th><th>Descrição</th><th>Frente</th><th>Pacote</th><th>Início</th>
                        <th>Status operacional</th><th>Prontidão material</th><th>Materiais críticos</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->resumo->matrizAtividades as $linha)
                        <tr wire:key="matriz-{{ $linha->atividadeId }}">
                            <td>{{ $linha->codigo }}</td>
                            <td>{{ $linha->descricao }}</td>
                            <td>{{ $linha->frente ?? '—' }}</td>
                            <td>{{ $linha->pacote ?? '—' }}</td>
                            <td>{{ $linha->inicioPlanejado?->format('d/m/Y') }} ({{ $linha->diasParaInicio }}d)</td>
                            <td>{{ $linha->statusOperacional ?? '—' }}</td>
                            <td>
                                <span class="badge bg-label-{{ $linha->prontidaoMaterial->value === 'coberto' ? 'success' : ($linha->prontidaoMaterial->value === 'informacao_insuficiente' ? 'secondary' : 'warning') }}">
                                    {{ $linha->prontidaoMaterial->label() }}
                                </span>
                            </td>
                            <td class="small">
                                @forelse ($linha->materiaisCriticos as $m)
                                    <div>{{ $m['codigo'] ?? '—' }} — faltam {{ rtrim(rtrim(number_format($m['faltante'], 3, ',', '.'), '0'), ',') }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-3">Nenhuma atividade neste horizonte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============================================================
         LINHA 4 — Suprimentos × Cronograma
    ============================================================= --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Suprimentos × Cronograma <small class="text-muted fw-normal">— onde material não acompanha a necessidade</small></h5>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Situação</th><th>Pacote</th><th>Folga (dias)</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->resumo->suprimentosCronograma as $linha)
                        <tr wire:key="supcron-{{ $linha['situacao']->chaveLogica }}">
                            <td>{{ $linha['situacao']->descricao }}</td>
                            <td>{{ $linha['pacote_nome'] ?? '—' }}</td>
                            <td>
                                @if($linha['folga_dias'] === null) — @elseif($linha['folga_dias'] < 0)
                                    <span class="text-danger">{{ $linha['folga_dias'] }}</span>
                                @else
                                    <span class="text-success">{{ $linha['folga_dias'] }}</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($linha['situacao']->deepLink['rota'] ?? null))
                                    <a href="{{ route($linha['situacao']->deepLink['rota'], $linha['situacao']->deepLink['parametros'] ?? []) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">Nenhum material crítico pra atividades deste horizonte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============================================================
         LINHA 5 — Decisões pendentes (Engenharia / Estoque / Inventário / Industrialização)
    ============================================================= --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Engenharia</h6></div>
                <div class="card-body">
                    @forelse ($this->resumo->engenharia as $s)
                        <div wire:key="eng-{{ $s->chaveLogica }}" class="small py-1 {{ !$loop->last ? 'border-bottom' : '' }}">{{ $s->descricao }}</div>
                    @empty
                        <p class="text-muted small mb-0">Nenhum documento bloqueando atividades neste horizonte.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Inventário</h6></div>
                <div class="card-body">
                    <div class="small text-muted mb-2">{{ $this->resumo->inventario['em_contagem'] }} em contagem</div>
                    @forelse ($this->resumo->inventario['aguardando_decisao'] as $s)
                        <div wire:key="inv-{{ $s->chaveLogica }}" class="small py-1 {{ !$loop->last ? 'border-bottom' : '' }}">{{ $s->descricao }}</div>
                    @empty
                        <p class="text-muted small mb-0">Nenhum inventário aguardando decisão.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Estoque</h6></div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between py-1"><span>Reservas descobertas</span><span>{{ $this->resumo->estoque['reservas_descobertas']->count() }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span>Sem destinação</span><span>{{ $this->resumo->estoque['materiais_sem_destinacao']->count() }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span>Saídas sem conciliação</span><span>{{ $this->resumo->estoque['saidas_sem_conciliacao']->count() }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span>Aplicação ≠ planejado</span><span>{{ $this->resumo->estoque['desvios_aplicacao']->count() }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span>Materiais parados</span><span>{{ $this->resumo->estoque['materiais_parados']->count() }}</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Industrialização — material em terceiros</h6></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Ordem</th><th>Fornecedor</th><th>Em terceiro</th><th>Pendente</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->industrializacao as $linha)
                                <tr wire:key="ind-{{ $linha['ordem_industrializacao_id'] }}">
                                    <td>Nº{{ $linha['numero'] ?? '—' }}</td>
                                    <td>{{ $linha['fornecedor_nome'] ?? '—' }}</td>
                                    <td>{{ $linha['em_poder_terceiro'] }}</td>
                                    <td>{{ $linha['pendente'] }}</td>
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

    {{-- ============================================================
         LINHA 6 — Saúde da cadeia (pipeline + fornecedores)
    ============================================================= --}}
    <div class="row g-3 mb-4">
        <div class="col-md-7">
            <div class="card h-100">
                <div class="card-header">
                    <h6 class="mb-0">Pipeline de Suprimentos <small class="text-muted fw-normal">— {{ $this->resumo->pipelineTotalMateriais }} material(is), top {{ $this->resumo->pipelineMateriais->count() }} por déficit</small></h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Material</th><th>Necess.</th><th>Recebido</th><th>Físico</th><th>Reservado</th><th>Déficit</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->pipelineMateriais as $linha)
                                <tr wire:key="pipe-{{ $linha['material_codigo'] }}">
                                    <td>{{ $linha['material_codigo'] }}</td>
                                    <td>{{ $linha['necessidade'] }}</td>
                                    <td>{{ $linha['recebido'] }}</td>
                                    <td>{{ $linha['fisico'] }}</td>
                                    <td>{{ $linha['reservado'] }}</td>
                                    <td class="{{ $linha['deficit'] > 0 ? 'text-danger fw-semibold' : '' }}">{{ $linha['deficit'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">Nenhum material com movimentação/reserva nesta obra.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Fornecedores</h6></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Fornecedor</th><th>Abertos</th><th>Atrasados</th></tr></thead>
                        <tbody>
                            @forelse ($this->resumo->fornecedores as $linha)
                                <tr wire:key="forn-{{ $linha['fornecedor_id'] }}" class="{{ $linha['pedidos_atrasados'] > 0 ? 'table-danger' : '' }}">
                                    <td>{{ $linha['nome'] }}</td>
                                    <td>{{ $linha['pedidos_abertos'] }}</td>
                                    <td>{{ $linha['pedidos_atrasados'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">Nenhum fornecedor com pedidos nesta obra.</td></tr>
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
