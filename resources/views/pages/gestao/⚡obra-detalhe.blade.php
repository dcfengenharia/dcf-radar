<?php

use App\Enums\OrigemEventoHistoricoAcesso;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\Client;
use App\Models\Convite;
use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Perfil;
use App\Models\Restricao;
use App\Models\User;
use App\Models\Work;
use App\Notifications\AdicionadoAObraNotification;
use App\Notifications\ConviteObraNotification;
use App\Support\Perfis\RegistrarEventoAcesso;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

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
    public ?string $diaSemanaReportEdit = null;

    // ---- Equipe ----
    public string  $buscaUsuario       = '';
    public ?string $perfilNovoMembroId = null;

    // ---- Equipe: edição multiperfil (Fase 2C) ----
    public bool    $editandoPerfisMembro   = false;
    public ?string $membroEditandoPerfisId = null;
    public array   $perfisSelecionadosEdicao = [];

    // ---- Convite por e-mail (Fase 2C, Seção 4 — multiperfil) ----
    public string  $emailConvite     = '';
    public array   $perfisConviteIds = [];

    // ---- Gantt do Cronograma ----
    public ?string $linhaBaseId          = null;
    public ?string $tendenciaImportacaoId = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        $perfilPadrao = Perfil::porSlugPadrao($obra->tenant, 'encarregado');
        $this->perfilNovoMembroId = $perfilPadrao?->id;
        $this->perfisConviteIds = $perfilPadrao ? [$perfilPadrao->id] : [];
    }

    #[Computed]
    public function perfisDisponiveis()
    {
        return Perfil::where('tenant_id', $this->obra->tenant_id)->orderBy('nome')->get();
    }

    public function setAba(string $aba): void
    {
        $this->abaAtiva = $aba;

        if ($aba === 'cronograma') {
            $this->dispatch('cronograma-tab-ativada', ganttData: $this->dadosGantt);
        }
    }

    // Troca de Linha de Base/Tendência com a aba Cronograma já aberta —
    // precisa redespachar o mesmo evento do dhtmlxGantt (ver setAba()),
    // senão o filtro muda o estado mas o Gantt na tela continua com os
    // dados antigos até a próxima troca de aba.
    public function updatedLinhaBaseId(): void
    {
        unset($this->dadosGantt);
        if ($this->abaAtiva === 'cronograma') {
            $this->dispatch('cronograma-tab-ativada', ganttData: $this->dadosGantt);
        }
    }

    public function updatedTendenciaImportacaoId(): void
    {
        unset($this->dadosGantt);
        if ($this->abaAtiva === 'cronograma') {
            $this->dispatch('cronograma-tab-ativada', ganttData: $this->dadosGantt);
        }
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
        $this->diaSemanaReportEdit = $this->obra->dia_semana_report !== null ? (string) $this->obra->dia_semana_report : '';
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
            'diaSemanaReportEdit' => 'nullable|in:0,1,2,3,4,5,6',
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
            'dia_semana_report' => $this->diaSemanaReportEdit !== '' && $this->diaSemanaReportEdit !== null
                ? (int) $this->diaSemanaReportEdit
                : null,
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

        // Fase 2D — a nova pivot é escrita/auditada ANTES de tocar o
        // espelho legado abaixo, de propósito: o snapshot "antes" do
        // evento é lido de dentro de `definirPerfilUnico()` (via
        // `perfisAtuais()`, que cai pro fallback legado quando a pivot
        // nova está vazia) — se o legado já tivesse sido atualizado
        // pelo `syncWithoutDetaching()` primeiro, o "antes" leria o
        // valor NOVO por engano (achado real durante os testes desta
        // fase: o diff saía sempre vazio, porque "antes" e "depois"
        // acabavam iguais).
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico(
            $this->obra, $userId, $this->perfilNovoMembroId, Auth::user(), OrigemEventoHistoricoAcesso::EquipeObra
        );
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

        // Fase 2D — mesma ordem cuidadosa de `adicionarMembro()`: audita
        // ANTES de tocar o espelho legado, senão o "antes" do evento
        // leria o valor NOVO (o legado já sobrescrito) por engano.
        // Fase 2B — a UI continua single-perfil (Seção 26: sem redesign
        // nesta fase); trocar aqui SUBSTITUI qualquer atribuição anterior
        // na nova pivot pela nova escolha, nunca soma.
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico(
            $this->obra, $userId, $perfilId, Auth::user(), OrigemEventoHistoricoAcesso::EquipeObra
        );
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

        $membro = User::find($userId);

        // Fase 2D, Seção 16 — "usuário removido da obra" é SEMANTICAMENTE
        // diferente de "perfis removidos" (o usuário deixa de ser
        // MEMBRO, não só perde capacidade) — evento próprio, com
        // snapshot dos perfis que possuía ANTES da remoção. Construído
        // aqui (nunca dentro de `removerTodas()`, que continua sendo só
        // o helper de baixo nível "limpe a pivot") pra nunca duplicar
        // com um evento genérico de `AtribuicaoPerfilObra`.
        DB::transaction(function () use ($userId, $membro) {
            $perfisAntes = $membro?->perfisNaObra($this->obra) ?? collect();

            $this->obra->users()->detach($userId);
            // Fase 2B — remove também qualquer atribuição na nova pivot pra
            // este par (obra, usuário).
            \App\Support\AtribuicaoPerfilObra::removerTodas($this->obra, $userId);

            if ($membro !== null) {
                RegistrarEventoAcesso::usuarioRemovidoDaObra(
                    Auth::user(), $this->obra, $membro, $perfisAntes, OrigemEventoHistoricoAcesso::EquipeObra
                );
            }
        });

        unset($this->membrosEquipe, $this->usuariosParaAdicionar);
        $this->dispatch('show-toast', message: 'Membro removido da equipe.');
    }

    // =========================================================================
    // EQUIPE — MULTIPERFIL (FASE 2C, Seção 19-21)
    // =========================================================================

    /**
     * Abre o modal de edição de perfis pra um membro — SEPARADO da ação
     * de remover da equipe (Seção 20: "membership e Perfil são conceitos
     * separados"; "remover perfis" nunca é a mesma ação que "remover
     * usuário da obra"). Pré-popula com os perfis EFETIVOS atuais
     * (`perfisNaObra()`, já resolvidos pelo core aprovado na Fase
     * 2B.CORREÇÃO — autoridade completa da nova pivot quando populada,
     * fallback legado só quando vazia).
     */
    public function abrirEdicaoPerfis(string $userId): void
    {
        $this->authorize('update', $this->obra);

        $membro = $this->obra->users()->where('user_id', $userId)->first();
        abort_unless($membro !== null, 404);

        $this->membroEditandoPerfisId = $userId;
        $this->perfisSelecionadosEdicao = $membro->perfisNaObra($this->obra)->pluck('id')->all();
        $this->editandoPerfisMembro = true;
    }

    public function fecharEdicaoPerfis(): void
    {
        $this->editandoPerfisMembro = false;
        $this->membroEditandoPerfisId = null;
        $this->perfisSelecionadosEdicao = [];
    }

    /**
     * Salva a coleção de perfis do membro via a API multiperfil aprovada
     * (`substituirPerfis()`) — nunca escreve a pivot diretamente (Seção
     * 19: "Não escrever pivot diretamente"). Revalida no servidor que
     * todo ID selecionado pertence de fato a `$this->perfisDisponiveis`
     * (já escopado ao tenant desta obra) — nunca confia em IDs vindos do
     * browser (Seção 41).
     */
    public function salvarPerfisMembro(): void
    {
        $this->authorize('update', $this->obra);

        $userId = $this->membroEditandoPerfisId;
        abort_if($userId === null, 400);

        $ehCriadorDoTenant = $userId === $this->obra->tenant->criado_por_id;
        abort_if($ehCriadorDoTenant && ! Auth::user()->is_platform_admin, 403, 'O(s) perfil(is) de quem criou a empresa só pode(m) ser alterado(s) pelo administrador da plataforma.');

        $idsValidos = $this->perfisDisponiveis->pluck('id')->all();
        $novosPerfilIds = array_values(array_intersect($this->perfisSelecionadosEdicao, $idsValidos));

        if ($this->removeriaOUltimoAdminComNovosPerfis($userId, $novosPerfilIds)) {
            $this->dispatch('show-toast', message: 'Esta obra precisa permanecer com pelo menos um administrador. Atribua Admin a outra pessoa antes de remover.');
            return;
        }

        \App\Support\AtribuicaoPerfilObra::substituirPerfis(
            $this->obra, $userId, $novosPerfilIds, Auth::user(), OrigemEventoHistoricoAcesso::EquipeObra
        );

        unset($this->membrosEquipe);
        $this->fecharEdicaoPerfis();

        $this->dispatch('show-toast', message: $novosPerfilIds === []
            ? 'Perfis removidos — o usuário continua membro da equipe, mas sem acesso às funcionalidades protegidas.'
            : 'Perfis atualizados.');
    }

    /**
     * Trava de integridade: todo tenant precisa ter, no mínimo, um
     * Administrador (perfil com slug_padrao='admin') em ALGUMA obra —
     * vale pra qualquer usuário, inclusive o administrador da
     * plataforma, que só pode contornar a trava do CRIADOR do tenant,
     * nunca esta. $novoPerfilId null = removendo o membro da equipe.
     *
     * Fase 2B, Seção 29 — "era Admin nesta obra" e "tem outro Admin no
     * tenant" agora usam a ESTRUTURA EFETIVA (união dos perfis do
     * usuário — `temPerfilNaObra()`/nova pivot — nunca só a leitura
     * crua do espelho legado `obra_user.perfil_id`). Isso cobre
     * corretamente um Admin atribuído só pela nova pivot multiperfil
     * (ex.: um usuário com Admin + Planejamento simultâneos), que a
     * leitura crua antiga nunca enxergaria. A UI desta fase continua
     * single-perfil (Seção 26) — `alterarPerfil()`/`removerMembro()`
     * substituem TODAS as atribuições do usuário nesta obra por, no
     * máximo, 1 nova — então "perder só um perfil secundário mantendo
     * Admin" só é expressável hoje por atribuição direta na nova pivot
     * (fora da UI), não pela tela; o guard já está correto pra quando a
     * Fase 2C introduzir seleção multi-perfil.
     */
    private function removeriaOUltimoAdmin(string $userId, ?string $novoPerfilId): bool
    {
        return $this->removeriaOUltimoAdminComNovosPerfis($userId, $novoPerfilId !== null ? [$novoPerfilId] : []);
    }

    /**
     * FASE 2C, Seção 27 — generalização multiperfil do guard acima:
     * "continua Admin" agora é "Admin está ENTRE os novos perfis
     * selecionados", nunca mais um único ID. `removeriaOUltimoAdmin()`
     * (acima) delega pra cá com um array de 0 ou 1 elemento — nenhum dos
     * 2 callers legados (`alterarPerfil()`/`removerMembro()`) precisou
     * mudar. A regra em si foi extraída pra
     * `App\Support\Perfis\GuardUltimoAdmin` (reaproveitada também pela
     * Matriz de Acessos) — nunca duas implementações da mesma checagem de
     * segurança.
     *
     * @param  array<int, string>  $novosPerfilIds
     */
    private function removeriaOUltimoAdminComNovosPerfis(string $userId, array $novosPerfilIds): bool
    {
        return \App\Support\Perfis\GuardUltimoAdmin::removeriaOUltimoAdmin($this->obra, $userId, $novosPerfilIds);
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

        $chaveThrottle = 'enviar-convite:'.Auth::id();
        if (RateLimiter::tooManyAttempts($chaveThrottle, 10)) {
            $segundos = RateLimiter::availableIn($chaveThrottle);
            $this->addError('emailConvite', "Muitos convites enviados em pouco tempo. Tente de novo em {$segundos} segundos.");
            return;
        }
        RateLimiter::hit($chaveThrottle, 60);

        $this->validate([
            'emailConvite' => 'required|email',
            'perfisConviteIds' => ['required', 'array', 'min:1'],
            'perfisConviteIds.*' => Rule::exists('perfis', 'id')->where('tenant_id', $this->obra->tenant_id),
        ], [], ['emailConvite' => 'e-mail', 'perfisConviteIds' => 'perfis de acesso']);

        // Fase 2C, Seção 4 — sempre um conjunto de 1..N perfis, nunca
        // mais um único ID; deduplicado por segurança (checkbox
        // duplicado não é possível na UI, mas nunca custa garantir).
        $perfisConviteIds = array_values(array_unique($this->perfisConviteIds));

        $usuarioExistente = User::where('tenant_id', $this->obra->tenant_id)
            ->where('email', $this->emailConvite)
            ->first();

        if ($usuarioExistente) {
            if ($this->obra->users()->where('user_id', $usuarioExistente->id)->exists()) {
                $this->addError('emailConvite', 'Este usuário já faz parte da equipe desta obra.');
                return;
            }

            // Fase 2D — auditado ANTES do attach() legado, mesma ordem
            // cuidadosa de adicionarMembro()/alterarPerfil() (senão o
            // "antes" do evento leria o legado já com o valor novo).
            // Fase 2B/2C — mantém a nova pivot multiperfil consistente.
            // Fase 2D — origem Convite (mesmo card "Convidar por
            // e-mail", mesmo que o resultado seja um vínculo imediato
            // em vez de um Convite pendente).
            \App\Support\AtribuicaoPerfilObra::substituirPerfis(
                $this->obra, $usuarioExistente->id, $perfisConviteIds, Auth::user(), OrigemEventoHistoricoAcesso::Convite
            );
            $this->obra->users()->attach($usuarioExistente->id, ['perfil_id' => $perfisConviteIds[0]]);
            $usuarioExistente->notify(new AdicionadoAObraNotification(
                $this->obra,
                Perfil::whereIn('id', $perfisConviteIds)->orderBy('nome')->get()
            ));

            $this->resetConvite();
            unset($this->membrosEquipe, $this->usuariosParaAdicionar);
            $this->dispatch('show-toast', message: 'Usuário já cadastrado — adicionado direto à equipe.');
            return;
        }

        if (Convite::where('obra_id', $this->obra->id)->where('email', $this->emailConvite)->where('status', 'pendente')->exists()) {
            $this->addError('emailConvite', 'Já existe um convite pendente para este e-mail nesta obra.');
            return;
        }

        // Só bloqueia aqui pra dar feedback cedo — quem cria de verdade o
        // usuário novo é ConviteController::aceitar(), que repete a mesma
        // checagem no momento da aceitação (o limite pode mudar entre o
        // envio e a aceitação de um convite).
        $limite = $this->obra->tenant->limiteUsuarios();
        if ($limite !== null && User::where('tenant_id', $this->obra->tenant_id)->count() >= $limite) {
            $this->addError('emailConvite', "Seu plano permite no máximo {$limite} usuário(s). Fale com o administrador da conta pra aumentar o limite.");
            return;
        }

        $perfisSelecionadosModels = Perfil::whereIn('id', $perfisConviteIds)->orderBy('nome')->get();

        // Fase 2D, Seção 17/31 — criação do Convite + gravação dos
        // perfis + evento de auditoria, tudo na MESMA transação (se o
        // registro de auditoria falhar, o convite inteiro não é criado
        // — nunca "convite enviado sem rastro").
        $convite = DB::transaction(function () use ($perfisConviteIds, $perfisSelecionadosModels) {
            $convite = Convite::create([
                'obra_id' => $this->obra->id,
                'email' => $this->emailConvite,
                // Primeiro perfil selecionado — mantido só pra
                // compatibilidade de exibição legada (`$convite->perfil`);
                // a lista COMPLETA de perfis concedidos vive em
                // `convite_perfis`, gravada logo abaixo.
                'perfil_id' => $perfisConviteIds[0],
                'token' => Str::random(64),
                'convidado_por_id' => Auth::id(),
                'expira_em' => now()->addDays(7),
            ]);
            \App\Support\Perfis\AtribuicaoPerfilConvite::gravar($convite, $perfisConviteIds);

            RegistrarEventoAcesso::conviteEnviado(Auth::user(), $this->obra, $convite->email, $perfisSelecionadosModels);

            return $convite;
        });

        Notification::route('mail', $convite->email)->notify(new ConviteObraNotification($convite));

        $this->resetConvite();
        unset($this->convitesPendentes);
        $this->dispatch('show-toast', message: 'Convite enviado por e-mail.');
    }

    private function resetConvite(): void
    {
        $this->emailConvite = '';
        $perfilPadrao = Perfil::porSlugPadrao($this->obra->tenant, 'encarregado');
        $this->perfisConviteIds = $perfilPadrao ? [$perfilPadrao->id] : [];
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

    // Teto de atividades no Gantt — dhtmlxGantt tem scroll virtual e aguenta
    // volume real de projeto; só existe pra evitar um payload patológico.
    private const LIMITE_GANTT = 1000;

    /**
     * Importações elegíveis pra "Tendência" — só as que gravam
     * Realizado/Tendência (seção Relatórios → Importar Avanço), nunca uma
     * importação Baseline pura. Mesmo filtro por tipo do Lookahead/
     * `CurvaAvanco::resolverImportacaoId()`.
     */
    #[Computed]
    public function importacoesDisponiveis()
    {
        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->whereIn('tipo', [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value])
            ->orderByDesc('importado_em')
            ->orderByDesc('id')
            ->get(['id', 'arquivo', 'importado_em']);
    }

    #[Computed]
    public function linhasBaseGantt()
    {
        return LinhaBase::where('obra_id', $this->obra->id)
            ->with('importacao:id,importado_em,arquivo')
            ->latest()
            ->get(['id', 'nome', 'cronograma_importacao_id']);
    }

    /** Importação tratada como "tendência" — a selecionada, ou a mais recente (Avanço/Ambos). */
    #[Computed]
    public function importacaoTendenciaAtual(): ?CronogramaImportacao
    {
        if ($this->tendenciaImportacaoId) {
            return $this->importacoesDisponiveis->firstWhere('id', $this->tendenciaImportacaoId);
        }

        return $this->importacoesDisponiveis->first();
    }

    #[Computed]
    public function temImportacaoAvanco(): bool
    {
        return $this->importacoesDisponiveis->isNotEmpty();
    }

    #[Computed]
    public function linhaBaseSelecionadaGantt(): ?LinhaBase
    {
        if (! $this->linhaBaseId) {
            return null;
        }

        return $this->linhasBaseGantt->firstWhere('id', $this->linhaBaseId);
    }

    #[Computed]
    public function ganttAtividades()
    {
        return Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->orderBy('id')
            ->limit(self::LIMITE_GANTT)
            ->get();
    }

    /**
     * Compara dois códigos de EAP (ex: "5.1.10" vs "5.1.3") segmento a
     * segmento como números — mesmo helper usado no Lookahead/Linhas de
     * Base (convenção do projeto: um comparador por arquivo, não
     * compartilhado via trait), pra manter a mesma ordenação em todo o app.
     */
    private function compararCodigos(?string $a, ?string $b): int
    {
        $a = explode('.', $a ?? '');
        $b = explode('.', $b ?? '');

        foreach (range(0, max(count($a), count($b)) - 1) as $i) {
            $x = (int) ($a[$i] ?? 0);
            $y = (int) ($b[$i] ?? 0);
            if ($x !== $y) {
                return $x <=> $y;
            }
        }

        return 0;
    }

    /**
     * Ordena atividades dentro do mesmo pacote: por ordem_manual quando
     * definida (mesmo campo usado pra reordenar no Lookahead); senão pelo
     * código do cronograma (posição original no MS Project — fiel à
     * sequência importada); só cai pra início planejado e nome quando não
     * há nem reordenação manual nem código (dado legado ou atividade
     * manual nunca importada).
     */
    private function compararOrdemAtividade(Atividade $a, Atividade $b): int
    {
        if ($a->ordem_manual !== null && $b->ordem_manual !== null) {
            return $a->ordem_manual <=> $b->ordem_manual;
        }
        if ($a->ordem_manual !== null) {
            return -1;
        }
        if ($b->ordem_manual !== null) {
            return 1;
        }

        if ($a->codigo_cronograma !== null && $b->codigo_cronograma !== null) {
            return $this->compararCodigos($a->codigo_cronograma, $b->codigo_cronograma);
        }

        $ia = $a->inicio_planejado?->timestamp ?? PHP_INT_MAX;
        $ib = $b->inicio_planejado?->timestamp ?? PHP_INT_MAX;

        return $ia !== $ib ? $ia <=> $ib : strcmp($a->nome, $b->nome);
    }

    /**
     * Monta a árvore no formato do dhtmlxGantt: pacotes viram linhas
     * `type=project` (resumo automático do dhtmlx, expandível/recolhível
     * nativamente) e atividades viram linhas `type=task` penduradas no
     * pacote via `parent`. As barras seguem a Linha de Base escolhida no
     * filtro (`linhaBaseId`, ou "ao vivo" sem seleção — mesmo padrão do
     * Lookahead) — datas do pacote = união (min início/max término) dos
     * próprios descendentes NESSA seleção, calculada aqui, nunca inferida
     * ao vivo pelo dhtmlx. Início/Término de Tendência (a importação de
     * Avanço escolhida em `tendenciaImportacaoId`, ou a mais recente sem
     * seleção) entram como colunas informativas extras — nunca viram uma
     * segunda barra (recurso de baseline visual é Pro do dhtmlx).
     */
    #[Computed]
    public function dadosGantt(): array
    {
        $atividades = $this->ganttAtividades;

        if ($atividades->isEmpty()) {
            return ['data' => [], 'links' => [], 'temTendencia' => $this->temImportacaoAvanco];
        }

        $lb = $this->linhaBaseSelecionadaGantt;
        $snapshotsBaseline = $lb
            ? AtividadeSnapshot::where('cronograma_importacao_id', $lb->cronograma_importacao_id)
                ->whereIn('atividade_id', $atividades->pluck('id'))
                ->get()->keyBy('atividade_id')
            : collect();

        $tendenciaIdEfetivo = $this->tendenciaImportacaoId ?: $this->importacaoTendenciaAtual?->id;
        $snapshotsTendencia = $tendenciaIdEfetivo
            ? AtividadeSnapshot::where('cronograma_importacao_id', $tendenciaIdEfetivo)
                ->whereIn('atividade_id', $atividades->pluck('id'))
                ->get()->keyBy('atividade_id')
            : collect();

        $datasPorAtividade = [];
        foreach ($atividades as $at) {
            if ($lb) {
                $snapB = $snapshotsBaseline->get($at->id);
                $inicioBase = $snapB?->baseline_inicio;
                $terminoBase = $snapB?->baseline_termino;
            } else {
                $inicioBase = $at->baseline_inicio;
                $terminoBase = $at->baseline_termino;
            }

            if ($tendenciaIdEfetivo) {
                $snapT = $snapshotsTendencia->get($at->id);
                $inicioTend = $snapT?->inicio_planejado;
                $terminoTend = $snapT?->data_termino;
            } else {
                $inicioTend = null;
                $terminoTend = null;
            }

            $datasPorAtividade[$at->id] = compact('inicioBase', 'terminoBase', 'inicioTend', 'terminoTend');
        }

        $pacotes = PacoteTrabalho::where('obra_id', $this->obra->id)->get(['id', 'nome', 'codigo', 'parent_id'])->keyBy('id');
        $atividadesPorPacote = $atividades->groupBy(fn ($at) => $at->pacote_trabalho_id ?? 'sem_pacote');

        // Só entram na árvore atividades com Linha de Base resolvida nessa
        // seleção (sem isso não há de onde vir a barra) e os pacotes que
        // têm alguma delas (direta ou em algum descendente) — nunca mostra
        // pacote vazio só porque existe cadastrado.
        $pacotesComAtividade = [];
        foreach ($atividades as $at) {
            if (! $datasPorAtividade[$at->id]['inicioBase'] || ! $at->pacote_trabalho_id) {
                continue;
            }
            $pacoteId = $at->pacote_trabalho_id;
            while ($pacoteId && ! isset($pacotesComAtividade[$pacoteId])) {
                $pacotesComAtividade[$pacoteId] = true;
                $pacoteId = $pacotes->get($pacoteId)?->parent_id;
            }
        }

        $subPacotesPorPai = $pacotes->filter(fn ($p) => isset($pacotesComAtividade[$p->id]))->groupBy('parent_id');

        $intervalos = [];
        $calcularIntervalo = function ($pacoteId) use (&$calcularIntervalo, &$intervalos, $atividadesPorPacote, $subPacotesPorPai, $datasPorAtividade) {
            if (array_key_exists($pacoteId, $intervalos)) {
                return $intervalos[$pacoteId];
            }
            $min = null;
            $max = null;
            foreach ($atividadesPorPacote->get($pacoteId, collect()) as $at) {
                $d = $datasPorAtividade[$at->id];
                if (! $d['inicioBase']) {
                    continue;
                }
                $min = $min === null || $d['inicioBase']->lt($min) ? $d['inicioBase'] : $min;
                $max = $max === null || $d['terminoBase']->gt($max) ? $d['terminoBase'] : $max;
            }
            foreach ($subPacotesPorPai->get($pacoteId, collect()) as $sub) {
                [$subMin, $subMax] = $calcularIntervalo($sub->id);
                if ($subMin) {
                    $min = $min === null || $subMin->lt($min) ? $subMin : $min;
                    $max = $max === null || $subMax->gt($max) ? $subMax : $max;
                }
            }
            return $intervalos[$pacoteId] = [$min, $max];
        };

        $linhaAtividade = function (Atividade $at) use ($datasPorAtividade, $pacotesComAtividade) {
            $d = $datasPorAtividade[$at->id];
            $pacoteId = $at->pacote_trabalho_id;

            return [
                'id' => "atividade_{$at->id}",
                'text' => $at->nome,
                'type' => 'task',
                'parent' => ($pacoteId && isset($pacotesComAtividade[$pacoteId])) ? "pacote_{$pacoteId}" : 0,
                'start_date' => $d['inicioBase']->format('Y-m-d'),
                'duration' => max(1, $d['inicioBase']->diffInDays($d['terminoBase']) + 1),
                'termino_lb' => $d['terminoBase']->format('Y-m-d'),
                'inicio_tend' => $d['inicioTend']?->format('Y-m-d'),
                'termino_tend' => $d['terminoTend']?->format('Y-m-d'),
                'progress' => $at->percentual_concluido !== null ? $at->percentual_concluido / 100 : 0,
                'color' => $at->caminho_critico ? '#ff4d49' : '#696cff',
            ];
        };

        $ordenarGrupo = function ($grupo) {
            return $grupo->sort(fn ($a, $b) => $this->compararOrdemAtividade($a, $b))->values();
        };

        // Achata a EAP na mesma ordem do MS Project importado — mesmo
        // algoritmo de `⚡linhas-base.blade.php::arvoreAtividades()`
        // (convenção do projeto: um comparador por arquivo, não
        // compartilhado via trait): pacotes-irmãos por `codigo` natural,
        // atividades dentro do pacote por `ordem_manual`/`codigo_cronograma`,
        // e intercalação de nível raiz (pacotes raiz + atividades órfãs com
        // código, na sequência real do cronograma). Sem isso, a ordem virava
        // a de `baseline_inicio`, nada a ver com a EAP original.
        $linhas = [];

        $percorrer = function (string $pacoteId) use (&$percorrer, &$linhas, $pacotes, $subPacotesPorPai, $atividadesPorPacote, $calcularIntervalo, $ordenarGrupo, $linhaAtividade) {
            $pacote = $pacotes->get($pacoteId);
            [$min, $max] = $calcularIntervalo($pacoteId);
            if (! $min) {
                return;
            }

            $linhas[] = [
                'id' => "pacote_{$pacote->id}",
                'text' => trim(($pacote->codigo ? "{$pacote->codigo} · " : '') . $pacote->nome),
                'type' => 'project',
                'open' => true,
                'parent' => $pacote->parent_id ? "pacote_{$pacote->parent_id}" : 0,
                'start_date' => $min->format('Y-m-d'),
                'duration' => max(1, $min->diffInDays($max) + 1),
                'termino_lb' => $max->format('Y-m-d'),
            ];

            $filhos = $subPacotesPorPai->get($pacoteId, collect())
                ->sort(fn ($a, $b) => $this->compararCodigos($a->codigo, $b->codigo));
            foreach ($filhos as $filho) {
                $percorrer($filho->id);
            }

            foreach ($ordenarGrupo($atividadesPorPacote->get($pacoteId, collect())) as $at) {
                $linhas[] = $linhaAtividade($at);
            }
        };

        // Nível raiz: intercala pacotes raiz E atividades sem pacote que
        // tenham código do cronograma, numa única sequência ordenada — em
        // vez de jogar as órfãs sempre no final (mesmo padrão de
        // `arvoreAtividades()`).
        $raizes = $subPacotesPorPai->get(null, collect());

        $orfas = $atividadesPorPacote->get('sem_pacote', collect())
            ->filter(fn ($at) => $datasPorAtividade[$at->id]['inicioBase']);
        $orfasComCodigo = $orfas->filter(fn ($at) => $at->codigo_cronograma !== null);
        $orfasSemCodigo = $orfas->filter(fn ($at) => $at->codigo_cronograma === null);

        $entradasRaiz = collect();
        foreach ($raizes as $pacote) {
            $entradasRaiz->push(['codigo' => $pacote->codigo, 'tipo' => 'pacote', 'payload' => $pacote]);
        }
        foreach ($orfasComCodigo as $at) {
            $entradasRaiz->push(['codigo' => $at->codigo_cronograma, 'tipo' => 'atividade', 'payload' => $at]);
        }
        $entradasRaiz = $entradasRaiz->sort(fn ($a, $b) => $this->compararCodigos($a['codigo'], $b['codigo']));

        foreach ($entradasRaiz as $entrada) {
            if ($entrada['tipo'] === 'pacote') {
                $percorrer($entrada['payload']->id);
                continue;
            }
            $linhas[] = $linhaAtividade($entrada['payload']);
        }

        foreach ($ordenarGrupo($orfasSemCodigo) as $at) {
            $linhas[] = $linhaAtividade($at);
        }

        return ['data' => $linhas, 'links' => [], 'temTendencia' => $this->temImportacaoAvanco];
    }

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
            'limiteGantt' => self::LIMITE_GANTT,
        ];
    }

    // =========================================================================
    // HISTÓRICO DE IMPORTAÇÕES
    // =========================================================================

    /**
     * Histórico de Importações (Fase 3, Etapa 5) — usa o partial
     * compartilhado historico-importacoes.blade.php, mesma apresentação de
     * ⚡cronograma.blade.php/⚡relatorio-importar-avanco.blade.php. Aqui,
     * sem filtro por tipo (visão consolidada de todas as importações da
     * obra, como já era antes desta etapa).
     */
    #[Computed]
    public function importacoes()
    {
        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->with(['autor', 'healthCheck'])
            ->orderByDesc('importado_em')
            ->paginate(10);
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
                <div class="col-md-6">
                    <div class="small text-muted">Relatório semanal automático</div>
                    <div class="fw-semibold">
                        @php
                        $diasSemana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                        @endphp
                        {{ $obra->dia_semana_report !== null ? 'Toda ' . $diasSemana[$obra->dia_semana_report] : 'Desligado (geração manual)' }}
                    </div>
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
                <div class="col-md-6">
                    <label class="form-label">Gerar relatório automaticamente</label>
                    <select class="form-select @error('diaSemanaReportEdit') is-invalid @enderror" wire:model="diaSemanaReportEdit">
                        <option value="">Desligado (geração manual)</option>
                        <option value="0">Toda Domingo</option>
                        <option value="1">Toda Segunda-feira</option>
                        <option value="2">Toda Terça-feira</option>
                        <option value="3">Toda Quarta-feira</option>
                        <option value="4">Toda Quinta-feira</option>
                        <option value="5">Toda Sexta-feira</option>
                        <option value="6">Todo Sábado</option>
                    </select>
                    @error('diaSemanaReportEdit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Cria o rascunho automaticamente no dia escolhido, reaproveitando as curvas do último relatório — a emissão continua manual.</small>
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
                        <th>Perfis</th>
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
                        $perfisDoMembro = $membro->perfisNaObra($obra);
                    @endphp
                    <tr wire:key="membro-{{ $membro->id }}">
                        <td>{{ $membro->first_name }} {{ $membro->last_name }}</td>
                        <td class="text-muted small">{{ $membro->email }}</td>
                        <td>
                            @if ($perfisDoMembro->isEmpty())
                            <span class="badge bg-label-warning" title="Continua membro da equipe, mas sem acesso às funcionalidades protegidas">
                                <i class="bx bx-error-circle me-1"></i>Sem perfil atribuído
                            </span>
                            @else
                            @foreach ($perfisDoMembro as $p)
                            <span class="badge bg-label-secondary me-1 mb-1">{{ $p->nome }}</span>
                            @endforeach
                            @endif

                            @if ($criadorBloqueadoAqui)
                            <div class="small text-muted mt-1"><i class="bx bx-lock-alt me-1"></i>Só o administrador da plataforma pode alterar</div>
                            @elseif (\Illuminate\Support\Facades\Gate::allows('update', $obra))
                            <button type="button" class="btn btn-link btn-sm p-0 d-block mt-1" wire:click="abrirEdicaoPerfis('{{ $membro->id }}')">
                                <i class="bx bx-edit-alt me-1"></i>Editar perfis
                            </button>
                            @endif
                        </td>
                        @can('update', $obra)
                        <td class="text-center">
                            @unless ($criadorBloqueadoAqui)
                            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1"
                                    title="Remover da equipe (diferente de remover perfis)"
                                    onclick="confirmarAcao(this, {
                                        mensagem: 'Remover {{ $membro->first_name }} da EQUIPE desta obra? Isso é diferente de remover os perfis — o usuário deixará de ser membro por completo.',
                                        metodo: 'removerMembro',
                                        args: ['{{ $membro->id }}'],
                                        icone: 'bx-trash',
                                    })">
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
            <div class="col-md-8">
                <input type="email" class="form-control form-control-sm @error('emailConvite') is-invalid @enderror"
                       wire:model="emailConvite" placeholder="email@exemplo.com">
                @error('emailConvite')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <button class="btn btn-primary btn-sm w-100" wire:click="enviarConvite" wire:loading.attr="disabled">
                    <i class="bx bx-envelope me-1"></i>Convidar
                </button>
            </div>
        </div>

        {{-- FASE 2C, Seção 4 — "Perfis de acesso" com seleção múltipla:
             o convite pode conceder 1..N perfis de uma vez, mesmo idiom
             de checkboxes já usado no modal "Perfis na obra" (Seção
             19/24) — nunca um <select> single nem IDs técnicos. --}}
        <div class="mt-2">
            <label class="form-label small fw-semibold mb-1">Perfis de acesso</label>
            <div class="d-flex flex-wrap gap-3">
                @foreach ($this->perfisDisponiveis as $p)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="{{ $p->id }}"
                           id="perfil-convite-{{ $p->id }}"
                           wire:model="perfisConviteIds">
                    <label class="form-check-label" for="perfil-convite-{{ $p->id }}">
                        {{ $p->nome }}
                    </label>
                </div>
                @endforeach
            </div>
            @error('perfisConviteIds')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            @error('perfisConviteIds.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
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
                        <td>{{ $convite->nomesPerfis() }}</td>
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
                            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" title="Cancelar"
                                    onclick="confirmarAcao(this, {
                                        mensagem: 'Cancelar o convite para {{ $convite->email }}?',
                                        metodo: 'cancelarConvite',
                                        args: ['{{ $convite->id }}'],
                                        icone: 'bx-x-circle',
                                    })">
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

{{-- FASE 2C, Seção 19/24 — modal de edição multiperfil: clicar em
     "Editar perfis" abre este drawer, o admin marca/desmarca N perfis, e
     salva via substituirPerfis() (nunca edição inline complexa na
     própria linha da tabela). --}}
@if ($editandoPerfisMembro)
<div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Perfis na obra</h5>
                <button type="button" class="btn-close" wire:click="fecharEdicaoPerfis"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Marque quantos perfis fizerem sentido pra este usuário nesta obra — as capacidades
                    concedidas por QUALQUER um deles somam (nunca é preciso escolher só 1).
                </p>
                @foreach ($this->perfisDisponiveis as $p)
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="{{ $p->id }}"
                           id="perfil-edicao-{{ $p->id }}"
                           wire:model="perfisSelecionadosEdicao">
                    <label class="form-check-label" for="perfil-edicao-{{ $p->id }}">
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
                <button type="button" class="btn btn-outline-secondary" wire:click="fecharEdicaoPerfis">Cancelar</button>
                <button type="button" class="btn btn-primary" wire:click="salvarPerfisMembro" wire:loading.attr="disabled">
                    Salvar perfis
                </button>
            </div>
        </div>
    </div>
</div>
@endif
@endif

{{-- =========================================================================
     ABA: CRONOGRAMA
     ========================================================================= --}}
@if ($abaAtiva === 'cronograma')
<div class="card mb-4">
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

<div class="card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="mb-0">Gantt do Cronograma</h5>
            <div class="d-flex align-items-center gap-3 small text-muted flex-wrap">
                <span><span class="d-inline-block rounded-1 me-1" style="width:10px;height:10px;background:#696cff"></span>Linha de Base</span>
                <span><span class="d-inline-block rounded-1 me-1" style="width:10px;height:10px;background:#ff4d49"></span>Linha de Base — Caminho Crítico</span>
                <span><span class="d-inline-block rounded-1 me-1" style="width:10px;height:5px;background:#ffab00"></span>Tendência</span>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Linha de Base</label>
                <select class="form-select form-select-sm" wire:model.live="linhaBaseId">
                    <option value="">Linha de Base: ao vivo</option>
                    @foreach ($this->linhasBaseGantt as $linhaBaseOpcao)
                    <option value="{{ $linhaBaseOpcao->id }}">
                        {{ $linhaBaseOpcao->nome }} ({{ $linhaBaseOpcao->importacao?->importado_em?->format('d/m/y') }})
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Tendência (Avanço)</label>
                <select class="form-select form-select-sm" wire:model.live="tendenciaImportacaoId">
                    <option value="">Tendência: mais recente</option>
                    @foreach ($this->importacoesDisponiveis as $importacaoOpcao)
                    <option value="{{ $importacaoOpcao->id }}">
                        {{ $importacaoOpcao->importado_em->format('d/m/y H:i') }} — {{ $importacaoOpcao->arquivo }}
                    </option>
                    @endforeach
                </select>
            </div>
        </div>

        @if (empty($this->dadosGantt['data']))
        <div class="text-center text-muted py-5">
            <i class="bx bx-bar-chart-alt-2 fs-1 d-block mb-2"></i>
            Nenhuma atividade com Linha de Base definida ({{ $this->linhaBaseSelecionadaGantt?->nome ?? 'ao vivo' }})
            nesta obra ainda.
        </div>
        @else
        @if ($this->ganttAtividades->count() >= $this->resumoCronograma['limiteGantt'])
        <p class="text-muted small">
            Mostrando as {{ $this->resumoCronograma['limiteGantt'] }} atividades mais antigas (por
            início da linha de base). Para a árvore EAP completa, veja
            <a href="{{ route('radar.entrar', $obra) }}">Linhas de Base no Radar</a>.
        </p>
        @endif
        <style>
            #gantt-cronograma-{{ $obra->id }} { font-size: 12px; }
            #gantt-cronograma-{{ $obra->id }} .gantt_grid_head_cell,
            #gantt-cronograma-{{ $obra->id }} .gantt_cell,
            #gantt-cronograma-{{ $obra->id }} .gantt_tree_content,
            #gantt-cronograma-{{ $obra->id }} .gantt_scale_cell { font-size: 12px; }
            #gantt-cronograma-{{ $obra->id }} .gantt-tendencia-bar {
                position: absolute;
                height: 6px;
                border-radius: 3px;
                background: #ffab00;
                opacity: 0.9;
                pointer-events: none;
            }
        </style>
        <div wire:ignore id="gantt-cronograma-{{ $obra->id }}" style="width:100%; height:600px"></div>
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
        @include('pages.radar._partials.historico-importacoes', ['importacoes' => $this->importacoes])
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

    // dd/mm/aa pros 4 campos de data — "start_date" já vira Date nativo
    // depois do gantt.parse() (governado por date_format), mas os campos
    // extras (termino_lb/inicio_tend/termino_tend) são strings 'Y-m-d'
    // cruas vindas do PHP, nunca convertidas pelo dhtmlx — por isso o
    // helper aceita os dois formatos.
    function formatarDataBR(valor) {
        if (! valor) return '—';
        if (typeof valor === 'string') {
            const [ano, mes, dia] = valor.split('-');
            return `${dia}/${mes}/${ano.slice(2)}`;
        }
        const dia = String(valor.getDate()).padStart(2, '0');
        const mes = String(valor.getMonth() + 1).padStart(2, '0');
        const ano = String(valor.getFullYear()).slice(2);
        return `${dia}/${mes}/${ano}`;
    }

    const mesesPt = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    // As colunas/tooltip são configuradas uma única vez (objetos de função
    // do dhtmlx, não recriados a cada evento), mas o filtro de Tendência
    // pode mudar a qualquer momento — por isso `temTendenciaAtual` é uma
    // variável mutável fora do listener, sempre atualizada no evento, e é
    // ela (não `ganttData` do primeiro disparo) que os templates capturam
    // por closure.
    let temTendenciaAtual = false;
    function celulaTendencia(valor) {
        return valor ? formatarDataBR(valor) : (temTendenciaAtual ? '—' : 'N/A');
    }

    // Cada troca pra aba Cronograma (ou troca de filtro de Linha de Base/
    // Tendência com a aba já aberta — ver updatedLinhaBaseId()/
    // updatedTendenciaImportacaoId()) manda os dados via evento (não fica
    // dentro de uma condicional Blade) — o <div> some/reaparece no DOM a
    // cada troca de aba, então sempre re-inicializa o dhtmlxGantt (instância
    // global única `gantt`, sem "destroy" — reinit + clearAll é o padrão da
    // própria lib pra remontar num container que pode ter mudado).
    let ganttConfigurado = false;
    $wire.on('cronograma-tab-ativada', ({ ganttData }) => {
        const el = document.getElementById('gantt-cronograma-{{ $obra->id }}');
        if (! el) return;

        temTendenciaAtual = ganttData.temTendencia;

        if (! ganttConfigurado) {
            gantt.config.readonly = true;
            gantt.config.date_format = '%Y-%m-%d';
            gantt.config.scale_height = 36;
            gantt.config.row_height = 34;
            gantt.config.task_height = 16;
            gantt.config.scales = [
                { unit: 'year', step: 1, format: '%Y' },
                { unit: 'month', step: 1, template: (date) => mesesPt[date.getMonth()] },
            ];
            gantt.config.columns = [
                { name: 'text', label: 'Tarefa', tree: true, width: 220, resize: true },
                { name: 'start_date', label: 'Início LB', align: 'center', width: 75, template: (task) => formatarDataBR(task.start_date) },
                { name: 'termino_lb', label: 'Término LB', align: 'center', width: 75, template: (task) => formatarDataBR(task.termino_lb) },
                {
                    name: 'inicio_tend', label: 'Início Tend.', align: 'center', width: 75,
                    template: (task) => task.type === 'project' ? '' : celulaTendencia(task.inicio_tend),
                },
                {
                    name: 'termino_tend', label: 'Término Tend.', align: 'center', width: 80,
                    template: (task) => task.type === 'project' ? '' : celulaTendencia(task.termino_tend),
                },
            ];
            gantt.templates.tooltip_text = (start, end, task) => {
                let html = `<strong>${task.text}</strong><br>Linha de Base: ${formatarDataBR(task.start_date)} — ${formatarDataBR(task.termino_lb)}`;
                if (task.type !== 'project') {
                    html += `<br>Tendência: ${celulaTendencia(task.inicio_tend)} — ${celulaTendencia(task.termino_tend)}`;
                }
                return html;
            };

            // Barra de Tendência sobreposta abaixo da barra principal (que
            // representa a Linha de Base) — duas tentativas anteriores
            // falharam por serem recurso Pro mesmo na edição MIT/Community:
            // `gantt.addTaskLayer()` é literalmente apagado do objeto
            // `gantt` dentro de `init()` (achado inspecionando o
            // `dhtmlxgantt.js` vendorizado: `function bo(e){delete
            // e.addTaskLayer,delete e.addLinkLayer}` chamada logo após o
            // mixin que o registra); "split task" (`render:'split'` + filho
            // com `parent` apontando pra própria atividade) É reconhecido
            // internamente (`gantt.isSplitTask()`/`$split_subtask` batem),
            // mas nenhum elemento chega a ser desenhado no DOM — mesmo com
            // `open_split_tasks:false`. Funciona sim (e sem exigir mudança
            // nenhuma no formato dos dados do PHP) via `onGanttRender`
            // (evento público, dispara a cada render) + `getTaskPosition()`
            // pra calcular a geometria e injetar um `<div>` absoluto direto
            // em `gantt.$task_data` (mesmo container que a própria lib usa
            // como destino padrão do addTaskLayer, achado lendo o código-
            // fonte) — nenhuma API removida/gateada envolvida.
            gantt.attachEvent('onGanttRender', function () {
                const container = gantt.$task_data;
                if (! container) return;
                container.querySelectorAll('.gantt-tendencia-bar').forEach((el) => el.remove());
                gantt.eachTask(function (task) {
                    if (task.type === 'project' || ! task.inicio_tend || ! task.termino_tend) return;
                    const inicio = gantt.date.parseDate(task.inicio_tend, '%Y-%m-%d');
                    const fim = gantt.date.add(gantt.date.parseDate(task.termino_tend, '%Y-%m-%d'), 1, 'day');
                    const posBase = gantt.getTaskPosition(task);
                    const posTend = gantt.getTaskPosition(task, inicio, fim);
                    const el = document.createElement('div');
                    el.className = 'gantt-tendencia-bar';
                    el.style.left = posTend.left + 'px';
                    el.style.width = Math.max(posTend.width, 2) + 'px';
                    el.style.top = (posBase.top + posBase.height + 1) + 'px';
                    container.appendChild(el);
                });
            });

            ganttConfigurado = true;
        }

        gantt.init(el);
        gantt.clearAll();
        if (ganttData.data.length) {
            gantt.parse(ganttData);
        }
    });
</script>
@endscript
