<?php

use App\Enums\SeveridadeSituacao;
use App\Enums\TipoSituacaoGerencial;
use App\Models\SituacaoOcorrencia;
use App\Support\Gestao\ScopoNotificacoesObra;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $filtro = 'todas'; // todas | nao_lidas | lidas
    public string $obraFiltro = '';
    public string $tipoFiltro = '';
    public string $severidadeFiltro = '';

    #[Computed]
    public function notificacoes()
    {
        $query = ScopoNotificacoesObra::aplicar(auth()->user()->notifications()->latest(), auth()->user());

        if ($this->filtro === 'nao_lidas') {
            $query->whereNull('read_at');
        } elseif ($this->filtro === 'lidas') {
            $query->whereNotNull('read_at');
        }

        if ($this->obraFiltro !== '') {
            $query->where('data->obra_id', $this->obraFiltro);
        }

        if ($this->tipoFiltro !== '') {
            $query->where('data->tipo', $this->tipoFiltro);
        }

        if ($this->severidadeFiltro !== '') {
            $query->where('data->severidade', $this->severidadeFiltro);
        }

        return $query->paginate(20);
    }

    /**
     * Ciclo 21, Etapa 21.3 (Seção 11) — "ativa"/"não lida" são dimensões
     * DIFERENTES: aqui buscamos o estado ATUAL da ocorrência (pode ter
     * mudado desde que a comunicação foi enviada), nunca inferido de
     * `read_at`. 1 query em lote pra toda a página (nunca 1 por linha).
     *
     * @return \Illuminate\Support\Collection<string, \App\Enums\StatusSituacaoOcorrencia>
     */
    #[Computed]
    public function estadosPorOcorrencia(): \Illuminate\Support\Collection
    {
        $ids = collect($this->notificacoes->items())
            ->pluck('data.ocorrencia_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return SituacaoOcorrencia::whereIn('id', $ids)->pluck('status', 'id');
    }

    #[Computed]
    public function obrasDisponiveis()
    {
        return auth()->user()->works()->orderBy('name')->get(['works.id', 'works.name']);
    }

    #[Computed]
    public function tiposDisponiveis(): array
    {
        return TipoSituacaoGerencial::cases();
    }

    #[Computed]
    public function severidadesDisponiveis(): array
    {
        return SeveridadeSituacao::cases();
    }

    public function marcarLida(string $id): void
    {
        auth()->user()->notifications()->find($id)?->markAsRead();
        unset($this->notificacoes);
    }

    public function marcarTodasLidas(): void
    {
        // ->get() sobre a relação de DatabaseNotification retorna uma
        // DatabaseNotificationCollection, cujo markAsRead() já faz 1
        // UPDATE em lote (nunca 1 por linha) — mesma eficiência do
        // ->unreadNotifications->markAsRead() original, só escopado.
        ScopoNotificacoesObra::aplicar(auth()->user()->unreadNotifications(), auth()->user())->get()->markAsRead();
        unset($this->notificacoes);
    }

    public function updatedFiltro(): void
    {
        $this->resetPage();
        unset($this->notificacoes);
    }

    public function updatedObraFiltro(): void
    {
        $this->resetPage();
        unset($this->notificacoes);
    }

    public function updatedTipoFiltro(): void
    {
        $this->resetPage();
        unset($this->notificacoes);
    }

    public function updatedSeveridadeFiltro(): void
    {
        $this->resetPage();
        unset($this->notificacoes);
    }
};

?>

<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="mb-1 mt-2">Notificações</h4>
            <p class="text-muted mb-0">Todas as suas notificações da plataforma.</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm" wire:click="marcarTodasLidas">
            <i class="bx bx-envelope-open me-1"></i>Marcar todas como lidas
        </button>
    </div>

    {{-- Filtro de lidas/não lidas --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link {{ $filtro === 'todas' ? 'active' : '' }}" wire:click="$set('filtro', 'todas')">
                Todas
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $filtro === 'nao_lidas' ? 'active' : '' }}" wire:click="$set('filtro', 'nao_lidas')">
                Não lidas
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $filtro === 'lidas' ? 'active' : '' }}" wire:click="$set('filtro', 'lidas')">
                Lidas
            </button>
        </li>
    </ul>

    {{-- Filtros mínimos úteis (Ciclo 21, Etapa 21.3, Seção 10) --}}
    <div class="row g-2 mb-4">
        <div class="col-auto">
            <select class="form-select form-select-sm" wire:model.live="obraFiltro" style="min-width: 180px;">
                <option value="">Todas as obras</option>
                @foreach ($this->obrasDisponiveis as $obraOpcao)
                <option value="{{ $obraOpcao->id }}">{{ $obraOpcao->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select class="form-select form-select-sm" wire:model.live="tipoFiltro" style="min-width: 220px;">
                <option value="">Todos os tipos</option>
                @foreach ($this->tiposDisponiveis as $tipoOpcao)
                <option value="{{ $tipoOpcao->value }}">{{ $tipoOpcao->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select class="form-select form-select-sm" wire:model.live="severidadeFiltro" style="min-width: 160px;">
                <option value="">Toda severidade</option>
                @foreach ($this->severidadesDisponiveis as $severidadeOpcao)
                <option value="{{ $severidadeOpcao->value }}">{{ $severidadeOpcao->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="card">
        <ul class="list-group list-group-flush">
            @forelse($this->notificacoes as $notificacao)
            @php
                $data  = $notificacao->data;
                $lida  = $notificacao->read_at !== null;
                $icone = $data['icone'] ?? 'bx-bell';
                $cor   = $data['cor']   ?? 'primary';
                $link  = $data['link']  ?? '#';
                $ocorrenciaId = $data['ocorrencia_id'] ?? null;
                $estadoOcorrencia = $ocorrenciaId ? $this->estadosPorOcorrencia->get($ocorrenciaId) : null;
            @endphp
            <li class="list-group-item list-group-item-action py-3 {{ $lida ? 'text-muted' : '' }}"
                wire:key="notif-{{ $notificacao->id }}">
                <div class="d-flex align-items-start gap-3">
                    <div class="avatar flex-shrink-0">
                        <span class="avatar-initial rounded-circle bg-label-{{ $cor }}">
                            <i class="bx {{ $icone }}"></i>
                        </span>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 {{ $lida ? 'fw-normal' : 'fw-semibold' }}">
                                    {{ $data['titulo'] ?? 'Notificação' }}
                                    @if(! $lida)
                                    <span class="badge bg-danger ms-1" style="width:8px;height:8px;border-radius:50%;padding:0;display:inline-block;vertical-align:middle;"></span>
                                    @endif
                                    @if($estadoOcorrencia)
                                    <span class="badge bg-label-{{ $estadoOcorrencia->estaAtiva() ? 'warning' : 'success' }} ms-1">
                                        {{ $estadoOcorrencia->label() }}
                                    </span>
                                    @endif
                                </h6>
                                @if(!empty($data['obra_nome']))
                                <small class="text-muted d-block mb-1">{{ $data['obra_nome'] }}</small>
                                @endif
                                <p class="mb-1 small">{{ $data['mensagem'] ?? '' }}</p>
                                <small class="text-muted">{{ $notificacao->created_at->diffForHumans() }}</small>
                            </div>
                            <div class="d-flex gap-2 flex-shrink-0 ms-3">
                                @if($link !== '#')
                                <a href="{{ $link }}" wire:click="marcarLida('{{ $notificacao->id }}')"
                                   class="btn btn-sm btn-outline-primary py-0">
                                    <i class="bx bx-link-external"></i>
                                </a>
                                @endif
                                @if(! $lida)
                                <button class="btn btn-sm btn-outline-secondary py-0"
                                        wire:click="marcarLida('{{ $notificacao->id }}')"
                                        title="Marcar como lida">
                                    <i class="bx bx-check"></i>
                                </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </li>
            @empty
            <li class="list-group-item text-center text-muted py-5">
                <i class="bx bx-bell-off fs-1 mb-2 d-block"></i>
                Nenhuma notificação encontrada.
            </li>
            @endforelse
        </ul>
        @if($this->notificacoes->hasPages())
        <div class="card-footer">
            {{ $this->notificacoes->links() }}
        </div>
        @endif
    </div>
</div>
