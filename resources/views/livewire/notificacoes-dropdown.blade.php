<?php

use App\Support\Gestao\ScopoNotificacoesObra;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function notificacoes(): \Illuminate\Support\Collection
    {
        return ScopoNotificacoesObra::aplicar(auth()->user()->notifications()->latest(), auth()->user())
            ->limit(20)
            ->get();
    }

    /**
     * Ciclo 21, Etapa 21.3 — o badge é sempre COMUNICAÇÕES não lidas,
     * nunca "quantidade de problemas ativos" (isso é papel de um futuro
     * Cockpit, não desta Central) — mesma contagem de `read_at IS NULL`
     * de sempre, só escopada pra obras que o usuário ainda acessa.
     */
    #[Computed]
    public function naoLidas(): int
    {
        return ScopoNotificacoesObra::aplicar(auth()->user()->unreadNotifications(), auth()->user())->count();
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

    public function refresh(): void
    {
        unset($this->notificacoes);
    }
};

?>

<li class="nav-item dropdown-notifications navbar-dropdown dropdown me-3 me-xl-2">
    <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);"
       data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <i class="bx bx-bell bx-sm"></i>
        @if($this->naoLidas > 0)
        <span class="badge bg-danger rounded-pill badge-notifications" wire:key="badge-count">
            {{ $this->naoLidas }}
        </span>
        @endif
    </a>

    <ul class="dropdown-menu dropdown-menu-end py-0">
        <li class="dropdown-menu-header border-bottom">
            <div class="dropdown-header d-flex align-items-center py-3">
                <h5 class="text-body mb-0 me-auto">Notificações</h5>
                @if($this->naoLidas > 0)
                <a href="javascript:void(0)" wire:click="marcarTodasLidas"
                   class="dropdown-notifications-all text-body"
                   data-bs-toggle="tooltip" data-bs-placement="top" title="Marcar todas como lidas">
                    <i class="bx fs-4 bx-envelope-open"></i>
                </a>
                @endif
            </div>
        </li>

        <li class="dropdown-notifications-list scrollable-container">
            <ul class="list-group list-group-flush">
                @forelse($this->notificacoes as $notificacao)
                @php
                    $data   = $notificacao->data;
                    $lida   = $notificacao->read_at !== null;
                    $icone  = $data['icone'] ?? 'bx-bell';
                    $cor    = $data['cor']   ?? 'primary';
                    $link   = $data['link']  ?? '#';
                @endphp
                <li class="list-group-item list-group-item-action dropdown-notifications-item {{ $lida ? 'marked-as-read' : '' }}"
                    wire:key="notif-{{ $notificacao->id }}">
                    <div class="d-flex">
                        <div class="flex-shrink-0 me-3">
                            <div class="avatar">
                                <span class="avatar-initial rounded-circle bg-label-{{ $cor }}">
                                    <i class="bx {{ $icone }}"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <a href="{{ $link }}" wire:click="marcarLida('{{ $notificacao->id }}')"
                               class="text-body text-decoration-none stretched-link">
                                <h6 class="mb-1">{{ $data['titulo'] ?? 'Notificação' }}</h6>
                                <p class="mb-0 small">{{ Str::limit($data['mensagem'] ?? '', 80) }}</p>
                                <small class="text-muted">{{ $notificacao->created_at->diffForHumans() }}</small>
                            </a>
                        </div>
                        <div class="flex-shrink-0 dropdown-notifications-actions">
                            @if(! $lida)
                            <a href="javascript:void(0)" wire:click.stop="marcarLida('{{ $notificacao->id }}')"
                               class="dropdown-notifications-read" title="Marcar como lida">
                                <span class="badge badge-dot"></span>
                            </a>
                            @endif
                        </div>
                    </div>
                </li>
                @empty
                <li class="list-group-item text-center text-muted py-4">
                    <i class="bx bx-bell-off fs-3 mb-2 d-block"></i>
                    Nenhuma notificação
                </li>
                @endforelse
            </ul>
        </li>

        <li class="dropdown-menu-footer border-top">
            <a href="{{ route('notificacoes.index') }}"
               class="dropdown-item d-flex justify-content-center text-primary p-2 h-px-40">
                Ver todas as notificações
            </a>
        </li>
    </ul>

    @script
    <script>
        if (window.Echo) {
            window.Echo.private(`App.Models.User.{{ auth()->id() }}`)
                .notification(() => {
                    $wire.refresh();
                });
        }
    </script>
    @endscript
</li>
