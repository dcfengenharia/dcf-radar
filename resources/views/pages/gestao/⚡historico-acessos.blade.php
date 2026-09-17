<?php

use App\Enums\OrigemEventoHistoricoAcesso;
use App\Enums\TipoEventoHistoricoAcesso;
use App\Models\HistoricoAcesso;
use App\Models\Perfil;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FASE 2D, Seção 34-38 — superfície administrativa de consulta ao
 * histórico de acessos (`historico_acessos`, append-only). Mesma
 * autoridade de Perfis de Acesso/Matriz de Acessos (Seção 40/42 —
 * nenhuma capability nova: quem já pode administrar Perfis/Matriz já
 * pode ler a auditoria correspondente, e ler não é uma decisão de
 * acesso à parte que mereça uma segunda trava).
 *
 * A listagem NUNCA precisa fazer eager-load de `ator`/`usuarioAfetado`/
 * `perfil`/`obra` (Seção 55: "não N+1 de ator/usuário/obra/Perfil") —
 * todo dado de exibição já está DENORMALIZADO nas colunas
 * `resumo`/`*_nome_snapshot`/`detalhes` no momento em que o evento foi
 * gravado (Seção 9/23), então a query da listagem é sempre plana, sem
 * nenhum join/relação carregada. Os `<select>` de filtro (obra/perfil)
 * são as ÚNICAS 2 queries extras, e são fixas (não escalam com o total
 * de eventos).
 */
new class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'busca')]
    public string $busca = '';

    #[Url(as: 'de')]
    public string $dataDe = '';

    #[Url(as: 'ate')]
    public string $dataAte = '';

    #[Url(as: 'obra')]
    public string $filtroObraId = '';

    #[Url(as: 'perfil')]
    public string $filtroPerfilId = '';

    #[Url(as: 'tipo')]
    public string $filtroTipo = '';

    #[Url(as: 'pagina')]
    public int $pagina = 1;

    public ?string $eventoExpandidoId = null;

    public function mount(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);
    }

    #[Computed]
    public function obras()
    {
        return Work::where('tenant_id', TenantContext::currentId())->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function perfis()
    {
        return Perfil::where('tenant_id', TenantContext::currentId())->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function tiposEvento(): array
    {
        return TipoEventoHistoricoAcesso::cases();
    }

    /**
     * Seção 39 — SEMPRE `where('tenant_id', ...)` explícito (nunca só o
     * global scope, defesa em profundidade consistente com o resto do
     * projeto). Seção 38 — busca textual sobre `resumo` (já contém
     * nomes de ator/usuário afetado/perfil/obra em texto plano, nunca
     * precisa buscar em 4 colunas separadas).
     */
    #[Computed]
    public function eventosPaginados()
    {
        $query = HistoricoAcesso::where('tenant_id', TenantContext::currentId())
            ->orderByDesc('created_at');

        if ($this->busca !== '') {
            $query->where('resumo', 'like', '%'.$this->busca.'%');
        }

        if ($this->dataDe !== '') {
            $query->whereDate('created_at', '>=', $this->dataDe);
        }

        if ($this->dataAte !== '') {
            $query->whereDate('created_at', '<=', $this->dataAte);
        }

        if ($this->filtroObraId !== '') {
            $query->where('obra_id', $this->filtroObraId);
        }

        if ($this->filtroPerfilId !== '') {
            $query->where('perfil_id', $this->filtroPerfilId);
        }

        if ($this->filtroTipo !== '') {
            $query->where('tipo_evento', $this->filtroTipo);
        }

        return $query->paginate(20, page: $this->pagina);
    }

    public function updatedBusca(): void
    {
        $this->pagina = 1;
    }

    public function updatedDataDe(): void
    {
        $this->pagina = 1;
    }

    public function updatedDataAte(): void
    {
        $this->pagina = 1;
    }

    public function updatedFiltroObraId(): void
    {
        $this->pagina = 1;
    }

    public function updatedFiltroPerfilId(): void
    {
        $this->pagina = 1;
    }

    public function updatedFiltroTipo(): void
    {
        $this->pagina = 1;
    }

    public function limparFiltros(): void
    {
        $this->reset(['busca', 'dataDe', 'dataAte', 'filtroObraId', 'filtroPerfilId', 'filtroTipo']);
        $this->pagina = 1;
    }

    public function irParaPagina(int $pagina): void
    {
        $this->pagina = $pagina;
    }

    /**
     * Expandir/recolher o detalhe de um evento — Seção 36. Só valida
     * que o evento pertence ao tenant atual (defesa contra ID
     * manipulado, Seção 39) antes de marcar como expandido; nenhuma
     * query extra é necessária pro detalhe em si (já veio na
     * listagem).
     */
    public function alternarDetalhe(string $eventoId): void
    {
        if ($this->eventoExpandidoId === $eventoId) {
            $this->eventoExpandidoId = null;

            return;
        }

        $existe = HistoricoAcesso::where('tenant_id', TenantContext::currentId())->where('id', $eventoId)->exists();
        $this->eventoExpandidoId = $existe ? $eventoId : null;
    }
};
?>

<div>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Buscar</label>
                    <input type="text" class="form-control form-control-sm" wire:model.live.debounce.300ms="busca"
                           placeholder="Nome, obra, perfil...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">De</label>
                    <input type="date" class="form-control form-control-sm" wire:model.live="dataDe">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Até</label>
                    <input type="date" class="form-control form-control-sm" wire:model.live="dataAte">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Obra</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroObraId">
                        <option value="">Todas</option>
                        @foreach ($this->obras as $o)
                        <option value="{{ $o->id }}">{{ $o->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Perfil</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroPerfilId">
                        <option value="">Todos</option>
                        @foreach ($this->perfis as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small">Evento</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroTipo">
                        <option value="">Todos</option>
                        @foreach ($this->tiposEvento as $tipo)
                        <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($busca !== '' || $dataDe !== '' || $dataAte !== '' || $filtroObraId !== '' || $filtroPerfilId !== '' || $filtroTipo !== '')
            <button class="btn btn-link btn-sm p-0 mt-2" wire:click="limparFiltros">Limpar filtros</button>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @php($eventos = $this->eventosPaginados)
            <p class="text-muted small mb-3">{{ $eventos->total() }} evento(s) de governança de acesso.</p>

            @forelse ($eventos as $evento)
            <div class="border rounded mb-2" wire:key="evento-{{ $evento->id }}">
                <div class="d-flex justify-content-between align-items-start p-3" style="cursor:pointer"
                     wire:click="alternarDetalhe('{{ $evento->id }}')">
                    <div>
                        <div class="small text-muted">
                            {{ $evento->created_at->format('d/m/Y H:i') }}
                            <span class="badge bg-label-secondary ms-2">{{ $evento->tipo_evento->label() }}</span>
                            <span class="badge bg-label-info ms-1">{{ $evento->origem->label() }}</span>
                            @if ($evento->ator_impersonando)
                            <span class="badge bg-label-warning ms-1" title="Ação realizada pelo administrador da plataforma durante impersonation">
                                <i class="bx bx-shield-alt-2"></i> Impersonation
                            </span>
                            @endif
                        </div>
                        <div class="fw-semibold mt-1">{{ $evento->resumo }}</div>
                    </div>
                    <i class="bx {{ $eventoExpandidoId === $evento->id ? 'bx-chevron-up' : 'bx-chevron-down' }} fs-4 text-muted"></i>
                </div>

                @if ($eventoExpandidoId === $evento->id)
                <div class="border-top p-3 bg-light">
                    <div class="row g-3 small">
                        <div class="col-md-4">
                            <div class="text-muted">Ator</div>
                            <div class="fw-semibold">{{ $evento->nomeAtorExibicao() }}</div>
                            @if ($evento->ator_platform_admin)
                            <div class="text-muted">Administrador da plataforma{{ $evento->ator_impersonando ? ' (impersonando este tenant)' : '' }}</div>
                            @endif
                        </div>
                        @if ($evento->nomeUsuarioAfetadoExibicao())
                        <div class="col-md-4">
                            <div class="text-muted">Usuário afetado</div>
                            <div class="fw-semibold">{{ $evento->nomeUsuarioAfetadoExibicao() }}</div>
                        </div>
                        @endif
                        @if ($evento->obra_id)
                        <div class="col-md-4">
                            <div class="text-muted">Obra</div>
                            <div class="fw-semibold">{{ $evento->obra?->name ?? 'Obra removida' }}</div>
                        </div>
                        @endif
                        @if ($evento->nomePerfilExibicao())
                        <div class="col-md-4">
                            <div class="text-muted">Perfil</div>
                            <div class="fw-semibold">{{ $evento->nomePerfilExibicao() }}</div>
                        </div>
                        @endif
                    </div>

                    {{-- Seção 36 — nunca JSON cru: cada chave conhecida de
                         `detalhes` tem sua própria apresentação humana. --}}
                    @php($d = $evento->detalhes ?? [])

                    @if (! empty($d['adicionadas']))
                    <div class="mt-3">
                        <div class="text-muted small mb-1">Adicionado(s)</div>
                        @foreach ($d['adicionadas'] as $item)
                        <div class="text-success">+ {{ is_array($item) ? $item['nome'] : $item }}</div>
                        @endforeach
                    </div>
                    @endif

                    @if (! empty($d['removidas']))
                    <div class="mt-2">
                        <div class="text-muted small mb-1">Removido(s)</div>
                        @foreach ($d['removidas'] as $item)
                        <div class="text-danger">− {{ is_array($item) ? $item['nome'] : $item }}</div>
                        @endforeach
                    </div>
                    @endif

                    @if (! empty($d['perfis_no_momento_da_remocao']))
                    <div class="mt-2">
                        <div class="text-muted small mb-1">Perfis que possuía no momento</div>
                        @foreach ($d['perfis_no_momento_da_remocao'] as $item)
                        <div>{{ $item['nome'] }}</div>
                        @endforeach
                    </div>
                    @endif

                    @if (isset($d['impacto']))
                    <div class="mt-2 alert alert-warning small py-2 px-3 mb-0">
                        <i class="bx bx-info-circle me-1"></i>
                        No momento desta alteração, afetava <strong>{{ $d['impacto']['usuarios'] }}</strong> usuário(s)
                        em <strong>{{ $d['impacto']['obras'] }}</strong> obra(s).
                    </div>
                    @endif

                    @if (! empty($d['capacidades_iniciais']))
                    <div class="mt-2">
                        <div class="text-muted small mb-1">Capacidades iniciais</div>
                        @foreach ($d['capacidades_iniciais'] as $c)
                        <div>{{ $c }}</div>
                        @endforeach
                    </div>
                    @endif

                    @if (! empty($d['nome_antes']) || ! empty($d['nome_depois']))
                    <div class="mt-2">
                        <div class="text-muted small">Nome</div>
                        <div>"{{ $d['nome_antes'] }}" → "{{ $d['nome_depois'] }}"</div>
                    </div>
                    @endif

                    @if (array_key_exists('descricao_depois', $d) && ($d['descricao_antes'] !== null || $d['descricao_depois'] !== null))
                    <div class="mt-2">
                        <div class="text-muted small">Descrição</div>
                        <div>"{{ $d['descricao_antes'] }}" → "{{ $d['descricao_depois'] }}"</div>
                    </div>
                    @endif

                    @if (! empty($d['email_convidado']))
                    <div class="mt-2">
                        <div class="text-muted small">E-mail convidado</div>
                        <div>{{ $d['email_convidado'] }}</div>
                    </div>
                    @endif

                    @if (! empty($d['perfis_convidados']))
                    <div class="mt-2">
                        <div class="text-muted small mb-1">Perfis convidados</div>
                        @foreach ($d['perfis_convidados'] as $item)
                        <div>{{ $item['nome'] }}</div>
                        @endforeach
                    </div>
                    @endif

                    @if (! empty($d['perfis_concedidos']))
                    <div class="mt-2">
                        <div class="text-muted small mb-1">Perfis concedidos</div>
                        @foreach ($d['perfis_concedidos'] as $item)
                        <div>{{ $item['nome'] }}</div>
                        @endforeach
                    </div>
                    @endif

                    @if (! empty($d['perfil_origem_nome_snapshot']))
                    <div class="mt-2">
                        <div class="text-muted small">Perfil de origem</div>
                        <div>{{ $d['perfil_origem_nome_snapshot'] }}</div>
                    </div>
                    @endif

                    @if (! empty($d['descricao_no_momento']))
                    <div class="mt-2">
                        <div class="text-muted small">Descrição no momento da exclusão</div>
                        <div>{{ $d['descricao_no_momento'] }}</div>
                    </div>
                    @endif

                    @if (! empty($d['capacidades_no_momento']))
                    <div class="mt-2">
                        <div class="text-muted small mb-1">Capacidades no momento da exclusão</div>
                        @foreach ($d['capacidades_no_momento'] as $c)
                        <div>{{ $c }}</div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif
            </div>
            @empty
            <p class="text-center text-muted py-4">Nenhum evento encontrado com os filtros atuais.</p>
            @endforelse

            @if ($eventos->lastPage() > 1)
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    @for ($i = 1; $i <= $eventos->lastPage(); $i++)
                    <li class="page-item {{ $i === $eventos->currentPage() ? 'active' : '' }}">
                        <button type="button" class="page-link" wire:click="irParaPagina({{ $i }})">{{ $i }}</button>
                    </li>
                    @endfor
                </ul>
            </nav>
            @endif
        </div>
    </div>
</div>
