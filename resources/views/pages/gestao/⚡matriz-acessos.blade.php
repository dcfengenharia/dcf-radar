<?php

use App\Enums\OrigemEventoHistoricoAcesso;
use App\Models\Perfil;
use App\Models\User;
use App\Models\Work;
use App\Support\Perfis\GuardUltimoAdmin;
use App\Support\Perfis\ResolverPerfisEfetivos;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * FASE 2C, Seção 22-25 — "ACESSOS ÀS OBRAS": Usuário × Obra × Perfis
 * numa visão só, com filtros (Seção 23) e edição contextual via
 * modal/drawer (Seção 24, nunca inline complexa). Mesma autoridade da
 * tela de Perfis de Acesso (Gate `gerenciar-perfis-acesso`, Seção 36/41
 * — nunca uma segunda regra paralela: o Gate compartilhado admite só o
 * criador do tenant OU o admin da plataforma em impersonation ATIVA e
 * auditada daquele tenant — mesmo bypass já usado por
 * `WorkPolicy::view()/update()`, necessário pra socorrer um tenant cujo
 * último Admin ficou trancado).
 *
 * Performance (Seção 44): 2 queries batch pra TODA a matriz do tenant
 * (`ResolverPerfisEfetivos::paraTenant()`, nunca N+1), filtro/ordenação/
 * paginação em memória — aceitável pro porte de tenant deste produto
 * (dezenas a poucas centenas de pares obra×usuário); documentado como
 * trade-off deliberado, nunca "sem limite" (sempre pagina).
 */
new class extends Component {
    #[Url(as: 'busca')]
    public string $buscaUsuario = '';

    #[Url(as: 'obra')]
    public string $filtroObraId = '';

    #[Url(as: 'perfil')]
    public string $filtroPerfilId = '';

    #[Url(as: 'status')]
    public string $filtroStatus = '';

    #[Url(as: 'pagina')]
    public int $pagina = 1;

    public int $porPagina = 20;

    // ---- Edição contextual (Seção 24) ----
    public bool    $editandoLinha        = false;
    public ?string $editandoObraId       = null;
    public ?string $editandoUserId       = null;
    public array   $perfisSelecionadosEdicao = [];

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
        return Perfil::where('tenant_id', TenantContext::currentId())->orderBy('nome')->get();
    }

    /**
     * Todas as linhas (Usuário × Obra × Perfis), já com filtros aplicados
     * — sempre a partir do resolver em lote (nunca N chamadas a
     * perfisNaObra()).
     */
    #[Computed]
    public function linhasFiltradas()
    {
        $tenantId = TenantContext::currentId();
        $pares = ResolverPerfisEfetivos::paraTenant($tenantId);

        $usuarios = User::where('tenant_id', $tenantId)->get()->keyBy('id');
        $obrasPorId = $this->obras->keyBy('id');
        $perfisPorId = $this->perfis->keyBy('id');

        $linhas = $pares->map(function ($par) use ($usuarios, $obrasPorId, $perfisPorId) {
            $usuario = $usuarios->get($par->user_id);
            $obra = $obrasPorId->get($par->work_id);

            if (! $usuario || ! $obra) {
                return null; // defensivo — nunca deveria acontecer com dado íntegro
            }

            return (object) [
                'usuario' => $usuario,
                'obra' => $obra,
                'perfis' => collect($par->perfil_ids)->map(fn ($id) => $perfisPorId->get($id))->filter()->values(),
            ];
        })->filter()->values();

        if ($this->buscaUsuario !== '') {
            $termo = mb_strtolower($this->buscaUsuario);
            $linhas = $linhas->filter(function ($l) use ($termo) {
                $alvo = mb_strtolower($l->usuario->first_name.' '.$l->usuario->last_name.' '.$l->usuario->email);

                return str_contains($alvo, $termo);
            });
        }

        if ($this->filtroObraId !== '') {
            $linhas = $linhas->filter(fn ($l) => $l->obra->id === $this->filtroObraId);
        }

        if ($this->filtroPerfilId !== '') {
            $linhas = $linhas->filter(fn ($l) => $l->perfis->pluck('id')->contains($this->filtroPerfilId));
        }

        if ($this->filtroStatus !== '') {
            $ativo = $this->filtroStatus === 'ativo';
            $linhas = $linhas->filter(fn ($l) => (bool) $l->usuario->ativo === $ativo);
        }

        return $linhas->sortBy([
            fn ($a, $b) => strcasecmp($a->usuario->first_name.' '.$a->usuario->last_name, $b->usuario->first_name.' '.$b->usuario->last_name),
            fn ($a, $b) => strcasecmp($a->obra->name, $b->obra->name),
        ])->values();
    }

    #[Computed]
    public function paginaAtual(): array
    {
        $total = $this->linhasFiltradas->count();
        $ultimaPagina = max(1, (int) ceil($total / $this->porPagina));
        $pagina = min(max(1, $this->pagina), $ultimaPagina);

        return [
            'itens' => $this->linhasFiltradas->forPage($pagina, $this->porPagina)->values(),
            'total' => $total,
            'pagina' => $pagina,
            'ultimaPagina' => $ultimaPagina,
        ];
    }

    public function updatedBuscaUsuario(): void
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

    public function updatedFiltroStatus(): void
    {
        $this->pagina = 1;
    }

    public function limparFiltros(): void
    {
        $this->reset(['buscaUsuario', 'filtroObraId', 'filtroPerfilId', 'filtroStatus']);
        $this->pagina = 1;
    }

    public function irParaPagina(int $pagina): void
    {
        $this->pagina = $pagina;
    }

    // =========================================================================
    // EDIÇÃO CONTEXTUAL (Seção 24)
    // =========================================================================

    public function abrirEdicao(string $obraId, string $userId): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $obra = Work::where('tenant_id', TenantContext::currentId())->findOrFail($obraId);
        $membro = $obra->users()->where('user_id', $userId)->first();
        abort_unless($membro !== null, 404);

        $this->editandoObraId = $obraId;
        $this->editandoUserId = $userId;
        $this->perfisSelecionadosEdicao = $membro->perfisNaObra($obra)->pluck('id')->all();
        $this->editandoLinha = true;
    }

    public function fecharEdicao(): void
    {
        $this->editandoLinha = false;
        $this->editandoObraId = null;
        $this->editandoUserId = null;
        $this->perfisSelecionadosEdicao = [];
    }

    public function salvarEdicao(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);
        abort_if($this->editandoObraId === null || $this->editandoUserId === null, 400);

        $obra = Work::with('tenant')->where('tenant_id', TenantContext::currentId())->findOrFail($this->editandoObraId);
        $userId = $this->editandoUserId;

        $ehCriadorDoTenant = $userId === $obra->tenant->criado_por_id;
        abort_if($ehCriadorDoTenant && ! auth()->user()->is_platform_admin, 403, 'O(s) perfil(is) de quem criou a empresa só pode(m) ser alterado(s) pelo administrador da plataforma.');

        $idsValidos = $this->perfis->pluck('id')->all();
        $novosPerfilIds = array_values(array_intersect($this->perfisSelecionadosEdicao, $idsValidos));

        if (GuardUltimoAdmin::removeriaOUltimoAdmin($obra, $userId, $novosPerfilIds)) {
            $this->dispatch('show-toast', message: 'Esta obra precisa permanecer com pelo menos um administrador. Atribua Admin a outra pessoa antes de remover.');
            return;
        }

        \App\Support\AtribuicaoPerfilObra::substituirPerfis(
            $obra, $userId, $novosPerfilIds, Auth::user(), OrigemEventoHistoricoAcesso::MatrizAcessos
        );

        unset($this->linhasFiltradas);
        $this->fecharEdicao();

        $this->dispatch('show-toast', message: $novosPerfilIds === []
            ? 'Perfis removidos — o usuário continua membro da obra, mas sem acesso às funcionalidades protegidas.'
            : 'Perfis atualizados.');
    }
};
?>

<div>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Buscar usuário</label>
                    <input type="text" class="form-control form-control-sm" wire:model.live.debounce.300ms="buscaUsuario"
                           placeholder="Nome ou e-mail...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Obra</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroObraId">
                        <option value="">Todas</option>
                        @foreach ($this->obras as $o)
                        <option value="{{ $o->id }}">{{ $o->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Perfil</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroPerfilId">
                        <option value="">Todos</option>
                        @foreach ($this->perfis as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status do usuário</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroStatus">
                        <option value="">Todos</option>
                        <option value="ativo">Ativo</option>
                        <option value="inativo">Inativo</option>
                    </select>
                </div>
            </div>
            @if ($buscaUsuario !== '' || $filtroObraId !== '' || $filtroPerfilId !== '' || $filtroStatus !== '')
            <button class="btn btn-link btn-sm p-0 mt-2" wire:click="limparFiltros">Limpar filtros</button>
            @endif
        </div>
    </div>

    @php $dados = $this->paginaAtual; @endphp

    <div class="card">
        <div class="card-body">
            <p class="text-muted small mb-3">{{ $dados['total'] }} associação(ões) obra × usuário.</p>

            {{-- Tabela responsiva (desktop) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Obra</th>
                            <th>Perfis</th>
                            <th>Status</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dados['itens'] as $linha)
                        <tr wire:key="linha-{{ $linha->obra->id }}-{{ $linha->usuario->id }}">
                            <td>
                                <div class="fw-semibold">{{ $linha->usuario->first_name }} {{ $linha->usuario->last_name }}</div>
                                <div class="small text-muted">{{ $linha->usuario->email }}</div>
                            </td>
                            <td>{{ $linha->obra->name }}</td>
                            <td>
                                @forelse ($linha->perfis as $p)
                                <span class="badge bg-label-secondary me-1 mb-1">{{ $p->nome }}</span>
                                @empty
                                <span class="badge bg-label-warning"><i class="bx bx-error-circle me-1"></i>Sem perfil</span>
                                @endforelse
                            </td>
                            <td>
                                @if ($linha->usuario->ativo)
                                <span class="badge bg-label-success">Ativo</span>
                                @else
                                <span class="badge bg-label-danger">Inativo</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        wire:click="abrirEdicao('{{ $linha->obra->id }}', '{{ $linha->usuario->id }}')">
                                    <i class="bx bx-edit-alt me-1"></i>Editar perfis
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma associação encontrada com os filtros atuais.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Cards empilhados (mobile — Seção 45) --}}
            <div class="d-md-none">
                @forelse ($dados['itens'] as $linha)
                <div class="border rounded p-3 mb-2" wire:key="card-{{ $linha->obra->id }}-{{ $linha->usuario->id }}">
                    <div class="fw-semibold">{{ $linha->usuario->first_name }} {{ $linha->usuario->last_name }}</div>
                    <div class="small text-muted mb-1">{{ $linha->usuario->email }}</div>
                    <div class="small mb-1"><strong>Obra:</strong> {{ $linha->obra->name }}</div>
                    <div class="mb-2">
                        @forelse ($linha->perfis as $p)
                        <span class="badge bg-label-secondary me-1 mb-1">{{ $p->nome }}</span>
                        @empty
                        <span class="badge bg-label-warning"><i class="bx bx-error-circle me-1"></i>Sem perfil</span>
                        @endforelse
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary w-100"
                            wire:click="abrirEdicao('{{ $linha->obra->id }}', '{{ $linha->usuario->id }}')">
                        <i class="bx bx-edit-alt me-1"></i>Editar perfis
                    </button>
                </div>
                @empty
                <p class="text-center text-muted py-4">Nenhuma associação encontrada com os filtros atuais.</p>
                @endforelse
            </div>

            @if ($dados['ultimaPagina'] > 1)
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    @for ($i = 1; $i <= $dados['ultimaPagina']; $i++)
                    <li class="page-item {{ $i === $dados['pagina'] ? 'active' : '' }}">
                        <button type="button" class="page-link" wire:click="irParaPagina({{ $i }})">{{ $i }}</button>
                    </li>
                    @endfor
                </ul>
            </nav>
            @endif
        </div>
    </div>

    @if ($editandoLinha)
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Perfis na obra</h5>
                    <button type="button" class="btn-close" wire:click="fecharEdicao"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        Marque quantos perfis fizerem sentido — as capacidades concedidas por QUALQUER
                        um deles somam.
                    </p>
                    @foreach ($this->perfis as $p)
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="{{ $p->id }}"
                               id="matriz-perfil-{{ $p->id }}"
                               wire:model="perfisSelecionadosEdicao">
                        <label class="form-check-label" for="matriz-perfil-{{ $p->id }}">
                            {{ $p->nome }}
                            @if ($p->ehPadrao())<span class="badge bg-label-info ms-1">Padrão DCF.ENG</span>@endif
                        </label>
                    </div>
                    @endforeach

                    @if (empty($perfisSelecionadosEdicao))
                    <div class="alert alert-warning small mb-0">
                        <i class="bx bx-error-circle me-1"></i>
                        Este usuário continuará membro da obra, mas ficará sem acesso às funcionalidades protegidas.
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="fecharEdicao">Cancelar</button>
                    <button type="button" class="btn btn-primary" wire:click="salvarEdicao" wire:loading.attr="disabled">
                        Salvar perfis
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
