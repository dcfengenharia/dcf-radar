<?php

use App\Models\Atividade;
use App\Models\Client;
use App\Models\Convite;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Perfil;
use App\Models\Restricao;
use App\Models\User;
use App\Models\Work;
use App\Notifications\AdicionadoAObraNotification;
use App\Notifications\ConviteObraNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Work $obra;
    public string $abaAtiva = 'visao-geral';

    // ---- Dados da obra (edição) ----
    public bool    $editandoDados      = false;
    public string  $nomeEdit           = '';
    public ?string $clienteIdEdit      = null;
    public ?string $localizacaoEdit    = null;
    public ?float  $orcamentoEdit      = null;
    public ?string $inicioBaselineEdit = null;
    public ?string $terminoBaselineEdit = null;
    public string  $statusEdit         = 'planejamento';

    // ---- Equipe ----
    public string  $buscaUsuario       = '';
    public ?string $perfilNovoMembroId = null;

    // ---- Convite por e-mail ----
    public string  $emailConvite     = '';
    public ?string $perfilConviteId  = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        $perfilPadrao = Perfil::porSlugPadrao($obra->tenant, 'encarregado');
        $this->perfilNovoMembroId = $perfilPadrao?->id;
        $this->perfilConviteId = $perfilPadrao?->id;
    }

    #[Computed]
    public function perfisDisponiveis()
    {
        return Perfil::where('tenant_id', $this->obra->tenant_id)->orderBy('nome')->get();
    }

    public function setAba(string $aba): void
    {
        $this->abaAtiva = $aba;
    }

    // =========================================================================
    // VISÃO GERAL
    // =========================================================================

    #[Computed]
    public function totalAtividades(): int
    {
        return Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->count();
    }

    #[Computed]
    public function atividadesProntas(): int
    {
        return Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->prontas()
            ->count();
    }

    #[Computed]
    public function restricoesAbertas(): int
    {
        return Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros'])
            ->count();
    }

    #[Computed]
    public function restricoesBloqueantes(): int
    {
        return Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->where('bloqueante', true)
            ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros'])
            ->count();
    }

    #[Computed]
    public function ultimaImportacao(): ?CronogramaImportacao
    {
        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->orderByDesc('importado_em')
            ->first();
    }

    // =========================================================================
    // DADOS DA OBRA
    // =========================================================================

    #[Computed]
    public function clientes()
    {
        return Client::where('tenant_id', $this->obra->tenant_id)->orderBy('name')->get(['id', 'name']);
    }

    public function abrirEdicaoDados(): void
    {
        $this->authorize('update', $this->obra);

        $this->nomeEdit            = $this->obra->name;
        $this->clienteIdEdit       = $this->obra->client_id;
        $this->localizacaoEdit     = $this->obra->location;
        $this->orcamentoEdit       = $this->obra->budget_total;
        $this->inicioBaselineEdit  = $this->obra->start_date_baseline?->format('Y-m-d');
        $this->terminoBaselineEdit = $this->obra->end_date_baseline?->format('Y-m-d');
        $this->statusEdit          = $this->obra->status;
        $this->editandoDados       = true;
    }

    public function cancelarEdicaoDados(): void
    {
        $this->editandoDados = false;
        $this->resetErrorBag();
    }

    public function salvarDadosObra(): void
    {
        $this->authorize('update', $this->obra);

        $this->validate([
            'nomeEdit'            => 'required|string|min:3|max:255',
            'clienteIdEdit'       => 'required|exists:clients,id',
            'localizacaoEdit'     => 'nullable|string|max:255',
            'orcamentoEdit'       => 'nullable|numeric|min:0',
            'inicioBaselineEdit'  => 'nullable|date',
            'terminoBaselineEdit' => 'nullable|date|after_or_equal:inicioBaselineEdit',
            'statusEdit'          => 'required|in:planejamento,em_andamento,paralisada,concluida',
        ], [], [
            'nomeEdit' => 'nome',
            'clienteIdEdit' => 'cliente',
        ]);

        $this->obra->update([
            'name' => $this->nomeEdit,
            'client_id' => $this->clienteIdEdit,
            'location' => $this->localizacaoEdit,
            'budget_total' => $this->orcamentoEdit,
            'start_date_baseline' => $this->inicioBaselineEdit,
            'end_date_baseline' => $this->terminoBaselineEdit,
            'status' => $this->statusEdit,
        ]);

        $this->editandoDados = false;
        $this->obra->refresh();
        $this->dispatch('show-toast', message: 'Dados da obra atualizados.');
    }

    // =========================================================================
    // EQUIPE
    // =========================================================================

    #[Computed]
    public function membrosEquipe()
    {
        return $this->obra->users()->orderBy('first_name')->get();
    }

    #[Computed]
    public function usuariosParaAdicionar()
    {
        return User::where('tenant_id', $this->obra->tenant_id)
            ->whereDoesntHave('works', fn ($q) => $q->where('works.id', $this->obra->id))
            ->when($this->buscaUsuario, fn ($q) => $q->where(function ($qq) {
                $qq->where('first_name', 'like', "%{$this->buscaUsuario}%")
                    ->orWhere('last_name', 'like', "%{$this->buscaUsuario}%")
                    ->orWhere('email', 'like', "%{$this->buscaUsuario}%");
            }))
            ->orderBy('first_name')
            ->limit(20)
            ->get();
    }

    public function adicionarMembro(string $userId): void
    {
        $this->authorize('update', $this->obra);

        $this->obra->users()->syncWithoutDetaching([$userId => ['perfil_id' => $this->perfilNovoMembroId]]);

        unset($this->membrosEquipe, $this->usuariosParaAdicionar);
        $this->dispatch('show-toast', message: 'Membro adicionado à equipe.');
    }

    public function alterarPerfil(string $userId, string $perfilId): void
    {
        $this->authorize('update', $this->obra);

        $ehCriadorDoTenant = $userId === $this->obra->tenant->criado_por_id;
        abort_if($ehCriadorDoTenant && ! Auth::user()->is_platform_admin, 403, 'O perfil de quem criou a empresa só pode ser alterado pelo administrador da plataforma.');

        if ($this->removeriaOUltimoAdmin($userId, $perfilId)) {
            $this->dispatch('show-toast', message: 'Não é possível — este é o único Administrador da empresa. Promova outra pessoa a Administrador antes.');
            return;
        }

        $this->obra->users()->updateExistingPivot($userId, ['perfil_id' => $perfilId]);

        unset($this->membrosEquipe);
        $this->dispatch('show-toast', message: 'Perfil atualizado.');
    }

    public function removerMembro(string $userId): void
    {
        $this->authorize('update', $this->obra);

        $ehCriadorDoTenant = $userId === $this->obra->tenant->criado_por_id;
        abort_if($ehCriadorDoTenant && ! Auth::user()->is_platform_admin, 403, 'Quem criou a empresa só pode ser removido pelo administrador da plataforma.');

        if ($this->removeriaOUltimoAdmin($userId, null)) {
            $this->dispatch('show-toast', message: 'Não é possível remover — este é o único Administrador da empresa. Promova outra pessoa a Administrador antes.');
            return;
        }

        $this->obra->users()->detach($userId);

        unset($this->membrosEquipe, $this->usuariosParaAdicionar);
        $this->dispatch('show-toast', message: 'Membro removido da equipe.');
    }

    /**
     * Trava de integridade: todo tenant precisa ter, no mínimo, um
     * Administrador (perfil com slug_padrao='admin') em ALGUMA obra —
     * vale pra qualquer usuário, inclusive o administrador da
     * plataforma, que só pode contornar a trava do CRIADOR do tenant,
     * nunca esta. $novoPerfilId null = removendo o membro da equipe.
     */
    private function removeriaOUltimoAdmin(string $userId, ?string $novoPerfilId): bool
    {
        $perfilAdmin = Perfil::porSlugPadrao($this->obra->tenant, 'admin');
        if (! $perfilAdmin) {
            return false;
        }

        $membroAtual = $this->obra->users()->where('user_id', $userId)->first();
        $eraAdminNestaObra = $membroAtual && $membroAtual->pivot->perfil_id === $perfilAdmin->id;
        $continuaAdmin = $novoPerfilId === $perfilAdmin->id;

        if (! $eraAdminNestaObra || $continuaAdmin) {
            return false;
        }

        $temOutroAdminNoTenant = DB::table('obra_user')
            ->join('works', 'works.id', '=', 'obra_user.work_id')
            ->where('works.tenant_id', $this->obra->tenant_id)
            ->where('obra_user.perfil_id', $perfilAdmin->id)
            ->where('obra_user.user_id', '!=', $userId)
            ->exists();

        return ! $temOutroAdminNoTenant;
    }

    // =========================================================================
    // CONVITE POR E-MAIL
    // =========================================================================

    #[Computed]
    public function convitesPendentes()
    {
        return Convite::where('obra_id', $this->obra->id)
            ->where('status', 'pendente')
            ->with('convidadoPor:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function enviarConvite(): void
    {
        $this->authorize('update', $this->obra);

        $this->validate([
            'emailConvite' => 'required|email',
            'perfilConviteId' => ['required', Rule::exists('perfis', 'id')->where('tenant_id', $this->obra->tenant_id)],
        ], [], ['emailConvite' => 'e-mail']);

        $usuarioExistente = User::where('tenant_id', $this->obra->tenant_id)
            ->where('email', $this->emailConvite)
            ->first();

        if ($usuarioExistente) {
            if ($this->obra->users()->where('user_id', $usuarioExistente->id)->exists()) {
                $this->addError('emailConvite', 'Este usuário já faz parte da equipe desta obra.');
                return;
            }

            $this->obra->users()->attach($usuarioExistente->id, ['perfil_id' => $this->perfilConviteId]);
            $usuarioExistente->notify(new AdicionadoAObraNotification($this->obra, Perfil::findOrFail($this->perfilConviteId)));

            $this->resetConvite();
            unset($this->membrosEquipe, $this->usuariosParaAdicionar);
            $this->dispatch('show-toast', message: 'Usuário já cadastrado — adicionado direto à equipe.');
            return;
        }

        if (Convite::where('obra_id', $this->obra->id)->where('email', $this->emailConvite)->where('status', 'pendente')->exists()) {
            $this->addError('emailConvite', 'Já existe um convite pendente para este e-mail nesta obra.');
            return;
        }

        $convite = Convite::create([
            'obra_id' => $this->obra->id,
            'email' => $this->emailConvite,
            'perfil_id' => $this->perfilConviteId,
            'token' => Str::random(64),
            'convidado_por_id' => Auth::id(),
            'expira_em' => now()->addDays(7),
        ]);

        Notification::route('mail', $convite->email)->notify(new ConviteObraNotification($convite));

        $this->resetConvite();
        unset($this->convitesPendentes);
        $this->dispatch('show-toast', message: 'Convite enviado por e-mail.');
    }

    private function resetConvite(): void
    {
        $this->emailConvite = '';
        $this->perfilConviteId = Perfil::porSlugPadrao($this->obra->tenant, 'encarregado')?->id;
        $this->resetErrorBag();
    }

    public function reenviarConvite(string $conviteId): void
    {
        $this->authorize('update', $this->obra);

        $convite = Convite::where('obra_id', $this->obra->id)->where('status', 'pendente')->findOrFail($conviteId);
        $convite->update(['expira_em' => now()->addDays(7)]);

        Notification::route('mail', $convite->email)->notify(new ConviteObraNotification($convite));

        unset($this->convitesPendentes);
        $this->dispatch('show-toast', message: 'Convite reenviado.');
    }

    public function cancelarConvite(string $conviteId): void
    {
        $this->authorize('update', $this->obra);

        Convite::where('obra_id', $this->obra->id)->where('status', 'pendente')->findOrFail($conviteId)
            ->update(['status' => 'cancelado']);

        unset($this->convitesPendentes);
        $this->dispatch('show-toast', message: 'Convite cancelado.');
    }

    // =========================================================================
    // CRONOGRAMA
    // =========================================================================

    #[Computed]
    public function resumoCronograma(): array
    {
        return [
            'totalPacotes' => PacoteTrabalho::where('obra_id', $this->obra->id)->count(),
            'totalAtividades' => $this->totalAtividades,
            'comBaseline' => Atividade::where('obra_id', $this->obra->id)
                ->where('fora_do_cronograma', false)
                ->whereNotNull('baseline_inicio')
                ->count(),
        ];
    }

    // =========================================================================
    // HISTÓRICO DE IMPORTAÇÕES
    // =========================================================================

    #[Computed]
    public function importacoes()
    {
        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->with(['autor:id,first_name,last_name', 'linhaBase:id,cronograma_importacao_id,nome'])
            ->orderByDesc('importado_em')
            ->get();
    }
};
?>

<div>

{{-- Abas --}}
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <button class="nav-link {{ $abaAtiva === 'visao-geral' ? 'active' : '' }}" wire:click="setAba('visao-geral')" type="button">
            <i class="bx bx-tachometer me-1"></i>Visão Geral
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $abaAtiva === 'dados' ? 'active' : '' }}" wire:click="setAba('dados')" type="button">
            <i class="bx bx-building-house me-1"></i>Dados da Obra
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $abaAtiva === 'equipe' ? 'active' : '' }}" wire:click="setAba('equipe')" type="button">
            <i class="bx bx-group me-1"></i>Equipe
            <span class="badge bg-secondary ms-1">{{ $this->membrosEquipe->count() }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $abaAtiva === 'cronograma' ? 'active' : '' }}" wire:click="setAba('cronograma')" type="button">
            <i class="bx bx-calendar me-1"></i>Cronograma
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $abaAtiva === 'importacoes' ? 'active' : '' }}" wire:click="setAba('importacoes')" type="button">
            <i class="bx bx-history me-1"></i>Histórico de Importações
        </button>
    </li>
</ul>

{{-- =========================================================================
     ABA: VISÃO GERAL
     ========================================================================= --}}
@if ($abaAtiva === 'visao-geral')
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <div class="display-6 fw-bold">{{ $this->totalAtividades }}</div>
                <small class="text-muted">Atividades</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-success h-100">
            <div class="card-body text-center py-3">
                <div class="display-6 fw-bold text-success">{{ $this->atividadesProntas }}</div>
                <small class="text-muted">Prontas</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-danger h-100">
            <div class="card-body text-center py-3">
                <div class="display-6 fw-bold text-danger">{{ $this->restricoesBloqueantes }}</div>
                <small class="text-muted">Restrições Bloqueantes</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-warning h-100">
            <div class="card-body text-center py-3">
                <div class="display-6 fw-bold text-warning">{{ $this->restricoesAbertas }}</div>
                <small class="text-muted">Restrições Abertas</small>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <h5 class="mb-1">{{ $obra->name }}</h5>
            <p class="text-muted mb-0">
                {{ $obra->client?->name ?? '—' }}
                @if ($this->ultimaImportacao)
                · Última importação: {{ $this->ultimaImportacao->importado_em->format('d/m/Y H:i') }}
                @endif
            </p>
        </div>
        @if (auth()->user()->temAcessoAObra($obra))
        <a href="{{ route('radar.entrar', $obra) }}" class="btn btn-primary">
            <i class="bx bx-shield-alt-2 me-1"></i>Entrar no Radar desta obra
        </a>
        @endif
    </div>
</div>
@endif

{{-- =========================================================================
     ABA: DADOS DA OBRA
     ========================================================================= --}}
@if ($abaAtiva === 'dados')
<div class="card">
    <div class="card-body">
        @if (! $editandoDados)
            <div class="d-flex justify-content-between align-items-start mb-3">
                <h5 class="mb-0">Dados da Obra</h5>
                @can('update', $obra)
                <button class="btn btn-sm btn-outline-primary" wire:click="abrirEdicaoDados">
                    <i class="bx bx-pencil me-1"></i>Editar
                </button>
                @endcan
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-muted">Nome</div>
                    <div class="fw-semibold">{{ $obra->name }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Cliente</div>
                    <div class="fw-semibold">{{ $obra->client?->name ?? '—' }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Localização</div>
                    <div class="fw-semibold">{{ $obra->location ?? '—' }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Orçamento total</div>
                    <div class="fw-semibold">{{ $obra->budget_total ? 'R$ ' . number_format($obra->budget_total, 2, ',', '.') : '—' }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Início (linha de base)</div>
                    <div class="fw-semibold">{{ $obra->start_date_baseline?->format('d/m/Y') ?? '—' }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Término (linha de base)</div>
                    <div class="fw-semibold">{{ $obra->end_date_baseline?->format('d/m/Y') ?? '—' }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Status</div>
                    <span class="badge {{ $obra->status_badge }}">
                        {{ match($obra->status) {
                            'planejamento' => 'Planejamento', 'em_andamento' => 'Em Andamento',
                            'paralisada' => 'Paralisada', 'concluida' => 'Concluída',
                            default => $obra->status,
                        } }}
                    </span>
                </div>
            </div>
        @else
            <h5 class="mb-3">Editar Dados da Obra</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nome <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('nomeEdit') is-invalid @enderror" wire:model="nomeEdit">
                    @error('nomeEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Cliente <span class="text-danger">*</span></label>
                    <select class="form-select @error('clienteIdEdit') is-invalid @enderror" wire:model="clienteIdEdit">
                        @foreach ($this->clientes as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('clienteIdEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Localização</label>
                    <input type="text" class="form-control" wire:model="localizacaoEdit">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Orçamento total (R$)</label>
                    <input type="number" step="0.01" class="form-control @error('orcamentoEdit') is-invalid @enderror" wire:model="orcamentoEdit">
                    @error('orcamentoEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Início (linha de base)</label>
                    <input type="date" class="form-control" wire:model="inicioBaselineEdit">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Término (linha de base)</label>
                    <input type="date" class="form-control @error('terminoBaselineEdit') is-invalid @enderror" wire:model="terminoBaselineEdit">
                    @error('terminoBaselineEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" wire:model="statusEdit">
                        <option value="planejamento">Planejamento</option>
                        <option value="em_andamento">Em Andamento</option>
                        <option value="paralisada">Paralisada</option>
                        <option value="concluida">Concluída</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-outline-secondary" wire:click="cancelarEdicaoDados">Cancelar</button>
                <button class="btn btn-primary" wire:click="salvarDadosObra" wire:loading.attr="disabled">Salvar</button>
            </div>
        @endif
    </div>
</div>
@endif

{{-- =========================================================================
     ABA: EQUIPE
     ========================================================================= --}}
@if ($abaAtiva === 'equipe')
<div class="card mb-3">
    <div class="card-body">
        <h5 class="mb-3">Equipe da Obra</h5>

        @if ($this->membrosEquipe->isEmpty())
        <p class="text-muted">Nenhum membro vinculado a esta obra ainda.</p>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Usuário</th>
                        <th>E-mail</th>
                        <th>Papel</th>
                        @can('update', $obra)
                        <th class="text-center">Ações</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->membrosEquipe as $membro)
                    @php
                        $ehCriadorDoTenant = $membro->id === $obra->tenant->criado_por_id;
                        $criadorBloqueadoAqui = $ehCriadorDoTenant && ! Auth::user()->is_platform_admin;
                    @endphp
                    <tr wire:key="membro-{{ $membro->id }}">
                        <td>{{ $membro->first_name }} {{ $membro->last_name }}</td>
                        <td class="text-muted small">{{ $membro->email }}</td>
                        <td>
                            @if ($criadorBloqueadoAqui)
                            <span class="badge bg-label-primary" title="Dono da empresa — só o administrador da plataforma pode alterar">
                                <i class="bx bx-lock-alt me-1"></i>{{ $this->perfisDisponiveis->firstWhere('id', $membro->pivot->perfil_id)?->nome }}
                            </span>
                            @elseif (\Illuminate\Support\Facades\Gate::allows('update', $obra))
                            <select class="form-select form-select-sm" style="width:auto"
                                    wire:change="alterarPerfil('{{ $membro->id }}', $event.target.value)">
                                @foreach ($this->perfisDisponiveis as $p)
                                <option value="{{ $p->id }}" @selected($membro->pivot->perfil_id === $p->id)>{{ $p->nome }}</option>
                                @endforeach
                            </select>
                            @else
                            <span class="badge bg-label-secondary">{{ $this->perfisDisponiveis->firstWhere('id', $membro->pivot->perfil_id)?->nome }}</span>
                            @endif
                        </td>
                        @can('update', $obra)
                        <td class="text-center">
                            @unless ($criadorBloqueadoAqui)
                            <button class="btn btn-xs btn-outline-danger py-0 px-1"
                                    wire:click="removerMembro('{{ $membro->id }}')"
                                    wire:confirm="Remover {{ $membro->first_name }} da equipe desta obra?">
                                <i class="bx bx-trash"></i>
                            </button>
                            @endunless
                        </td>
                        @endcan
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@can('update', $obra)
<div class="card">
    <div class="card-body">
        <h6 class="fw-bold mb-3">Adicionar membro</h6>
        <div class="row g-2 mb-2">
            <div class="col-md-8">
                <input type="text" class="form-control form-control-sm" wire:model.live.debounce.300ms="buscaUsuario"
                       placeholder="Buscar por nome ou e-mail...">
            </div>
            <div class="col-md-4">
                <select class="form-select form-select-sm" wire:model="perfilNovoMembroId">
                    @foreach ($this->perfisDisponiveis as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div style="max-height:220px; overflow-y:auto">
            @forelse ($this->usuariosParaAdicionar as $u)
            <div class="d-flex align-items-center justify-content-between border-bottom py-2">
                <div>
                    <div class="fw-semibold">{{ $u->first_name }} {{ $u->last_name }}</div>
                    <small class="text-muted">{{ $u->email }}</small>
                </div>
                <button class="btn btn-sm btn-outline-primary" wire:click="adicionarMembro('{{ $u->id }}')">
                    <i class="bx bx-plus me-1"></i>Adicionar
                </button>
            </div>
            @empty
            <small class="text-muted">Nenhum usuário do tenant disponível pra adicionar.</small>
            @endforelse
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h6 class="fw-bold mb-3">Convidar por e-mail</h6>
        <p class="text-muted small">
            Se o e-mail já pertencer a um usuário desta empresa, ele é adicionado direto
            à equipe. Caso contrário, um e-mail de convite é enviado pra pessoa criar a
            própria senha e se cadastrar.
        </p>
        <div class="row g-2">
            <div class="col-md-6">
                <input type="email" class="form-control form-control-sm @error('emailConvite') is-invalid @enderror"
                       wire:model="emailConvite" placeholder="email@exemplo.com">
                @error('emailConvite')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <select class="form-select form-select-sm" wire:model="perfilConviteId">
                    @foreach ($this->perfisDisponiveis as $p)
                    <option value="{{ $p->id }}">{{ $p->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100" wire:click="enviarConvite" wire:loading.attr="disabled">
                    <i class="bx bx-envelope me-1"></i>Convidar
                </button>
            </div>
        </div>

        @if ($this->convitesPendentes->isNotEmpty())
        <hr>
        <h6 class="fw-bold mb-2">Convites pendentes</h6>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>E-mail</th>
                        <th>Papel</th>
                        <th>Convidado por</th>
                        <th>Enviado em</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->convitesPendentes as $convite)
                    <tr wire:key="convite-{{ $convite->id }}">
                        <td>{{ $convite->email }}</td>
                        <td>{{ $convite->perfil->nome }}</td>
                        <td class="small text-muted">
                            {{ $convite->convidadoPor?->first_name }} {{ $convite->convidadoPor?->last_name }}
                        </td>
                        <td class="small text-muted">{{ $convite->created_at->format('d/m/Y H:i') }}</td>
                        <td class="text-center">
                            @if ($convite->expirado())
                            <span class="badge bg-label-danger">Expirado</span>
                            @else
                            <span class="badge bg-label-warning">Pendente</span>
                            @endif
                        </td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1" title="Reenviar"
                                    wire:click="reenviarConvite('{{ $convite->id }}')">
                                <i class="bx bx-refresh"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-danger py-0 px-1" title="Cancelar"
                                    wire:click="cancelarConvite('{{ $convite->id }}')"
                                    wire:confirm="Cancelar o convite para {{ $convite->email }}?">
                                <i class="bx bx-x"></i>
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endcan
@endif

{{-- =========================================================================
     ABA: CRONOGRAMA
     ========================================================================= --}}
@if ($abaAtiva === 'cronograma')
<div class="card">
    <div class="card-body">
        <h5 class="mb-3">Estrutura do Cronograma</h5>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="small text-muted">Pacotes de trabalho (EAP)</div>
                <div class="fw-bold fs-4">{{ $this->resumoCronograma['totalPacotes'] }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Atividades</div>
                <div class="fw-bold fs-4">{{ $this->resumoCronograma['totalAtividades'] }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Com linha de base definida</div>
                <div class="fw-bold fs-4">{{ $this->resumoCronograma['comBaseline'] }}</div>
            </div>
        </div>
        @if (auth()->user()->temAcessoAObra($obra))
        <a href="{{ route('radar.entrar', $obra) }}" class="btn btn-primary">
            <i class="bx bx-cog me-1"></i>Gerenciar cronograma no Radar
        </a>
        @endif
    </div>
</div>
@endif

{{-- =========================================================================
     ABA: HISTÓRICO DE IMPORTAÇÕES
     ========================================================================= --}}
@if ($abaAtiva === 'importacoes')
<div class="card">
    <div class="card-body">
        <h5 class="mb-3">Histórico de Importações</h5>
        @if ($this->importacoes->isEmpty())
        <p class="text-muted">Nenhuma importação de cronograma registrada ainda.</p>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Arquivo</th>
                        <th>Importado em</th>
                        <th>Autor</th>
                        <th class="text-center">Criadas</th>
                        <th class="text-center">Atualizadas</th>
                        <th class="text-center">Arquivadas</th>
                        <th class="text-center">Linha de Base</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->importacoes as $imp)
                    <tr>
                        <td>{{ $imp->arquivo ?? '—' }}</td>
                        <td>{{ $imp->importado_em->format('d/m/Y H:i') }}</td>
                        <td>{{ $imp->autor ? "{$imp->autor->first_name} {$imp->autor->last_name}" : '—' }}</td>
                        <td class="text-center">{{ $imp->criadas }}</td>
                        <td class="text-center">{{ $imp->atualizadas }}</td>
                        <td class="text-center">{{ $imp->removidas }}</td>
                        <td class="text-center">
                            @if ($imp->linhaBase)
                            <span class="badge bg-success">{{ $imp->linhaBase->nome }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endif

</div>

@script
<script>
    $wire.on('show-toast', ({ message }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            toastr.success(message);
        }
    });
</script>
@endscript
