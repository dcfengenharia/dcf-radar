<?php

use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Exceptions\RequisicaoPlanejamentoEmissaoInvalidaException;
use App\Exceptions\RequisicaoPlanejamentoImutavelException;
use App\Exceptions\SaldoTakeOffInsuficienteException;
use App\Models\Disciplina;
use App\Models\FamiliaMaterial;
use App\Models\RequisicaoPlanejamento;
use App\Models\RequisicaoPlanejamentoItem;
use App\Models\UnidadeMedida;
use App\Models\Work;
use App\Support\Suprimentos\ConciliacaoAlocacao;
use App\Support\Suprimentos\ConciliacaoTakeOff;
use App\Support\TakeOff\TakeOffConsolidado;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ciclo 19, Etapa 19.2 — área própria do Planejamento (nunca dentro da
 * tela de Engenharia): listar/criar/editar rascunho/emitir Requisição do
 * Planejamento (RP). Zero regra de negócio nova aqui — tudo delega pras
 * Actions (App\Actions\Suprimentos\*) e pro serviço de conciliação
 * (App\Support\Suprimentos\ConciliacaoTakeOff), já testados isoladamente.
 */
new class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public Work $obra;

    public string $filtroStatus = '';

    public ?string $rpAbertaId = null;

    // ---- filtros do seletor de itens do Take Off (dentro do rascunho aberto) ----
    public string $itemBusca = '';
    public ?string $itemListaId = null;
    public ?string $itemDisciplinaId = null;
    public ?string $itemFamiliaId = null;
    public ?string $itemUnidadeId = null;
    public string $itemSituacao = '';

    public ?string $novoItemQuantidadeDe = null;
    public ?string $novoItemQuantidadeValor = null;

    public ?string $erro = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        abort_unless(
            Auth::user()->temPermissaoNaObra($this->obra->id, 'planejamento.requisicoes', 'ver'),
            403
        );
    }

    // =========================================================================
    // LISTAGEM
    // =========================================================================

    public function updatedFiltroStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function requisicoes()
    {
        return RequisicaoPlanejamento::query()
            ->where('obra_id', $this->obra->id)
            ->when($this->filtroStatus, fn ($q) => $q->where('status', $this->filtroStatus))
            ->withCount('itens')
            ->with('autor', 'emitidaPor')
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    #[Computed]
    public function podeEditar(): bool
    {
        return Auth::user()->temPermissaoNaObra($this->obra->id, 'planejamento.requisicoes', 'editar');
    }

    public function criarRascunho(): void
    {
        $this->authorize('create', [RequisicaoPlanejamento::class, $this->obra->id]);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, Auth::id());
        $this->abrirRp($rp->id);
    }

    // =========================================================================
    // DETALHE (rascunho editável ou emitida somente-leitura)
    // =========================================================================

    public function abrirRp(string $id): void
    {
        $rp = RequisicaoPlanejamento::where('obra_id', $this->obra->id)->find($id);

        if (! $rp) {
            $this->dispatch('show-toast', message: 'Requisição do Planejamento não encontrada.', type: 'error');
            return;
        }

        $this->rpAbertaId = $id;
        $this->resetFiltrosItem();
        unset($this->rpAberta);
    }

    public function fecharRp(): void
    {
        $this->rpAbertaId = null;
        $this->erro = null;
        unset($this->rpAberta);
    }

    #[Computed]
    public function rpAberta(): ?RequisicaoPlanejamento
    {
        if (! $this->rpAbertaId) {
            return null;
        }

        return RequisicaoPlanejamento::where('obra_id', $this->obra->id)
            ->with(['itens.itemTakeOff.lista.revisao.documento', 'itens.itemTakeOff.unidadeMedida', 'autor', 'emitidaPor'])
            ->find($this->rpAbertaId);
    }

    /**
     * Ciclo 19, Etapa 19.3, seção 29 — leitura SOMENTE (quantidade
     * alocada/saldo a alocar/Pacotes relacionados) — a RP nunca cria/edita
     * alocação diretamente, só lê a conciliação já calculada por
     * App\Support\Suprimentos\ConciliacaoAlocacao (mesmo serviço usado
     * pelo Mapa de Suprimentos). 1 query em lote pra conciliação + 1 pra
     * os nomes dos Pacotes envolvidos, nunca por linha.
     */
    #[Computed]
    public function conciliacaoAlocacaoDaRp(): \Illuminate\Support\Collection
    {
        $rp = $this->rpAberta;
        if (! $rp || ! $rp->estaEmitida()) {
            return collect();
        }

        return ConciliacaoAlocacao::porRequisicaoItens($rp->itens);
    }

    #[Computed]
    public function pacotesPorRpItem(): \Illuminate\Support\Collection
    {
        $rp = $this->rpAberta;
        if (! $rp || ! $rp->estaEmitida()) {
            return collect();
        }

        return \App\Models\AlocacaoRequisicaoPacote::query()
            ->whereIn('requisicao_planejamento_item_id', $rp->itens->pluck('id'))
            ->with('pacote:id,nome,codigo')
            ->get()
            ->groupBy('requisicao_planejamento_item_id');
    }

    public function resetFiltrosItem(): void
    {
        $this->itemBusca = '';
        $this->itemListaId = null;
        $this->itemDisciplinaId = null;
        $this->itemFamiliaId = null;
        $this->itemUnidadeId = null;
        $this->itemSituacao = '';
    }

    public function updatedItemBusca(): void { unset($this->itensDisponiveis); }
    public function updatedItemListaId(): void { unset($this->itensDisponiveis); }
    public function updatedItemDisciplinaId(): void { unset($this->itensDisponiveis); }
    public function updatedItemFamiliaId(): void { unset($this->itensDisponiveis); }
    public function updatedItemUnidadeId(): void { unset($this->itensDisponiveis); }
    public function updatedItemSituacao(): void { unset($this->itensDisponiveis); }

    #[Computed]
    public function listasParaFiltro()
    {
        return TakeOffConsolidado::listasVigentes($this->obra->id);
    }

    #[Computed]
    public function disciplinasParaFiltro()
    {
        return Disciplina::orderBy('nome')->get();
    }

    #[Computed]
    public function familiasParaFiltro()
    {
        return FamiliaMaterial::orderBy('nome')->get();
    }

    #[Computed]
    public function unidadesParaFiltro()
    {
        return UnidadeMedida::orderBy('codigo')->get();
    }

    /**
     * Itens vigentes do Take Off da obra, filtrados, com a conciliação já
     * anexada — 1 query pra listas/itens (TakeOffConsolidado, já
     * existente) + 1 query em lote pra conciliação (ConciliacaoTakeOff),
     * nunca 1 query por item.
     */
    #[Computed]
    public function itensDisponiveis()
    {
        $itens = TakeOffConsolidado::itensVigentes($this->obra->id, null, $this->itemListaId);

        if ($this->itemDisciplinaId) {
            $itens = $itens->filter(fn ($i) => $i->disciplina_id === $this->itemDisciplinaId);
        }
        if ($this->itemFamiliaId) {
            $itens = $itens->filter(fn ($i) => $i->familia_material_id === $this->itemFamiliaId);
        }
        if ($this->itemUnidadeId) {
            $itens = $itens->filter(fn ($i) => $i->unidade_medida_id === $this->itemUnidadeId);
        }
        if ($this->itemBusca) {
            $busca = mb_strtolower($this->itemBusca);
            $itens = $itens->filter(fn ($i) => str_contains(mb_strtolower($i->descricao), $busca)
                || str_contains(mb_strtolower((string) $i->codigo), $busca));
        }

        $conciliacao = ConciliacaoTakeOff::porItens($itens);

        if ($this->itemSituacao) {
            $itens = $itens->filter(fn ($i) => ($conciliacao->get($i->id)['status'] ?? null) === $this->itemSituacao);
        }

        return $itens->map(fn ($i) => [
            'item' => $i,
            'conciliacao' => $conciliacao->get($i->id),
        ])->values();
    }

    public function definirNovaQuantidade(string $itemId): void
    {
        $this->novoItemQuantidadeDe = $itemId;
        $this->novoItemQuantidadeValor = null;
        $this->erro = null;
    }

    public function adicionarItem(string $itemTakeOffId, string $quantidade): void
    {
        $rp = $this->rpAberta;
        if (! $rp) {
            return;
        }

        $this->authorize('update', $rp);
        $this->erro = null;

        $quantidadeFloat = (float) str_replace(',', '.', $quantidade);
        if ($quantidadeFloat <= 0) {
            $this->erro = 'Informe uma quantidade maior que zero.';
            return;
        }

        try {
            (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $itemTakeOffId, $quantidadeFloat);
            $this->novoItemQuantidadeDe = null;
            unset($this->rpAberta, $this->itensDisponiveis);
            $this->dispatch('show-toast', message: 'Item adicionado à requisição.', type: 'success');
        } catch (SaldoTakeOffInsuficienteException $e) {
            $this->erro = $e->getMessage();
        } catch (RequisicaoPlanejamentoImutavelException|\InvalidArgumentException $e) {
            $this->erro = $e->getMessage();
        }
    }

    public function alterarQuantidadeItem(string $rpItemId, string $novaQuantidade): void
    {
        $rp = $this->rpAberta;
        if (! $rp) {
            return;
        }

        $this->authorize('update', $rp);
        $this->erro = null;

        $rpItem = RequisicaoPlanejamentoItem::where('requisicao_planejamento_id', $rp->id)->find($rpItemId);
        if (! $rpItem) {
            return;
        }

        $quantidadeFloat = (float) str_replace(',', '.', $novaQuantidade);
        if ($quantidadeFloat <= 0) {
            $this->erro = 'Informe uma quantidade maior que zero.';
            return;
        }

        try {
            (new AtualizarRascunhoRequisicaoPlanejamento())->alterarQuantidade($rpItem, $quantidadeFloat);
            unset($this->rpAberta, $this->itensDisponiveis);
        } catch (SaldoTakeOffInsuficienteException|RequisicaoPlanejamentoImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    public function removerItem(string $rpItemId): void
    {
        $rp = $this->rpAberta;
        if (! $rp) {
            return;
        }

        $this->authorize('update', $rp);
        $this->erro = null;

        $rpItem = RequisicaoPlanejamentoItem::where('requisicao_planejamento_id', $rp->id)->find($rpItemId);
        if (! $rpItem) {
            return;
        }

        try {
            (new AtualizarRascunhoRequisicaoPlanejamento())->removerItem($rpItem);
            unset($this->rpAberta, $this->itensDisponiveis);
            $this->dispatch('show-toast', message: 'Item removido da requisição.', type: 'success');
        } catch (RequisicaoPlanejamentoImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    public function emitirRp(): void
    {
        $rp = $this->rpAberta;
        if (! $rp) {
            return;
        }

        $this->authorize('update', $rp);
        $this->erro = null;

        try {
            (new EmitirRequisicaoPlanejamento())->execute($rp, Auth::user());
            unset($this->rpAberta);
            unset($this->requisicoes);
            $this->dispatch('show-toast', message: 'Requisição do Planejamento emitida com sucesso.', type: 'success');
        } catch (RequisicaoPlanejamentoEmissaoInvalidaException|SaldoTakeOffInsuficienteException|RequisicaoPlanejamentoImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    public function descartarRascunho(): void
    {
        $rp = $this->rpAberta;
        if (! $rp) {
            return;
        }

        $this->authorize('update', $rp);

        try {
            $rp->delete();
            $this->fecharRp();
            unset($this->requisicoes);
            $this->dispatch('show-toast', message: 'Rascunho descartado.', type: 'success');
        } catch (RequisicaoPlanejamentoImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex gap-2">
            <select wire:model.live="filtroStatus" class="form-select form-select-sm" style="width: auto">
                <option value="">Todos os status</option>
                <option value="rascunho">Rascunho</option>
                <option value="emitida">Emitida</option>
            </select>
        </div>

        @if ($this->podeEditar)
            <button type="button" class="btn btn-primary btn-sm" wire:click="criarRascunho">
                <i class="bx bx-plus"></i> Nova Requisição
            </button>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Status</th>
                        <th>Itens</th>
                        <th>Autor</th>
                        <th>Emitida em</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->requisicoes as $rp)
                        <tr wire:key="rp-{{ $rp->id }}" style="cursor:pointer">
                            <td wire:click="abrirRp('{{ $rp->id }}')">{{ $rp->numero ? '#'.str_pad($rp->numero, 4, '0', STR_PAD_LEFT) : '— (rascunho)' }}</td>
                            <td wire:click="abrirRp('{{ $rp->id }}')">
                                @if ($rp->estaEmitida())
                                    <span class="badge bg-label-success">Emitida</span>
                                @else
                                    <span class="badge bg-label-secondary">Rascunho</span>
                                @endif
                            </td>
                            <td wire:click="abrirRp('{{ $rp->id }}')">{{ $rp->itens_count }}</td>
                            <td wire:click="abrirRp('{{ $rp->id }}')">{{ $rp->autor?->name ?? 'Usuário removido' }}</td>
                            <td wire:click="abrirRp('{{ $rp->id }}')">{{ $rp->emitida_em?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirRp('{{ $rp->id }}')">Abrir</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Nenhuma Requisição do Planejamento encontrada.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body">
            {{ $this->requisicoes->links() }}
        </div>
    </div>

    {{-- Painel de detalhe (rascunho editável ou emitida somente-leitura) --}}
    @if ($this->rpAberta)
        @php($rp = $this->rpAberta)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Requisição do Planejamento
                            {{ $rp->numero ? '#'.str_pad($rp->numero, 4, '0', STR_PAD_LEFT) : '(rascunho)' }}
                            @if ($rp->estaEmitida())
                                <span class="badge bg-label-success ms-2">Emitida</span>
                            @else
                                <span class="badge bg-label-secondary ms-2">Rascunho</span>
                            @endif
                        </h5>
                        <button type="button" class="btn-close" wire:click="fecharRp"></button>
                    </div>
                    <div class="modal-body">
                        @if ($erro)
                            <div class="alert alert-danger">{{ $erro }}</div>
                        @endif

                        @if ($rp->estaEmitida())
                            <p class="text-muted mb-3">
                                Emitida em {{ $rp->emitida_em?->format('d/m/Y H:i') }} por {{ $rp->emitidaPor?->name ?? 'Usuário removido' }}.
                                Esta requisição é imutável — as informações abaixo são a fotografia do momento da emissão.
                            </p>
                        @endif

                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Descrição</th>
                                    <th>Lista</th>
                                    <th>Documento</th>
                                    <th>Quantidade</th>
                                    @if ($rp->estaEmitida())
                                        <th class="text-end">Alocado</th>
                                        <th class="text-end">Saldo a alocar</th>
                                        <th>Pacotes</th>
                                    @endif
                                    @if (! $rp->estaEmitida() && $this->podeEditar)
                                        <th></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rp->itens as $rpItem)
                                    <tr wire:key="rpitem-{{ $rpItem->id }}">
                                        @if ($rp->estaEmitida())
                                            <td>{{ $rpItem->codigo_item_snapshot ?? '—' }}</td>
                                            <td>{{ $rpItem->descricao_snapshot }}</td>
                                            <td>{{ $rpItem->lista_codigo_snapshot }} ({{ $rpItem->tipo_lista_snapshot }})</td>
                                            <td>{{ $rpItem->documento_codigo_snapshot }} — {{ $rpItem->revisao_snapshot }}</td>
                                            <td>{{ number_format((float) $rpItem->quantidade_requisitada, 3, ',', '.') }} {{ $rpItem->unidade_snapshot }}</td>
                                            @php($c = $this->conciliacaoAlocacaoDaRp->get($rpItem->id))
                                            <td class="text-end">{{ number_format($c['quantidade_alocada'] ?? 0, 3, ',', '.') }}</td>
                                            <td class="text-end">{{ number_format($c['saldo'] ?? (float) $rpItem->quantidade_requisitada, 3, ',', '.') }}</td>
                                            <td class="small">
                                                @php($pacotes = $this->pacotesPorRpItem->get($rpItem->id, collect()))
                                                @forelse ($pacotes as $alocacao)
                                                    <span class="badge bg-label-secondary">{{ $alocacao->pacote?->nome }}</span>
                                                @empty
                                                    <span class="text-muted">—</span>
                                                @endforelse
                                            </td>
                                        @else
                                            <td>{{ $rpItem->itemTakeOff?->codigo ?? '—' }}</td>
                                            <td>{{ $rpItem->itemTakeOff?->descricao }}</td>
                                            <td>{{ $rpItem->itemTakeOff?->lista?->codigo }} ({{ $rpItem->itemTakeOff?->lista?->tipo?->label() }})</td>
                                            <td>{{ $rpItem->itemTakeOff?->lista?->revisao?->documento?->codigo }}</td>
                                            <td style="width: 160px">
                                                <input type="text" class="form-control form-control-sm"
                                                    value="{{ (string) $rpItem->quantidade_requisitada }}"
                                                    wire:change="alterarQuantidadeItem('{{ $rpItem->id }}', $event.target.value)"
                                                    @if (! $this->podeEditar) disabled @endif>
                                            </td>
                                            @if ($this->podeEditar)
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removerItem('{{ $rpItem->id }}')">
                                                        <i class="bx bx-trash"></i>
                                                    </button>
                                                </td>
                                            @endif
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-3">Nenhum item nesta requisição.</td></tr>
                                @endforelse
                            </tbody>
                        </table>

                        @if (! $rp->estaEmitida() && $this->podeEditar)
                            <hr>
                            <h6>Adicionar itens do Take Off</h6>

                            <div class="row g-2 mb-2">
                                <div class="col-md-3">
                                    <input type="text" class="form-control form-control-sm" placeholder="Buscar código/descrição" wire:model.live.debounce.400ms="itemBusca">
                                </div>
                                <div class="col-md-2">
                                    <select wire:model.live="itemListaId" class="form-select form-select-sm">
                                        <option value="">Todas as listas</option>
                                        @foreach ($this->listasParaFiltro as $lista)
                                            <option value="{{ $lista->id }}">{{ $lista->codigo }} ({{ $lista->tipo->label() }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select wire:model.live="itemDisciplinaId" class="form-select form-select-sm">
                                        <option value="">Toda disciplina</option>
                                        @foreach ($this->disciplinasParaFiltro as $d)
                                            <option value="{{ $d->id }}">{{ $d->nome }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select wire:model.live="itemFamiliaId" class="form-select form-select-sm">
                                        <option value="">Toda família</option>
                                        @foreach ($this->familiasParaFiltro as $f)
                                            <option value="{{ $f->id }}">{{ $f->nome }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select wire:model.live="itemSituacao" class="form-select form-select-sm">
                                        <option value="">Qualquer situação</option>
                                        <option value="nao_requisitado">Não requisitado</option>
                                        <option value="parcial">Parcial</option>
                                        <option value="completo">Completo</option>
                                    </select>
                                </div>
                            </div>

                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Código</th>
                                            <th>Descrição</th>
                                            <th>Lista</th>
                                            <th>Previsto</th>
                                            <th>Saldo</th>
                                            <th>Situação</th>
                                            <th style="width: 220px"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($this->itensDisponiveis as $linha)
                                            @php($item = $linha['item'])
                                            @php($c = $linha['conciliacao'])
                                            <tr wire:key="disp-{{ $item->id }}" x-data="{ quantidade: '' }">
                                                <td>{{ $item->codigo ?? '—' }}</td>
                                                <td>{{ $item->descricao }}</td>
                                                <td>{{ $item->lista->codigo }}</td>
                                                <td>{{ number_format($c['quantidade_prevista'], 3, ',', '.') }} {{ $item->unidadeMedida?->codigo }}</td>
                                                <td>{{ number_format($c['saldo'], 3, ',', '.') }}</td>
                                                <td>
                                                    @if ($c['status'] === 'completo')
                                                        <span class="badge bg-label-success">Completo</span>
                                                    @elseif ($c['status'] === 'parcial')
                                                        <span class="badge bg-label-warning">Parcial</span>
                                                    @else
                                                        <span class="badge bg-label-secondary">Não requisitado</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($c['saldo'] > 0)
                                                        <div class="input-group input-group-sm">
                                                            <input type="text" class="form-control" placeholder="Qtd." x-model="quantidade">
                                                            <button type="button" class="btn btn-outline-primary" wire:click="adicionarItem('{{ $item->id }}', quantidade)">
                                                                Adicionar
                                                            </button>
                                                        </div>
                                                    @else
                                                        <span class="text-muted small">Sem saldo</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="7" class="text-center text-muted py-3">Nenhum item do Take Off encontrado com esses filtros.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        @if (! $rp->estaEmitida() && $this->podeEditar)
                            <button type="button" class="btn btn-outline-danger me-auto" wire:click="descartarRascunho"
                                onclick="return confirm('Descartar este rascunho? Esta ação não pode ser desfeita.')">
                                Descartar Rascunho
                            </button>
                            <button type="button" class="btn btn-success" wire:click="emitirRp" wire:loading.attr="disabled">
                                <i class="bx bx-check"></i> Emitir Requisição
                            </button>
                        @endif
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharRp">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
