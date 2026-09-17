<?php

use App\Models\Convite;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Support\CatalogoFuncionalidades;
use App\Support\Perfis\CapabilidadeCatalogo;
use App\Support\Perfis\ImpactoPerfilCalculator;
use App\Support\Perfis\RegistrarEventoAcesso;
use App\Support\Perfis\ResolverPerfisEfetivos;
use App\Support\Perfis\TemplatesEspecialistas;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * FASE 2C, Seção 4-18 — redesenho profissional da administração de
 * Perfis de Acesso. Listagem (nome/descrição/tipo/impacto/ações) →
 * editor contextual (Modo Simples via presets/Modo Avançado via
 * capacidades reais) → Novo Perfil (zero/template/existente) →
 * Duplicar. Nunca uma segunda autoridade: todo preset/domínio/template
 * é só apresentação sobre `PerfilPermissao`/`Perfil::REGRAS_ESCRITA`
 * já existentes (`App\Support\Perfis\CapabilidadeCatalogo`/
 * `TemplatesEspecialistas`, Seção 12).
 */
new class extends Component {
    // ---- Listagem (Seção 4) ----
    public string $visualizacao = 'lista'; // 'lista' | 'editor'
    public string $busca = '';

    // ---- Editor (Seção 4/9-18) ----
    public string $abaAtiva = '';
    public string $nomeEdit = '';
    public string $descricaoEdit = '';
    public string $modoEdicao = 'simples'; // 'simples' | 'avancado'

    // ---- Novo Perfil (Seção 6/7/9) ----
    public bool $mostrandoNovoPerfil = false;
    public string $novoPerfilOrigem = 'zero'; // 'zero' | 'template' | 'existente'
    public string $novoPerfilTemplateChave = '';
    public string $novoPerfilBaseId = '';
    public string $novoPerfilNomeCustom = '';

    // ---- Duplicar (Seção 8) ----
    public bool $mostrandoDuplicar = false;
    public string $duplicarPerfilId = '';
    public string $duplicarNomeNovo = '';

    /**
     * FASE 2C, fechamento adversarial (Seção 38/39) — snapshot das
     * permissões ATIVAS no instante em que o editor foi aberto (via
     * `selecionarAba()`). Nunca é uma segunda autoridade — só serve pra
     * `diffPermissoesSessao()` calcular "o que mudou nesta sessão de
     * edição" (impacto ainda era só uma CONTAGEM; o pedido original
     * também queria ver adicionado/removido concretamente).
     *
     * @var array<string, true>
     */
    public array $permissoesOriginaisEdicao = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);
    }

    #[Computed]
    public function perfis()
    {
        return Perfil::where('tenant_id', TenantContext::currentId())->orderBy('nome')->get();
    }

    /**
     * Seção 4 — impacto de TODOS os perfis calculado em lote (2 queries
     * batch via `ResolverPerfisEfetivos::paraTenant()`, nunca 1 SUM por
     * linha da listagem).
     *
     * @return \Illuminate\Support\Collection<int, object{perfil: Perfil, usuarios: int, obras: int}>
     */
    #[Computed]
    public function perfisComImpacto()
    {
        $pares = ResolverPerfisEfetivos::paraTenant(TenantContext::currentId());

        return $this->perfis->map(function (Perfil $perfil) use ($pares) {
            $doPerfil = $pares->filter(fn ($par) => in_array($perfil->id, $par->perfil_ids, true));

            return (object) [
                'perfil' => $perfil,
                'usuarios' => $doPerfil->pluck('user_id')->unique()->count(),
                'obras' => $doPerfil->pluck('work_id')->unique()->count(),
            ];
        });
    }

    #[Computed]
    public function perfisFiltrados()
    {
        $termo = mb_strtolower(trim($this->busca));

        if ($termo === '') {
            return $this->perfisComImpacto;
        }

        return $this->perfisComImpacto->filter(function ($item) use ($termo) {
            return str_contains(mb_strtolower($item->perfil->nome), $termo)
                || str_contains(mb_strtolower((string) $item->perfil->descricao), $termo);
        })->values();
    }

    #[Computed]
    public function perfilAtivo(): ?Perfil
    {
        if (! $this->abaAtiva) {
            return null;
        }

        return Perfil::where('tenant_id', TenantContext::currentId())->find($this->abaAtiva);
    }

    /**
     * Seção 22 — impacto do perfil ABERTO no editor, pra exibir "isso
     * afeta N usuário(s) em M obra(s)" antes/durante a edição.
     *
     * @return array{usuarios: int, obras: int}
     */
    #[Computed]
    public function impactoAtivo(): array
    {
        if (! $this->abaAtiva) {
            return ['usuarios' => 0, 'obras' => 0];
        }

        $impacto = ImpactoPerfilCalculator::calcular(TenantContext::currentId(), $this->abaAtiva);

        return ['usuarios' => $impacto['usuarios'], 'obras' => $impacto['obras']];
    }

    #[Computed]
    public function dominiosCapacidades(): array
    {
        return CapabilidadeCatalogo::funcionalidadesPorDominio();
    }

    #[Computed]
    public function templatesEspecialistas(): array
    {
        return TemplatesEspecialistas::definicoes();
    }

    /**
     * @return array<string, true>
     */
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

    /**
     * Preset ativo (Modo Simples) por funcionalidade — deriva SEMPRE das
     * ações realmente marcadas, nunca um estado guardado à parte.
     *
     * @return array<string, ?string>
     */
    #[Computed]
    public function presetAtivoPorFuncionalidade(): array
    {
        if (! $this->abaAtiva) {
            return [];
        }

        $ativas = collect(array_keys($this->permissoesAtivas))
            ->groupBy(fn ($chave) => explode('|', $chave)[0])
            ->map(fn ($linhas) => $linhas->map(fn ($chave) => explode('|', $chave)[1])->all());

        $resultado = [];
        foreach (CatalogoFuncionalidades::slugs() as $slug) {
            $resultado[$slug] = CapabilidadeCatalogo::presetAtivo($slug, $ativas->get($slug, []));
        }

        return $resultado;
    }

    public function irParaLista(): void
    {
        $this->visualizacao = 'lista';
        $this->abaAtiva = '';
    }

    public function selecionarAba(string $perfilId): void
    {
        $perfil = Perfil::where('tenant_id', TenantContext::currentId())->findOrFail($perfilId);

        $this->abaAtiva = $perfil->id;
        $this->nomeEdit = $perfil->nome;
        $this->descricaoEdit = (string) $perfil->descricao;
        $this->modoEdicao = 'simples';
        $this->visualizacao = 'editor';

        unset($this->permissoesAtivas, $this->presetAtivoPorFuncionalidade);
        $this->permissoesOriginaisEdicao = $this->permissoesAtivas;
    }

    /**
     * Seção 38/39 — diff simples (não sofisticado, por pedido explícito)
     * entre o que estava marcado quando o editor abriu
     * (`permissoesOriginaisEdicao`) e o que está marcado agora
     * (`permissoesAtivas`) — nunca recalcula autoridade, só apresenta a
     * diferença já persistida em `PerfilPermissao` de forma legível
     * ("Nome da página — Ação"), pra quem está editando um Perfil já em
     * uso enxergar concretamente "+ ganhou X" / "- perdeu Y" antes de
     * sair da tela, não só a contagem de usuários/obras afetados.
     *
     * @return array{adicionadas: array<int, string>, removidas: array<int, string>}
     */
    #[Computed]
    public function diffPermissoesSessao(): array
    {
        $atuais = array_keys($this->permissoesAtivas);
        $originais = array_keys($this->permissoesOriginaisEdicao);

        $rotular = function (array $chaves): array {
            $nomes = collect(CatalogoFuncionalidades::todas())->keyBy('slug');

            return collect($chaves)->map(function ($chave) use ($nomes) {
                [$slug, $acao] = explode('|', $chave, 2);
                $nomeFuncionalidade = $nomes->get($slug)['nome'] ?? $slug;

                return $nomeFuncionalidade.' — '.CapabilidadeCatalogo::nomeAcao($acao);
            })->sort()->values()->all();
        };

        return [
            'adicionadas' => $rotular(array_diff($atuais, $originais)),
            'removidas' => $rotular(array_diff($originais, $atuais)),
        ];
    }

    public function alternarModo(string $modo): void
    {
        $this->modoEdicao = in_array($modo, ['simples', 'avancado'], true) ? $modo : 'simples';
    }

    public function salvarNome(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $this->validate([
            'nomeEdit' => 'required|string|min:2|max:255',
            'descricaoEdit' => 'nullable|string|max:1000',
        ], [], ['nomeEdit' => 'nome', 'descricaoEdit' => 'descrição']);

        $perfil = Perfil::where('id', $this->abaAtiva)->where('tenant_id', TenantContext::currentId())->firstOrFail();
        $nomeAntes = $perfil->nome;
        $descricaoAntes = $perfil->descricao;
        $descricaoDepois = $this->descricaoEdit ?: null;

        DB::transaction(function () use ($perfil, $nomeAntes, $descricaoAntes, $descricaoDepois) {
            $perfil->update(['nome' => $this->nomeEdit, 'descricao' => $descricaoDepois]);

            // Fase 2D, Seção 21 — RegistrarEventoAcesso já retorna
            // `null` quando nada mudou de fato; nenhum evento é criado
            // nesse caso (salvar sem alteração não gera histórico).
            RegistrarEventoAcesso::perfilDadosAlterados(
                Auth::user(), $perfil, $nomeAntes, $this->nomeEdit, $descricaoAntes, $descricaoDepois
            );
        });

        unset($this->perfis, $this->perfisComImpacto);
        $this->dispatch('show-toast', message: 'Perfil atualizado.');
    }

    /**
     * Compatibilidade — cria um Perfil personalizado do zero, sem passar
     * pelo modal "Novo Perfil" (Seção 9: caminho rápido, sem checklist
     * gigante de capacidades).
     */
    public function novoPerfil(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $perfil = DB::transaction(function () {
            $perfil = Perfil::create([
                'tenant_id' => TenantContext::currentId(),
                'nome' => 'Novo Perfil',
            ]);

            RegistrarEventoAcesso::perfilCriado(Auth::user(), $perfil, 'zero');

            return $perfil;
        });

        unset($this->perfis, $this->perfisComImpacto);
        $this->selecionarAba($perfil->id);
        $this->dispatch('show-toast', message: 'Perfil criado — defina o nome e as permissões.');
    }

    public function abrirNovoPerfil(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $this->reset(['novoPerfilOrigem', 'novoPerfilTemplateChave', 'novoPerfilBaseId', 'novoPerfilNomeCustom']);
        $this->novoPerfilOrigem = 'zero';
        $this->mostrandoNovoPerfil = true;
    }

    public function fecharNovoPerfil(): void
    {
        $this->mostrandoNovoPerfil = false;
    }

    /**
     * Seção 9 — "Novo Perfil" com 3 origens: do zero, a partir de um
     * template especialista, ou a partir de um perfil já existente
     * (equivalente a Duplicar, só que iniciado pelo modal de criação).
     */
    public function confirmarNovoPerfil(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $tenant = Tenant::find(TenantContext::currentId());
        $nomeCustom = trim($this->novoPerfilNomeCustom) !== '' ? trim($this->novoPerfilNomeCustom) : null;
        $ator = Auth::user();

        $perfil = match ($this->novoPerfilOrigem) {
            'template' => $this->criarNovoPerfilDeTemplate($tenant, $nomeCustom, $ator),
            'existente' => $this->criarNovoPerfilDeExistente($nomeCustom, $ator),
            default => DB::transaction(function () use ($tenant, $nomeCustom, $ator) {
                $perfil = Perfil::create([
                    'tenant_id' => $tenant->id,
                    'nome' => $nomeCustom ?? 'Novo Perfil',
                ]);

                RegistrarEventoAcesso::perfilCriado($ator, $perfil, 'zero');

                return $perfil;
            }),
        };

        if ($perfil === null) {
            return;
        }

        unset($this->perfis, $this->perfisComImpacto);
        $this->mostrandoNovoPerfil = false;
        $this->selecionarAba($perfil->id);
        $this->dispatch('show-toast', message: 'Perfil criado.');
    }

    private function criarNovoPerfilDeTemplate(Tenant $tenant, ?string $nomeCustom, \App\Models\User $ator): ?Perfil
    {
        $definicoes = TemplatesEspecialistas::definicoes();

        if (! array_key_exists($this->novoPerfilTemplateChave, $definicoes)) {
            $this->addError('novoPerfilTemplateChave', 'Selecione um template.');

            return null;
        }

        return DB::transaction(function () use ($tenant, $definicoes, $nomeCustom, $ator) {
            $perfil = TemplatesEspecialistas::criar($tenant, $this->novoPerfilTemplateChave, $nomeCustom);

            RegistrarEventoAcesso::perfilCriado($ator, $perfil, 'template', $definicoes[$this->novoPerfilTemplateChave]['nome']);

            return $perfil;
        });
    }

    /**
     * "Novo Perfil → a partir de um perfil existente" (Seção 9) é
     * literalmente a MESMA operação de negócio que o botão "Duplicar"
     * (Seção 20) — os dois chamam `Perfil::duplicar()` e por isso os
     * dois produzem o MESMO tipo de evento (`PerfilDuplicado`), nunca um
     * tipo "criado" à parte, que esconderia que o conteúdo veio de outro
     * Perfil.
     */
    private function criarNovoPerfilDeExistente(?string $nomeCustom, \App\Models\User $ator): ?Perfil
    {
        $base = Perfil::where('tenant_id', TenantContext::currentId())->find($this->novoPerfilBaseId);

        if ($base === null) {
            $this->addError('novoPerfilBaseId', 'Selecione um perfil existente.');

            return null;
        }

        return DB::transaction(function () use ($base, $nomeCustom, $ator) {
            $copia = $base->duplicar($nomeCustom ?? ($base->nome.' (cópia)'));

            RegistrarEventoAcesso::perfilDuplicado($ator, $base, $copia);

            return $copia;
        });
    }

    public function abrirDuplicar(string $perfilId): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $perfil = Perfil::where('tenant_id', TenantContext::currentId())->findOrFail($perfilId);

        $this->duplicarPerfilId = $perfil->id;
        $this->duplicarNomeNovo = $perfil->nome.' (cópia)';
        $this->mostrandoDuplicar = true;
    }

    public function fecharDuplicar(): void
    {
        $this->mostrandoDuplicar = false;
    }

    /**
     * Seção 8 — Duplicar copia só as capacidades (nunca usuários/obras/
     * slug_padrao — `Perfil::duplicar()` já garante isso).
     */
    public function confirmarDuplicar(): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $this->validate(['duplicarNomeNovo' => 'required|string|min:2|max:255'], [], ['duplicarNomeNovo' => 'nome']);

        $original = Perfil::where('tenant_id', TenantContext::currentId())->findOrFail($this->duplicarPerfilId);

        $copia = DB::transaction(function () use ($original) {
            $copia = $original->duplicar($this->duplicarNomeNovo);

            RegistrarEventoAcesso::perfilDuplicado(Auth::user(), $original, $copia);

            return $copia;
        });

        unset($this->perfis, $this->perfisComImpacto);
        $this->mostrandoDuplicar = false;
        $this->selecionarAba($copia->id);
        $this->dispatch('show-toast', message: 'Perfil duplicado — revise o nome e as permissões antes de usar.');
    }

    public function excluirPerfil(string $perfilId): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $perfil = Perfil::where('tenant_id', TenantContext::currentId())->findOrFail($perfilId);

        if ($perfil->slug_padrao === 'admin') {
            $this->dispatch('show-toast', message: 'O perfil Admin não pode ser excluído — quem criou a empresa depende dele.');

            return;
        }

        // Fase 2B — também checa a nova pivot multiperfil
        // (obra_user_perfil), nunca só o espelho legado obra_user.perfil_id
        // — um perfil atribuído SÓ pela nova pivot também conta como "em
        // uso" e não pode ser excluído.
        //
        // Fase 2C, Seção 4-8 — Convite multiperfil: `convite_perfis`
        // também é checada (nunca só a coluna legada `convites.perfil_id`)
        // — um perfil concedido SÓ pela nova pivot de convite também
        // conta como "em uso".
        $emUso = DB::table('obra_user')->where('perfil_id', $perfilId)->exists()
            || DB::table('obra_user_perfil')->where('perfil_id', $perfilId)->exists()
            || Convite::where('perfil_id', $perfilId)->exists()
            || DB::table('convite_perfis')->where('perfil_id', $perfilId)->exists();

        if ($emUso) {
            $this->dispatch('show-toast', message: 'Este perfil está em uso (obra ou convite) e não pode ser excluído.');

            return;
        }

        DB::transaction(function () use ($perfil) {
            // Fase 2D, Seção 23 — snapshot ANTES de excluir (o evento
            // precisa do nome/descrição/capacidades vivos nesse
            // instante; depois do delete, `perfil_nome_snapshot` é a
            // única fonte que resta).
            RegistrarEventoAcesso::perfilExcluido(Auth::user(), $perfil);

            $perfil->permissoes()->delete();
            $perfil->delete();
        });

        unset($this->perfis, $this->perfisComImpacto);

        if ($this->abaAtiva === $perfilId) {
            $this->irParaLista();
        }

        $this->dispatch('show-toast', message: 'Perfil excluído.');
    }

    public function togglePermissao(string $funcionalidade, string $acao): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $perfil = Perfil::where('id', $this->abaAtiva)->where('tenant_id', TenantContext::currentId())->firstOrFail();

        // Fase 2D, Seção 10/11 — antes/depois escopados só a esta
        // funcionalidade (a única coisa que este clique pode mudar) —
        // nunca precisa reler o perfil inteiro pra montar o diff.
        $antes = PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', $funcionalidade)
            ->get(['funcionalidade', 'acao'])->map(fn ($p) => ['funcionalidade' => $p->funcionalidade, 'acao' => $p->acao])->all();

        DB::transaction(function () use ($perfil, $funcionalidade, $acao, $antes) {
            $existente = PerfilPermissao::where('perfil_id', $perfil->id)
                ->where('funcionalidade', $funcionalidade)
                ->where('acao', $acao)
                ->first();

            if ($existente) {
                $existente->delete();
            } else {
                PerfilPermissao::create([
                    'tenant_id' => TenantContext::currentId(),
                    'perfil_id' => $perfil->id,
                    'funcionalidade' => $funcionalidade,
                    'acao' => $acao,
                ]);
            }

            $depois = PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', $funcionalidade)
                ->get(['funcionalidade', 'acao'])->map(fn ($p) => ['funcionalidade' => $p->funcionalidade, 'acao' => $p->acao])->all();

            RegistrarEventoAcesso::perfilCapabilitiesAlteradas(Auth::user(), $perfil, $antes, $depois);
        });

        unset($this->permissoesAtivas, $this->presetAtivoPorFuncionalidade);
    }

    /**
     * Seção 11/12 — Modo Simples: aplica um preset (SEMPRE derivado de
     * capacidades reais, `CapabilidadeCatalogo::presetsPara()`) trocando
     * de uma vez as ações desta funcionalidade pelo conjunto exato do
     * preset — nunca mexe em outra funcionalidade, nunca cria uma
     * autoridade nova (a escrita continua sendo `PerfilPermissao`).
     */
    public function aplicarPreset(string $funcionalidade, string $presetChave): void
    {
        abort_unless(Gate::allows('gerenciar-perfis-acesso'), 403);

        $preset = collect(CapabilidadeCatalogo::presetsPara($funcionalidade))->firstWhere('chave', $presetChave);
        abort_unless($preset !== null, 404);

        $perfil = Perfil::where('id', $this->abaAtiva)->where('tenant_id', TenantContext::currentId())->firstOrFail();

        $antes = PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', $funcionalidade)
            ->get(['funcionalidade', 'acao'])->map(fn ($p) => ['funcionalidade' => $p->funcionalidade, 'acao' => $p->acao])->all();

        DB::transaction(function () use ($perfil, $funcionalidade, $preset, $antes) {
            PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', $funcionalidade)->delete();

            foreach ($preset['acoes'] as $acao) {
                PerfilPermissao::create([
                    'tenant_id' => TenantContext::currentId(),
                    'perfil_id' => $perfil->id,
                    'funcionalidade' => $funcionalidade,
                    'acao' => $acao,
                ]);
            }

            $depois = collect($preset['acoes'])->map(fn ($acao) => ['funcionalidade' => $funcionalidade, 'acao' => $acao])->all();

            RegistrarEventoAcesso::perfilCapabilitiesAlteradas(Auth::user(), $perfil, $antes, $depois);
        });

        unset($this->permissoesAtivas, $this->presetAtivoPorFuncionalidade);
    }
};
?>

<div>
    @if ($visualizacao === 'lista')
        {{-- =============================== LISTAGEM (Seção 4) =============================== --}}
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <div class="flex-grow-1" style="max-width: 360px">
                <input type="text" class="form-control" wire:model.live.debounce.300ms="busca"
                       placeholder="Buscar por nome ou descrição...">
            </div>
            <button type="button" class="btn btn-primary" wire:click="abrirNovoPerfil">
                <i class="bx bx-plus me-1"></i>Novo Perfil
            </button>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Descrição</th>
                            <th>Tipo</th>
                            <th class="text-center">Usuários efetivos</th>
                            <th class="text-center">Obras</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->perfisFiltrados as $item)
                        <tr wire:key="perfil-{{ $item->perfil->id }}" style="cursor:pointer" wire:click="selecionarAba('{{ $item->perfil->id }}')">
                            <td class="fw-semibold">{{ $item->perfil->nome }}</td>
                            <td class="text-muted small">{{ $item->perfil->descricao ?: '—' }}</td>
                            <td>
                                @if ($item->perfil->ehPadrao())
                                    <span class="badge bg-label-info">Perfil Padrão DCF.ENG</span>
                                @else
                                    <span class="badge bg-label-secondary">Perfil Personalizado</span>
                                @endif
                            </td>
                            <td class="text-center">{{ $item->usuarios }}</td>
                            <td class="text-center">{{ $item->obras }}</td>
                            <td class="text-center" onclick="event.stopPropagation()">
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" title="Ver/editar"
                                        wire:click="selecionarAba('{{ $item->perfil->id }}')">
                                    <i class="bx bx-edit-alt"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1" title="Duplicar"
                                        wire:click="abrirDuplicar('{{ $item->perfil->id }}')">
                                    <i class="bx bx-copy"></i>
                                </button>
                                @if ($item->perfil->slug_padrao !== 'admin')
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Excluir"
                                        onclick="confirmarAcao(this, {
                                            mensagem: '{{ $item->perfil->ehPadrao() ? 'Este é um Perfil Padrão DCF.ENG. Excluir não afeta o funcionamento do sistema, mas só é possível se ele não estiver em uso em nenhuma obra ou convite. Confirma a exclusão?' : 'Excluir este perfil? Só é possível se ele não estiver em uso.' }}',
                                            metodo: 'excluirPerfil',
                                            args: ['{{ $item->perfil->id }}'],
                                            icone: 'bx-trash',
                                        })">
                                    <i class="bx bx-trash"></i>
                                </button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Nenhum perfil encontrado.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- =============================== MODAL: NOVO PERFIL (Seção 9) =============================== --}}
        @if ($mostrandoNovoPerfil)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Novo Perfil</h5>
                        <button type="button" class="btn-close" wire:click="fecharNovoPerfil"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nome do novo perfil (opcional)</label>
                            <input type="text" class="form-control" wire:model="novoPerfilNomeCustom" placeholder="Ex.: Engenheiro Sênior">
                        </div>

                        <label class="form-label d-block">Como este perfil deve começar?</label>
                        <div class="list-group mb-3">
                            <label class="list-group-item">
                                <input class="form-check-input me-2" type="radio" value="zero" wire:model.live="novoPerfilOrigem">
                                <strong>Do zero</strong> — sem nenhuma permissão marcada, você define tudo depois.
                            </label>
                            <label class="list-group-item">
                                <input class="form-check-input me-2" type="radio" value="template" wire:model.live="novoPerfilOrigem">
                                <strong>A partir de um template</strong> — começa com as capacidades de um perfil especialista pronto.
                            </label>
                            <label class="list-group-item">
                                <input class="form-check-input me-2" type="radio" value="existente" wire:model.live="novoPerfilOrigem">
                                <strong>A partir de um perfil existente</strong> — copia as permissões de um perfil já cadastrado (equivalente a Duplicar).
                            </label>
                        </div>

                        @if ($novoPerfilOrigem === 'template')
                        <div class="mb-2">
                            <label class="form-label">Template</label>
                            <select class="form-select @error('novoPerfilTemplateChave') is-invalid @enderror" wire:model="novoPerfilTemplateChave">
                                <option value="">Selecione...</option>
                                @foreach ($this->templatesEspecialistas as $chave => $definicao)
                                <option value="{{ $chave }}">{{ $definicao['nome'] }}</option>
                                @endforeach
                            </select>
                            @error('novoPerfilTemplateChave')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if ($novoPerfilTemplateChave && isset($this->templatesEspecialistas[$novoPerfilTemplateChave]))
                            <div class="form-text">{{ $this->templatesEspecialistas[$novoPerfilTemplateChave]['descricao'] }}</div>
                            @endif
                        </div>
                        @endif

                        @if ($novoPerfilOrigem === 'existente')
                        <div class="mb-2">
                            <label class="form-label">Perfil base</label>
                            <select class="form-select @error('novoPerfilBaseId') is-invalid @enderror" wire:model="novoPerfilBaseId">
                                <option value="">Selecione...</option>
                                @foreach ($this->perfis as $p)
                                <option value="{{ $p->id }}">{{ $p->nome }}</option>
                                @endforeach
                            </select>
                            @error('novoPerfilBaseId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharNovoPerfil">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmarNovoPerfil" wire:loading.attr="disabled">Criar perfil</button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- =============================== MODAL: DUPLICAR (Seção 8) =============================== --}}
        @if ($mostrandoDuplicar)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Duplicar Perfil</h5>
                        <button type="button" class="btn-close" wire:click="fecharDuplicar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Copia apenas as capacidades do perfil — nunca usuários, obras ou o vínculo de perfil padrão.</p>
                        <label class="form-label">Nome do novo perfil</label>
                        <input type="text" class="form-control @error('duplicarNomeNovo') is-invalid @enderror" wire:model="duplicarNomeNovo">
                        @error('duplicarNomeNovo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharDuplicar">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmarDuplicar" wire:loading.attr="disabled">Duplicar</button>
                    </div>
                </div>
            </div>
        </div>
        @endif

    @else
        {{-- =============================== EDITOR (Seção 4/9-18) =============================== --}}
        @php($p = $this->perfilAtivo)
        @if ($p)
        <button type="button" class="btn btn-link ps-0 mb-2" wire:click="irParaLista">
            <i class="bx bx-arrow-back me-1"></i>Voltar para a lista de perfis
        </button>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        @if ($p->ehPadrao())
                            <span class="badge bg-label-info mb-1">Perfil Padrão DCF.ENG</span>
                        @else
                            <span class="badge bg-label-secondary mb-1">Perfil Personalizado</span>
                        @endif
                        <div class="small text-muted">
                            Afeta <strong>{{ $this->impactoAtivo['usuarios'] }}</strong> usuário(s) em
                            <strong>{{ $this->impactoAtivo['obras'] }}</strong> obra(s).
                        </div>
                        {{-- Seção 38/39 — diff simples do que mudou NESTA sessão de
                             edição, além da contagem acima. Só aparece quando há
                             algo pra mostrar (nunca um card vazio). --}}
                        @php($diff = $this->diffPermissoesSessao)
                        @if (($diff['adicionadas'] !== [] || $diff['removidas'] !== []) && $this->impactoAtivo['usuarios'] > 0)
                        <div class="alert alert-warning small py-2 px-3 mt-2 mb-0">
                            <div class="fw-semibold mb-1">
                                <i class="bx bx-error-circle me-1"></i>
                                Esta alteração afetará {{ $this->impactoAtivo['usuarios'] }} usuário(s) em {{ $this->impactoAtivo['obras'] }} obra(s):
                            </div>
                            @foreach ($diff['adicionadas'] as $item)
                            <div class="text-success">+ Ganha: {{ $item }}</div>
                            @endforeach
                            @foreach ($diff['removidas'] as $item)
                            <div class="text-danger">− Perde: {{ $item }}</div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirDuplicar('{{ $p->id }}')">
                            <i class="bx bx-copy me-1"></i>Duplicar
                        </button>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="form-label">Nome do perfil</label>
                        <input type="text" class="form-control @error('nomeEdit') is-invalid @enderror" wire:model="nomeEdit">
                        @error('nomeEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Descrição</label>
                        <input type="text" class="form-control @error('descricaoEdit') is-invalid @enderror" wire:model="descricaoEdit"
                               placeholder="Explique em uma frase quem deveria usar este perfil.">
                        @error('descricaoEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" wire:click="salvarNome" wire:loading.attr="disabled">
                            <i class="bx bx-save me-1"></i>Salvar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <h6 class="fw-bold mb-0">Permissões</h6>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn {{ $modoEdicao === 'simples' ? 'btn-primary' : 'btn-outline-primary' }}"
                                wire:click="alternarModo('simples')">Modo Simples</button>
                        <button type="button" class="btn {{ $modoEdicao === 'avancado' ? 'btn-primary' : 'btn-outline-primary' }}"
                                wire:click="alternarModo('avancado')">Modo Avançado</button>
                    </div>
                </div>

                @if ($modoEdicao === 'simples')
                    {{-- ---------- MODO SIMPLES (Seção 10-17): presets por domínio ---------- --}}
                    <p class="text-muted small">
                        Escolha, para cada página, o nível de acesso deste perfil. Se a combinação atual não
                        corresponder a nenhuma opção abaixo, use o Modo Avançado para ver exatamente o que está marcado.
                    </p>

                    @foreach ($this->dominiosCapacidades as $dominio => $itens)
                    <h6 class="text-muted small text-uppercase mt-4 mb-2">{{ $dominio }}</h6>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm align-middle">
                            <tbody>
                                @foreach ($itens as $item)
                                @php($presets = \App\Support\Perfis\CapabilidadeCatalogo::presetsPara($item['slug']))
                                @php($ativo = $this->presetAtivoPorFuncionalidade[$item['slug']] ?? null)
                                @php($acoesElevadas = \App\Support\Perfis\CapabilidadeCatalogo::acoesElevadasReais($item['slug']))
                                <tr wire:key="simples-{{ $item['slug'] }}">
                                    <td style="width: 40%">{{ $item['nome'] }}</td>
                                    <td>
                                        <select class="form-select form-select-sm"
                                                wire:change="aplicarPreset('{{ $item['slug'] }}', $event.target.value)">
                                            @foreach ($presets as $preset)
                                            <option value="{{ $preset['chave'] }}" @selected($ativo === $preset['chave'])>{{ $preset['nome'] }}</option>
                                            @endforeach
                                            @if ($ativo === null)
                                            <option value="" selected disabled>Personalizado (ver Modo Avançado)</option>
                                            @endif
                                        </select>
                                        {{-- FASE 2C, Seção 15/26 — "Operação" nunca concede uma ação de
                                             autoridade formal elevada (ex.: Liberar para construção) por
                                             engano; a UI avisa isso explicitamente em vez de esconder. --}}
                                        @if ($acoesElevadas !== [] && $ativo === 'operacao')
                                        <small class="text-muted d-block mt-1">
                                            <i class="bx bx-info-circle"></i>
                                            Não inclui
                                            {{ collect($acoesElevadas)->map(fn ($a) => \App\Support\Perfis\CapabilidadeCatalogo::nomeAcao($a))->implode(', ') }}
                                            — selecione "Gestão completa" ou use o Modo Avançado se este perfil precisar disso.
                                        </small>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endforeach
                @else
                    {{-- ---------- MODO AVANÇADO (Seção 16-18): capacidades reais por funcionalidade ---------- --}}
                    <p class="text-muted small">
                        Marque exatamente o que este perfil pode fazer em cada página do sistema.
                    </p>

                    @foreach ($this->dominiosCapacidades as $dominio => $itens)
                    <h6 class="text-muted small text-uppercase mt-4 mb-2">{{ $dominio }}</h6>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm align-middle">
                            <tbody>
                                @foreach ($itens as $item)
                                <tr wire:key="avancado-{{ $item['slug'] }}">
                                    <td style="width: 30%">{{ $item['nome'] }}</td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-3">
                                            @foreach (\App\Models\Perfil::acoesReaisPara($item['slug']) as $acao)
                                            <div class="form-check" title="{{ \App\Support\Perfis\CapabilidadeCatalogo::ajudaAcao($acao) }}">
                                                <input type="checkbox" class="form-check-input" style="cursor:pointer"
                                                       id="perm-{{ $item['slug'] }}-{{ $acao }}"
                                                       wire:click="togglePermissao('{{ $item['slug'] }}', '{{ $acao }}')"
                                                       @checked(isset($this->permissoesAtivas[$item['slug'].'|'.$acao]))>
                                                <label class="form-check-label small" for="perm-{{ $item['slug'] }}-{{ $acao }}">
                                                    {{ \App\Support\Perfis\CapabilidadeCatalogo::nomeAcao($acao) }}
                                                </label>
                                            </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endforeach
                @endif
            </div>
        </div>
        @else
        <p class="text-muted">Perfil não encontrado.</p>
        @endif
    @endif
</div>
