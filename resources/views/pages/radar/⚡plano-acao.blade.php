<?php

use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Models\Atividade;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\User;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Plano de Ação do Health Check (Fase 4.3, Etapa B) — listagem paginada,
 * cross-import (Modelo B, Fase 4), das ações abertas/resolvidas/canceladas
 * da obra. Consome exatamente a query/relação já validadas na Etapa A
 * (`PlanoAcao::ultimaReconciliacao()`, `severidadeDaRegra()`) — nenhuma
 * regra de negócio nova aqui, só apresentação.
 *
 * Painel expansível, edição, histórico interativo, comentários,
 * notificações e Score projetado ficam para as Etapas C/D/E seguintes
 * (decisão do usuário) — esta etapa é só listagem/filtros/ordenação/
 * paginação.
 */
new class extends Component {
    use WithPagination, ExecutaComTransacaoSegura;

    protected $paginationTheme = 'bootstrap';

    public Work $obra;

    // ---- Filtros ----
    public string $filtroStatus = '';
    public ?string $filtroResponsavelId = null;
    /** Fase 4.3, Etapa E: persistido via ?regra= — permite o link do Mapa de
     * Ações (⚡importacao-detalhe.blade.php) chegar aqui já filtrado. Só o
     * atributo é novo — queryFiltrada()/aplicarOrdenacao() continuam
     * intocados, a propriedade já era usada exatamente assim. */
    #[Url(as: 'regra')]
    public ?string $filtroRegraId = null;
    public string $filtroPrazoDe = '';
    public string $filtroPrazoAte = '';
    public string $filtroSituacao = '';

    // ---- Paginação ----
    public int $perPage = 10;

    // ---- Modal de edição (Fase 4.3, Etapa D) ----
    public ?string $acaoEditandoId = null;

    /** Status capturado só na abertura do modal — usado apenas pra decidir se
     * a UI exige confirmarAcao() (mudou o status?) ou não. NUNCA é a fonte de
     * verdade da validação de transição — isso sempre acontece contra o
     * status fresco, buscado de novo dentro de confirmarEditar(). */
    public ?string $acaoEditandoStatusOriginal = null;

    public ?string $editResponsavelId = null;

    public ?string $editPrazo = null;

    public ?string $editStatus = null;

    /** Mensagem amigável (validação/transição/responsável) exibida dentro do modal — nunca uma exceção crua. */
    public ?string $editErro = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        // "Página deve respeitar a permissão" (Fase 4.3, Seção 21) — mesma
        // checagem de PlanoAcaoPolicy::view(), sem instância de PlanoAcao
        // (ainda não existe uma no contexto de listagem).
        abort_unless(
            Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.plano_acao', 'ver'),
            403
        );
    }

    // =========================================================================
    // FILTROS
    // =========================================================================

    public function updatedFiltroStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroResponsavelId(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroRegraId(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroPrazoDe(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroPrazoAte(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroSituacao(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function temFiltrosAtivos(): bool
    {
        return (bool) ($this->filtroStatus
            || $this->filtroResponsavelId
            || $this->filtroRegraId
            || $this->filtroPrazoDe
            || $this->filtroPrazoAte
            || $this->filtroSituacao);
    }

    public function limparFiltros(): void
    {
        $this->filtroStatus = '';
        $this->filtroResponsavelId = null;
        $this->filtroRegraId = null;
        $this->filtroPrazoDe = '';
        $this->filtroPrazoAte = '';
        $this->filtroSituacao = '';
        $this->resetPage();
    }

    // =========================================================================
    // QUERY / ORDENAÇÃO — mesma estratégia validada na Etapa A
    // (tests/Feature/PlanoAcaoQueryTest.php), nenhuma alteração de fórmula.
    // =========================================================================

    private function queryFiltrada(): Builder
    {
        return PlanoAcao::where('obra_id', $this->obra->id)
            ->when($this->filtroStatus, fn ($q) => $q->where('status', $this->filtroStatus))
            ->when($this->filtroResponsavelId, fn ($q) => $q->where('responsavel_id', $this->filtroResponsavelId))
            ->when($this->filtroRegraId, fn ($q) => $q->where('regra_id', $this->filtroRegraId))
            ->when($this->filtroPrazoDe, fn ($q) => $q->whereDate('prazo', '>=', $this->filtroPrazoDe))
            ->when($this->filtroPrazoAte, fn ($q) => $q->whereDate('prazo', '<=', $this->filtroPrazoAte))
            ->when(
                $this->filtroSituacao === 'sem_reconciliacao',
                fn ($q) => $q->whereDoesntHave('ultimaReconciliacao')
            )
            ->when(
                $this->filtroSituacao && $this->filtroSituacao !== 'sem_reconciliacao',
                fn ($q) => $q->whereHas(
                    'ultimaReconciliacao',
                    fn ($r) => $r->where('resultado', $this->filtroSituacao)
                )
            );
    }

    /**
     * Aberta antes de Resolvida/Cancelada; dentro de Aberta, prazo mais
     * vencido primeiro, depois mais próximo, nulos por último (mesmo
     * idioma `CASE WHEN` já usado em ⚡restricoes.blade.php); desempate por
     * situação da última reconciliação (Alterado > Agravado > Persistente
     * > sem reconciliação) via subquery correlacionada — nunca usa impacto,
     * nunca inventa fórmula de prioridade (Seção 12/11 da Fase 4.3).
     */
    private function aplicarOrdenacao(Builder $q): void
    {
        $q->orderByRaw("CASE WHEN status = 'aberta' THEN 0 ELSE 1 END ASC")
            ->orderByRaw('CASE WHEN prazo IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('prazo', 'asc')
            ->orderByRaw("COALESCE((
                SELECT CASE resultado
                    WHEN 'alterado' THEN 0
                    WHEN 'agravado' THEN 1
                    WHEN 'persistente' THEN 2
                    WHEN 'resolvido' THEN 3
                    ELSE 4
                END
                FROM plano_acao_reconciliacoes r
                WHERE r.plano_acao_id = planos_acao.id
                ORDER BY r.created_at DESC
                LIMIT 1
            ), 4) ASC");
    }

    #[Computed]
    public function acoes()
    {
        // Etapa C (painel expansível): eager-load adicional, mínimo — o
        // histórico completo (`reconciliacoes.importacao`, mais recente
        // primeiro) é usado pelo painel de detalhe. Isso é 1 query extra
        // (+1 para as importações relacionadas) para a página INTEIRA, não
        // por linha — nunca escala com o total de ações da obra, só com
        // `perPage` (já limitado a no máximo 50). `ultimaReconciliacao`
        // (Etapa B, usada em filtro/ordenação/badge da listagem) não foi
        // alterada nem removida.
        $q = $this->queryFiltrada()->with([
            'ultimaReconciliacao.importacao',
            'responsavel:id,first_name,last_name',
            'autor:id,first_name,last_name',
            'importacaoOrigem.healthCheck',
            'reconciliacoes' => fn ($r) => $r->orderByDesc('created_at'),
            'reconciliacoes.importacao',
        ]);
        $this->aplicarOrdenacao($q);

        return $q->paginate($this->perPage);
    }

    #[Computed]
    public function responsaveis()
    {
        return Cache::remember(
            "obra_{$this->obra->id}_usuarios",
            90,
            fn () => $this->obra->users()->orderBy('users.first_name')->get(['users.id', 'users.first_name', 'users.last_name'])
        );
    }

    #[Computed]
    public function regrasDisponiveis()
    {
        return PlanoAcao::where('obra_id', $this->obra->id)
            ->distinct()
            ->orderBy('regra_id')
            ->pluck('regra_id');
    }

    /**
     * Ciclo 11 (Etapa B) — "Transformar em Restrição": 1 query pra TODA a
     * página (nunca por linha), mesmo padrão já usado em
     * `acoesAbertasDaObra()` (Fase 4.3, achado de N+1 no Mapa de Ações).
     * Usa `uids_referencia` (já em memória em cada `$this->acoes`, sem
     * query extra) em vez de chamar `atividadesRelacionadas()` de novo —
     * essa chamada já acontece, por linha, dentro do próprio partial (não
     * duplicada aqui). Chave = `external_uid`, não `id`, exatamente pra
     * evitar precisar resolver id→atividade uma segunda vez.
     */
    #[Computed]
    public function foraDoCronogramaPorUid()
    {
        $uids = $this->acoes->flatMap(fn (PlanoAcao $acao) => $acao->uids_referencia ?? [])->unique()->values();

        return Atividade::where('obra_id', $this->obra->id)
            ->whereIn('external_uid', $uids)
            ->pluck('fora_do_cronograma', 'external_uid');
    }

    /**
     * Ciclo 11 (Etapa B): idem — 1 query pra TODA a página, agrupada por
     * `origem_plano_acao_id` pro partial só filtrar em memória.
     */
    #[Computed]
    public function restricoesVinculadasPorAcao()
    {
        return Restricao::whereIn('origem_plano_acao_id', $this->acoes->pluck('id'))
            ->get(['origem_plano_acao_id', 'atividade_id', 'status'])
            ->groupBy('origem_plano_acao_id');
    }

    // =========================================================================
    // EDIÇÃO MANUAL (Fase 4.3, Etapa D) — responsável/prazo/status. Nunca
    // toca uids_referencia/titulo/recomendacao/regra_id/
    // cronograma_importacao_origem_id/tenant_id/obra_id/created_by_id.
    // =========================================================================

    /**
     * Reidrata a ação sendo editada A CADA render (nunca reaproveita a
     * instância paginada em `$this->acoes`) — é o que garante que as opções
     * de status do `<select>` no modal reflitam o status FRESCO do banco,
     * mesmo se uma importação reconciliar a ação enquanto o modal está
     * aberto (Seção 12 do plano aprovado).
     */
    #[Computed]
    public function acaoEmEdicao(): ?PlanoAcao
    {
        return $this->acaoEditandoId ? PlanoAcao::find($this->acaoEditandoId) : null;
    }

    /**
     * Só verificação de UX (evita abrir um modal que o usuário não vai
     * conseguir salvar) — NUNCA substitui o authorize() real, que só
     * acontece em confirmarEditar() (Seção 4/16 do plano aprovado).
     */
    public function abrirModalEditar(string $id): void
    {
        $acao = PlanoAcao::findOrFail($id);

        if (! Auth::user()->can('update', $acao)) {
            return;
        }

        $this->acaoEditandoId = $acao->id;
        $this->acaoEditandoStatusOriginal = $acao->status->value;
        $this->editResponsavelId = $acao->responsavel_id;
        $this->editPrazo = $acao->prazo?->format('Y-m-d');
        $this->editStatus = $acao->status->value;
        $this->editErro = null;
    }

    public function fecharModalEditar(): void
    {
        $this->acaoEditandoId = null;
        $this->acaoEditandoStatusOriginal = null;
    }

    /**
     * Persiste a edição — única fonte real de proteção (authorize()), única
     * fonte real de validação de transição (contra o status FRESCO, buscado
     * de novo aqui, nunca o valor capturado quando o modal abriu) e única
     * fonte real de validação de responsável (nunca confia no `<select>`
     * já estar limitado a `$this->responsaveis`).
     *
     * `update()` restrito a EXATAMENTE 4 campos (responsavel_id/prazo/
     * status/resolvida_em) — nunca um fill() genérico, nunca toca
     * uids_referencia (identidade de reconciliação, responsabilidade
     * exclusiva do PlanoAcaoReconciliador).
     */
    public function confirmarEditar(): void
    {
        $acao = PlanoAcao::find($this->acaoEditandoId);

        if ($acao === null) {
            $this->editErro = 'Não foi possível localizar esta ação — recarregue a página e tente novamente.';

            return;
        }

        $this->authorize('update', $acao);

        $this->validate([
            'editResponsavelId' => 'nullable|exists:users,id',
            'editPrazo' => 'nullable|date',
            'editStatus' => 'required|in:'.implode(',', array_map(fn ($s) => $s->value, StatusPlanoAcao::cases())),
        ]);

        $statusAtual = $acao->status; // fresco — acabou de ser lido do banco acima, nunca o valor do estado do componente
        $novoStatus = StatusPlanoAcao::from($this->editStatus);

        if (! in_array($novoStatus, $statusAtual->transicoesPermitidas(), true)) {
            $this->editErro = "Esta ação já foi atualizada (status atual: {$statusAtual->label()}). Feche e reabra o painel para ver o estado mais recente.";

            return;
        }

        $responsavel = $this->editResponsavelId ? User::find($this->editResponsavelId) : null;

        if ($responsavel !== null && ! $responsavel->temAcessoAObra($acao->obra_id)) {
            $this->editErro = 'O responsável selecionado não tem acesso a esta obra.';

            return;
        }

        // Aberta→Resolvida grava resolvida_em; qualquer transição pra Aberta
        // ou Cancelada limpa/mantém null; "manter" (sem mudar de status)
        // preserva o valor já existente (nunca reseta à toa).
        $resolvidaEm = match (true) {
            $novoStatus === $statusAtual => $acao->resolvida_em,
            $novoStatus === StatusPlanoAcao::Resolvida => now(),
            default => null,
        };

        $this->transacaoSegura(fn () => $acao->update([
            'responsavel_id' => $responsavel?->id,
            'prazo' => $this->editPrazo ?: null,
            'status' => $novoStatus,
            'resolvida_em' => $resolvidaEm,
        ]));

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->acaoEditandoId = null;
        $this->acaoEditandoStatusOriginal = null;
        $this->dispatch('show-toast', message: 'Ação atualizada com sucesso.');
        unset($this->acoes);
    }

    // =========================================================================
    // TRANSFORMAR EM RESTRIÇÃO (Ciclo 11, Etapa B) — transformação explícita
    // e manual, sem qualquer sincronização de ciclo de vida depois de criada
    // (ver App\Models\PlanoAcao::transformarEmRestricoes()).
    // =========================================================================

    /**
     * `$atividadeIds` vem do Alpine (checkboxes marcados no painel) — é só a
     * INTENÇÃO do usuário, nunca fonte confiável: toda a revalidação real
     * (obra/tenant/uids_referencia/fora_do_cronograma/duplicidade) acontece
     * dentro de `PlanoAcao::transformarEmRestricoes()`.
     *
     * As duas autorizações são checadas ANTES de qualquer criação — se
     * qualquer uma falhar, `authorize()` lança e a exceção interrompe o
     * método imediatamente, nenhuma Restrição chega a ser criada.
     *
     * @param  string[]  $atividadeIds
     */
    public function transformarEmRestricoes(string $acaoId, array $atividadeIds): void
    {
        $acao = PlanoAcao::find($acaoId);

        if ($acao === null) {
            $this->dispatch('show-toast', message: 'Não foi possível localizar esta ação — recarregue a página e tente novamente.');

            return;
        }

        $this->authorize('update', $acao);
        $this->authorize('create', [Restricao::class, $acao->obra_id]);

        if (empty($atividadeIds)) {
            $this->dispatch('show-toast', message: 'Selecione ao menos uma atividade para transformar em Restrição.');

            return;
        }

        $resultado = null;

        $this->transacaoSegura(function () use ($acao, $atividadeIds, &$resultado) {
            $resultado = $acao->transformarEmRestricoes($atividadeIds);
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->dispatch('show-toast', message: $this->mensagemTransformacaoEmRestricoes($resultado));
        unset($this->acoes);
    }

    /**
     * @param  array{criadas:int, ignoradasForaDoCronograma:int, ignoradasJaVinculadas:int, ignoradasInvalidas:int}  $r
     */
    private function mensagemTransformacaoEmRestricoes(array $r): string
    {
        $partes = [$r['criadas'] === 1 ? '1 restrição criada.' : "{$r['criadas']} restrições criadas."];

        $ignoradas = [];

        if ($r['ignoradasForaDoCronograma'] > 0) {
            $ignoradas[] = $r['ignoradasForaDoCronograma'] === 1
                ? '1 atividade fora do cronograma'
                : "{$r['ignoradasForaDoCronograma']} atividades fora do cronograma";
        }

        if ($r['ignoradasJaVinculadas'] > 0) {
            $ignoradas[] = $r['ignoradasJaVinculadas'] === 1
                ? '1 já vinculada'
                : "{$r['ignoradasJaVinculadas']} já vinculadas";
        }

        if ($r['ignoradasInvalidas'] > 0) {
            $ignoradas[] = $r['ignoradasInvalidas'] === 1
                ? '1 atividade inválida'
                : "{$r['ignoradasInvalidas']} atividades inválidas";
        }

        if (! empty($ignoradas)) {
            $partes[] = implode(' e ', $ignoradas).' foram ignoradas.';
        }

        return implode(' ', $partes);
    }
}; ?>

<div>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bx bx-task me-1"></i>Plano de Ação</h5>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Status</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroStatus">
                        <option value="">Todos</option>
                        @foreach (StatusPlanoAcao::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Responsável</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroResponsavelId">
                        <option value="">Todos</option>
                        @foreach ($this->responsaveis as $u)
                        <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Regra</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroRegraId">
                        <option value="">Todas</option>
                        @foreach ($this->regrasDisponiveis as $regra)
                        <option value="{{ $regra }}">{{ $regra }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Situação da Última Análise</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroSituacao">
                        <option value="">Todas</option>
                        <option value="sem_reconciliacao">Sem reconciliação</option>
                        @foreach (ResultadoReconciliacaoPlanoAcao::cases() as $resultado)
                        <option value="{{ $resultado->value }}">{{ $resultado->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Prazo de</label>
                    <input type="date" class="form-control form-control-sm" wire:model.live="filtroPrazoDe">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label mb-1">Prazo até</label>
                    <input type="date" class="form-control form-control-sm" wire:model.live="filtroPrazoAte">
                </div>
            </div>
            @if ($this->temFiltrosAtivos())
            <div class="mt-2">
                <button class="btn btn-sm btn-outline-secondary" wire:click="limparFiltros">
                    <i class="bx bx-x me-1"></i>Limpar filtros
                </button>
            </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if ($this->acoes->isNotEmpty())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:2.5rem"></th>
                            <th>Problema</th>
                            <th>Regra</th>
                            <th>Responsável</th>
                            <th>Prazo</th>
                            <th>Status da Ação</th>
                            <th>Situação da Última Análise</th>
                            <th>Última Importação Analisada</th>
                        </tr>
                    </thead>
                    @foreach ($this->acoes as $acao)
                    @php
                        $severidade = $acao->severidadeDaRegra();
                        $ultimaReconciliacao = $acao->ultimaReconciliacao;
                        $importacaoParaExibir = $ultimaReconciliacao?->importacao ?? $acao->importacaoOrigem;
                        $corStatus = match ($acao->status) {
                            StatusPlanoAcao::Aberta => 'primary',
                            StatusPlanoAcao::Resolvida => 'success',
                            StatusPlanoAcao::Cancelada => 'secondary',
                        };
                        $corSituacao = $ultimaReconciliacao ? match ($ultimaReconciliacao->resultado) {
                            ResultadoReconciliacaoPlanoAcao::Persistente => 'secondary',
                            ResultadoReconciliacaoPlanoAcao::Agravado => 'danger',
                            ResultadoReconciliacaoPlanoAcao::Alterado => 'warning',
                            ResultadoReconciliacaoPlanoAcao::Resolvido => 'success',
                        } : null;
                    @endphp
                    {{-- Múltiplos <tbody> por <table> são válidos em HTML5 — usado aqui
                         deliberadamente pra dar a cada ação seu próprio escopo Alpine
                         (x-data por par de linhas resumo+painel), já que <tr> irmãos
                         dentro do MESMO <tbody> não compartilhariam esse escopo. --}}
                    <tbody x-data="{ aberto: false, selecionadas: [] }" wire:key="plano-acao-tbody-{{ $acao->id }}">
                        <tr wire:key="plano-acao-{{ $acao->id }}" style="cursor:pointer" @click="aberto = !aberto">
                            <td>
                                <i class="bx" :class="aberto ? 'bx-chevron-up' : 'bx-chevron-down'"></i>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $acao->titulo }}</div>
                                @if ($severidade)
                                <span class="badge bg-label-{{ $severidade->cor() }}">
                                    {{ $severidade->emoji() }} {{ $severidade->label() }}
                                </span>
                                @endif
                            </td>
                            <td><code>{{ $acao->regra_id }}</code></td>
                            <td>
                                @if ($acao->responsavel)
                                {{ $acao->responsavel->first_name }} {{ $acao->responsavel->last_name }}
                                @else
                                <span class="text-muted">Não atribuído</span>
                                @endif
                            </td>
                            <td>
                                @if ($acao->prazo)
                                {{ $acao->prazo->format('d/m/Y') }}
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-label-{{ $corStatus }}">{{ $acao->status->label() }}</span>
                            </td>
                            <td>
                                @if (! $ultimaReconciliacao)
                                <span class="badge bg-label-light text-muted">Nunca reconciliada</span>
                                @else
                                <span class="badge bg-label-{{ $corSituacao }}" title="Resultado da última importação analisada — não confundir com o Status da Ação">
                                    {{ $ultimaReconciliacao->resultado->label() }}
                                </span>
                                @endif
                            </td>
                            <td>
                                @if ($importacaoParaExibir)
                                <a href="{{ route('radar.importacoes.show', $importacaoParaExibir) }}" @click.stop>
                                    {{ $importacaoParaExibir->importado_em->format('d/m/Y H:i') }}
                                </a>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        <tr x-show="aberto" x-transition x-cloak>
                            <td></td>
                            <td colspan="7">
                                @include('pages.radar._partials.plano-acao-detalhe', [
                                    'acao' => $acao,
                                    'foraDoCronogramaPorUid' => $this->foraDoCronogramaPorUid,
                                    'restricoesVinculadas' => $this->restricoesVinculadasPorAcao->get($acao->id, collect()),
                                ])
                            </td>
                        </tr>
                    </tbody>
                    @endforeach
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3">
                <div class="d-flex align-items-center gap-2">
                    <small class="text-muted text-nowrap">{{ $this->acoes->total() }} ações encontradas</small>
                    <select class="form-select form-select-sm" style="width:auto" wire:model.live="perPage">
                        @foreach ([5, 10, 20, 50] as $n)
                        <option value="{{ $n }}">{{ $n }} por página</option>
                        @endforeach
                    </select>
                </div>
                {{ $this->acoes->links() }}
            </div>
            @else
            <div class="text-center py-5">
                <i class="bx bx-task display-3 text-muted"></i>
                <h5 class="fw-bold mt-3">Nenhuma ação encontrada</h5>
                <p class="text-muted">
                    @if ($this->temFiltrosAtivos())
                    <button class="btn btn-sm btn-outline-secondary mt-1" wire:click="limparFiltros">
                        Limpar filtros
                    </button>
                    @else
                    Esta obra ainda não possui ações do Plano de Ação registradas.
                    @endif
                </p>
            </div>
            @endif
        </div>
    </div>

    {{-- =====================================================================
         MODAL: EDITAR AÇÃO (Fase 4.3, Etapa D) — mesmo padrão do modal
         "Criar Ação" de ⚡importacao-detalhe.blade.php (Fase 4.2): modal
         condicional em Blade, sem depender de JS do Bootstrap pra abrir.
         ===================================================================== --}}
    @if ($acaoEditandoId !== null)
    @php $acaoEmEdicao = $this->acaoEmEdicao; @endphp
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-edit me-2"></i>Editar Ação</h5>
                    <button type="button" class="btn-close" wire:click="fecharModalEditar"></button>
                </div>
                @if (! $acaoEmEdicao)
                <div class="modal-body">
                    <div class="alert alert-warning mb-0">
                        Não foi possível localizar esta ação — recarregue a página e tente novamente.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalEditar">Fechar</button>
                </div>
                @else
                <div class="modal-body">
                    @if ($editErro)
                    <div class="alert alert-warning">{{ $editErro }}</div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select class="form-select" wire:model="editResponsavelId">
                            <option value="">— Sem responsável —</option>
                            @foreach ($this->responsaveis as $u)
                            <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                            @endforeach
                        </select>
                        @error('editResponsavelId')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prazo</label>
                        <input type="date" class="form-control" wire:model="editPrazo">
                        @error('editPrazo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" wire:model.live="editStatus">
                            @foreach ($acaoEmEdicao->status->transicoesPermitidas() as $opcaoStatus)
                            <option value="{{ $opcaoStatus->value }}">
                                {{ $opcaoStatus->label() }}{{ $opcaoStatus === $acaoEmEdicao->status ? ' (manter)' : '' }}
                            </option>
                            @endforeach
                        </select>
                        @error('editStatus')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalEditar">Cancelar</button>
                    @php
                        $mudouStatus = $editStatus !== $acaoEditandoStatusOriginal;
                        $mensagemConfirmacaoStatus = match ($editStatus) {
                            StatusPlanoAcao::Resolvida->value => 'Deseja realmente resolver esta ação? Ela deixará de ser reconciliada automaticamente enquanto estiver resolvida.',
                            StatusPlanoAcao::Cancelada->value => 'Deseja realmente cancelar esta ação? Ela deixará de ser reconciliada automaticamente enquanto estiver cancelada.',
                            StatusPlanoAcao::Aberta->value => 'Deseja reabrir esta ação? Ela voltará a ser reconciliada automaticamente nas próximas importações.',
                            default => null,
                        };
                    @endphp
                    @if ($mudouStatus && $mensagemConfirmacaoStatus)
                    <button type="button" class="btn btn-primary" wire:loading.attr="disabled"
                            onclick="confirmarAcao(this, {
                                mensagem: '{{ $mensagemConfirmacaoStatus }}',
                                metodo: 'confirmarEditar',
                                args: [],
                                corBotao: 'primary',
                                icone: 'bx-edit',
                            })">
                        Salvar
                    </button>
                    @else
                    <button type="button" class="btn btn-primary" wire:click="confirmarEditar" wire:loading.attr="disabled">
                        <span wire:loading wire:target="confirmarEditar">
                            <span class="spinner-border spinner-border-sm me-1"></span>
                        </span>
                        Salvar
                    </button>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
