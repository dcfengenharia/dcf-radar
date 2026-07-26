<?php

use App\Models\Convite;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Support\CatalogoFuncionalidades;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $abaAtiva = '';
    public string $nomeEdit = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $primeiro = $this->perfis->first();
        if ($primeiro) {
            $this->selecionarAba($primeiro->id);
        }
    }

    #[Computed]
    public function perfis()
    {
        return Perfil::where('tenant_id', TenantContext::currentId())->orderBy('nome')->get();
    }

    #[Computed]
    public function catalogoPorSecao(): array
    {
        return CatalogoFuncionalidades::porSecao();
    }

    #[Computed]
    public function permissoesAtivas(): array
    {
        if (! $this->abaAtiva) {
            return [];
        }

        $mapa = [];
        foreach (PerfilPermissao::where('perfil_id', $this->abaAtiva)->get(['funcionalidade', 'acao']) as $linha) {
            $mapa[$linha->funcionalidade.'|'.$linha->acao] = true;
        }

        return $mapa;
    }

    public function selecionarAba(string $perfilId): void
    {
        $this->abaAtiva = $perfilId;
        $this->nomeEdit = Perfil::find($perfilId)?->nome ?? '';
    }

    public function salvarNome(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $this->validate(['nomeEdit' => 'required|string|min:2|max:255'], [], ['nomeEdit' => 'nome']);

        Perfil::where('id', $this->abaAtiva)->where('tenant_id', TenantContext::currentId())
            ->update(['nome' => $this->nomeEdit]);

        unset($this->perfis);
        $this->dispatch('show-toast', message: 'Perfil renomeado.');
    }

    public function novoPerfil(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $perfil = Perfil::create([
            'tenant_id' => TenantContext::currentId(),
            'nome' => 'Novo Perfil',
        ]);

        unset($this->perfis);
        $this->selecionarAba($perfil->id);
        $this->dispatch('show-toast', message: 'Perfil criado — defina o nome e as permissões.');
    }

    public function excluirPerfil(string $perfilId): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $perfil = Perfil::where('tenant_id', TenantContext::currentId())->findOrFail($perfilId);

        if ($perfil->slug_padrao === 'admin') {
            $this->dispatch('show-toast', message: 'O perfil Admin não pode ser excluído — quem criou a empresa depende dele.');
            return;
        }

        $emUso = DB::table('obra_user')->where('perfil_id', $perfilId)->exists()
            || Convite::where('perfil_id', $perfilId)->exists();

        if ($emUso) {
            $this->dispatch('show-toast', message: 'Este perfil está em uso (obra ou convite) e não pode ser excluído.');
            return;
        }

        $perfil->permissoes()->delete();
        $perfil->delete();

        unset($this->perfis);
        $proximo = $this->perfis->first();
        $this->selecionarAba($proximo?->id ?? '');
        $this->dispatch('show-toast', message: 'Perfil excluído.');
    }

    public function togglePermissao(string $funcionalidade, string $acao): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $existente = PerfilPermissao::where('perfil_id', $this->abaAtiva)
            ->where('funcionalidade', $funcionalidade)
            ->where('acao', $acao)
            ->first();

        if ($existente) {
            $existente->delete();
        } else {
            PerfilPermissao::create([
                'tenant_id' => TenantContext::currentId(),
                'perfil_id' => $this->abaAtiva,
                'funcionalidade' => $funcionalidade,
                'acao' => $acao,
            ]);
        }

        unset($this->permissoesAtivas);
    }
};
?>

<div>
    <ul class="nav nav-tabs mb-4" role="tablist">
        @foreach ($this->perfis as $perfil)
        <li class="nav-item">
            <button class="nav-link {{ $abaAtiva === $perfil->id ? 'active' : '' }}"
                    wire:click="selecionarAba('{{ $perfil->id }}')" type="button">
                {{ $perfil->nome }}
            </button>
        </li>
        @endforeach
        <li class="nav-item">
            <button class="nav-link text-success" wire:click="novoPerfil" type="button">
                <i class="bx bx-plus"></i> Novo Perfil
            </button>
        </li>
    </ul>

    @if ($abaAtiva)
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Nome do perfil</label>
                    <input type="text" class="form-control @error('nomeEdit') is-invalid @enderror" wire:model="nomeEdit">
                    @error('nomeEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary" wire:click="salvarNome" wire:loading.attr="disabled">
                        <i class="bx bx-save me-1"></i>Salvar nome
                    </button>
                </div>
                <div class="col-md-3 text-end">
                    <button type="button" class="btn btn-outline-danger"
                            onclick="confirmarAcao(this, {
                                mensagem: 'Excluir este perfil? Só é possível se ele não estiver em uso.',
                                metodo: 'excluirPerfil',
                                args: ['{{ $abaAtiva }}'],
                                icone: 'bx-trash',
                            })">
                        <i class="bx bx-trash me-1"></i>Excluir perfil
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h6 class="fw-bold mb-3">Permissões</h6>
            <p class="text-muted small">
                Marque o que este perfil pode fazer em cada página do sistema.
            </p>

            @foreach ($this->catalogoPorSecao as $secao => $itens)
            <h6 class="text-muted small text-uppercase mt-4 mb-2">{{ $secao }}</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Página</th>
                            <th class="text-center" style="width:90px">Ver</th>
                            <th class="text-center" style="width:90px">Criar</th>
                            <th class="text-center" style="width:90px">Editar</th>
                            <th class="text-center" style="width:90px">Excluir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($itens as $item)
                        <tr wire:key="funcionalidade-{{ $item['slug'] }}">
                            <td>{{ $item['nome'] }}</td>
                            @foreach (\App\Support\CatalogoFuncionalidades::ACOES as $acao)
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" style="cursor:pointer"
                                       wire:click="togglePermissao('{{ $item['slug'] }}', '{{ $acao }}')"
                                       @checked(isset($this->permissoesAtivas[$item['slug'].'|'.$acao]))>
                            </td>
                            @endforeach
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endforeach
        </div>
    </div>
    @else
    <p class="text-muted">Nenhum perfil cadastrado ainda.</p>
    @endif
</div>
