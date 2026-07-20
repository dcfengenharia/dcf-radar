<?php

use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $filtro = 'todas'; // todas | nao_lidas | lidas

    #[Computed]
    public function notificacoes()
    {
        $query = auth()->user()->notifications()->latest();

        if ($this->filtro === 'nao_lidas') {
            $query->whereNull('read_at');
        } elseif ($this->filtro === 'lidas') {
            $query->whereNotNull('read_at');
        }

        return $query->paginate(20);
    }

    public function marcarLida(string $id): void
    {
        auth()->user()->notifications()->find($id)?->markAsRead();
        unset($this->notificacoes);
    }

    public function marcarTodasLidas(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->notificacoes);
    }

    public function updatedFiltro(): void
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

    {{-- Filtro --}}
    <ul class="nav nav-tabs mb-4">
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

    <div class="card">
        <ul class="list-group list-group-flush">
            @forelse($this->notificacoes as $notificacao)
            @php
                $data  = $notificacao->data;
                $lida  = $notificacao->read_at !== null;
                $icone = $data['icone'] ?? 'bx-bell';
                $cor   = $data['cor']   ?? 'primary';
                $link  = $data['link']  ?? '#';
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
                                </h6>
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
