<?php

use App\Enums\OrigemItemTakeOff;
use App\Enums\TipoItemTakeOff;
use App\Exceptions\ListaEngenhariaImutavelException;
use App\Imports\TakeOffImporter;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\FamiliaMaterial;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\UnidadeMedida;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use App\Support\TakeOff\CurvaAbcTakeOff;
use App\Support\TakeOff\TakeOffConsolidado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — Take Off (LM/LI). Mesmo padrão de
 * seletor de obra próprio de ⚡grds.blade.php/⚡documentos-engenharia.blade.php
 * (`engenharia.pacotes` é ESCOPO_TENANT — página não trava na obra
 * ativa da sessão, nenhum slug novo).
 *
 * Fluxo: Obra → Documento → Revisão → LISTA (LM-001/LM-002/LI-001, nova
 * entidade da correção) → Itens (manual/importação). "Consolidado"
 * agrega só listas de revisões VIGENTES, com Curva ABC segmentada por
 * Unidade de Medida (nunca somando grandezas incompatíveis).
 */
new class extends Component {
    use ExecutaComTransacaoSegura, WithFileUploads;

    #[Url(as: 'obra')]
    public ?string $obraId = null;

    #[Url(as: 'aba')]
    public string $abaAtiva = 'gerenciar';

    private const ABAS_VALIDAS = ['gerenciar', 'consolidado'];

    // ---- Seleção de Documento/Revisão/Lista (aba Gerenciar) ----
    public ?string $documentoId = null;
    public ?string $revisaoId = null;
    public ?string $listaId = null;
    public string $buscaDocumento = '';

    // ---- Modal nova lista ----
    public bool $listaFormAberto = false;
    public string $listaFormTipo = 'material';
    public string $listaFormCodigo = '';
    public string $listaFormTitulo = '';
    public ?string $listaFormDisciplinaId = null;
    public string $listaFormObservacao = '';

    // ---- Modal item manual ----
    public bool $itemFormAberto = false;
    public ?string $itemEditandoId = null;
    public string $formCodigo = '';
    public string $formDescricao = '';
    public ?string $formUnidadeId = null;
    public ?string $formFamiliaId = null;
    public ?string $formDisciplinaId = null;
    public $formQuantidade = '';
    public string $formObservacoes = '';

    // ---- Modal importação Excel ----
    public bool $importModalAberto = false;
    public $arquivoImportacao = null;
    public ?array $previaImportacao = null;

    // ---- Aba Consolidado ----
    public string $consolidadoTipoFiltro = '';
    public string $consolidadoListaFiltro = '';

    public function mount(): void
    {
        if (! in_array($this->abaAtiva, self::ABAS_VALIDAS, true)) {
            $this->abaAtiva = 'gerenciar';
        }

        if ($this->obraId && ! Auth::user()?->temPermissaoNaObra($this->obraId, 'engenharia.pacotes', 'ver')) {
            $this->obraId = null;
        }
    }

    private function garantirPermissaoNaObraAtual(string $acao): void
    {
        abort_unless(
            $this->obraId && Auth::user()?->temPermissaoNaObra($this->obraId, 'engenharia.pacotes', $acao),
            403
        );
    }

    private function resolverRevisaoDaObraAtual(string $id): DocumentoEngenhariaRevisao
    {
        return DocumentoEngenhariaRevisao::whereHas(
            'documento',
            fn ($q) => $q->where('obra_id', $this->obraId)
        )->findOrFail($id);
    }

    /**
     * Sempre resolve a lista a partir da OBRA atual (via
     * revisao->documento->obra_id), nunca confiando num id solto vindo
     * do Livewire/UI (seção 15 — importador/mutações sempre recebem
     * objeto já autorizado, nunca IDs crus).
     */
    private function resolverListaDaObraAtual(string $id): ListaEngenharia
    {
        return ListaEngenharia::whereHas(
            'revisao.documento',
            fn ($q) => $q->where('obra_id', $this->obraId)
        )->with('revisao.documento')->findOrFail($id);
    }

    #[Computed]
    public function obras(): Collection
    {
        $usuario = Auth::user();

        return Work::orderBy('name')->get(['id', 'name'])
            ->filter(fn (Work $obra) => $usuario?->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'ver'))
            ->values();
    }

    public function updatedObraId(): void
    {
        if ($this->obraId) {
            $this->garantirPermissaoNaObraAtual('ver');
        }

        $this->documentoId = null;
        $this->revisaoId = null;
        $this->listaId = null;
    }

    #[Computed]
    public function documentos(): Collection
    {
        if (! $this->obraId) {
            return collect();
        }

        return DocumentoEngenharia::where('obra_id', $this->obraId)
            ->when($this->buscaDocumento !== '', function ($q) {
                $termo = "%{$this->buscaDocumento}%";
                $q->where(fn ($qq) => $qq->where('codigo', 'like', $termo)->orWhere('descricao', 'like', $termo));
            })
            ->orderBy('codigo')
            ->with('revisoes:id,documento_engenharia_id,revisao,data_emissao,created_at')
            ->get();
    }

    public function updatedBuscaDocumento(): void
    {
        unset($this->documentos);
    }

    public function selecionarDocumento(string $documentoId): void
    {
        $this->garantirPermissaoNaObraAtual('ver');
        $documento = DocumentoEngenharia::where('obra_id', $this->obraId)->findOrFail($documentoId);

        $this->documentoId = $documento->id;
        $this->revisaoId = $documento->revisaoVigente()?->id;
        $this->listaId = null;
    }

    public function selecionarRevisao(string $revisaoId): void
    {
        $this->garantirPermissaoNaObraAtual('ver');
        $this->revisaoId = $this->resolverRevisaoDaObraAtual($revisaoId)->id;
        $this->listaId = null;
    }

    public function selecionarLista(string $listaId): void
    {
        $this->garantirPermissaoNaObraAtual('ver');
        $this->listaId = $this->resolverListaDaObraAtual($listaId)->id;
    }

    #[Computed]
    public function revisaoAtual(): ?DocumentoEngenhariaRevisao
    {
        if (! $this->revisaoId) {
            return null;
        }

        return DocumentoEngenhariaRevisao::with('documento')->find($this->revisaoId);
    }

    #[Computed]
    public function listasDaRevisao(): Collection
    {
        if (! $this->revisaoId) {
            return collect();
        }

        return ListaEngenharia::where('documento_engenharia_revisao_id', $this->revisaoId)
            ->withCount('itens')
            ->orderBy('tipo')
            ->orderBy('codigo')
            ->get();
    }

    #[Computed]
    public function listaAtual(): ?ListaEngenharia
    {
        if (! $this->listaId) {
            return null;
        }

        return ListaEngenharia::with('revisao.documento', 'disciplina')->find($this->listaId);
    }

    #[Computed]
    public function itensDaLista(): Collection
    {
        if (! $this->listaId) {
            return collect();
        }

        return ItemTakeOff::where('lista_engenharia_id', $this->listaId)
            ->with(['unidadeMedida', 'familiaMaterial', 'disciplina'])
            ->orderBy('codigo')
            ->get();
    }

    #[Computed]
    public function unidadesMedida(): Collection
    {
        return UnidadeMedida::where('ativo', true)->orderBy('nome')->get();
    }

    #[Computed]
    public function familiasMaterial(): Collection
    {
        return FamiliaMaterial::where('ativo', true)->orderBy('nome')->get();
    }

    #[Computed]
    public function disciplinas(): Collection
    {
        return Disciplina::orderBy('nome')->get();
    }

    // =========================================================================
    // LISTA (LM/LI)
    // =========================================================================

    /**
     * Ciclo 19, Etapa 19.1.HARDENING — checagem antecipada e amigável
     * (UX) de "esta lista pode ser editada agora?" — a garantia REAL é
     * `ListaEngenharia::garantirEditavel()`/`ItemTakeOffObserver`, que
     * dispara em qualquer mutação real independente desta checagem
     * (nunca confiar só em botão escondido). Esta checagem só evita o
     * usuário preencher um formulário inteiro pra descobrir o bloqueio
     * só no fim.
     */
    private function garantirListaSelecionadaEditavel(): bool
    {
        abort_if(! $this->listaId, 400);
        $lista = $this->resolverListaDaObraAtual($this->listaId);

        if (! $lista->estaVigente()) {
            $this->dispatch('show-toast', message: 'Esta lista pertence a uma revisão que não é mais a vigente — não pode ser editada, reimportada ou ter itens excluídos.', type: 'error');
            return false;
        }

        return true;
    }

    private function garantirRevisaoSelecionadaVigente(): bool
    {
        abort_if(! $this->revisaoId, 400);
        // resolverRevisaoDaObraAtual() não eager-carrega 'documento' (é
        // compartilhado com outros chamadores que não precisam disso) —
        // resolve o documento separadamente pra nunca disparar lazy load.
        $revisao = $this->resolverRevisaoDaObraAtual($this->revisaoId);
        $documento = DocumentoEngenharia::find($revisao->documento_engenharia_id);

        if ($documento?->revisaoVigente()?->id !== $revisao->id) {
            $this->dispatch('show-toast', message: 'Esta revisão não é mais a vigente do documento — não é possível criar novas listas nela.', type: 'error');
            return false;
        }

        return true;
    }

    public function abrirNovaLista(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        abort_if(! $this->revisaoId, 400);

        if (! $this->garantirRevisaoSelecionadaVigente()) {
            return;
        }

        $this->listaFormTipo = TipoItemTakeOff::Material->value;
        $this->listaFormCodigo = '';
        $this->listaFormTitulo = '';
        $this->listaFormDisciplinaId = null;
        $this->listaFormObservacao = '';
        $this->resetErrorBag();
        $this->listaFormAberto = true;
    }

    public function fecharFormLista(): void
    {
        $this->listaFormAberto = false;
    }

    public function salvarLista(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        abort_if(! $this->revisaoId, 400);

        if (! $this->garantirRevisaoSelecionadaVigente()) {
            return;
        }

        $this->validate([
            'listaFormTipo' => 'required|in:material,instrumento',
            'listaFormCodigo' => 'required|string|max:100',
            'listaFormTitulo' => 'nullable|string|max:255',
            'listaFormDisciplinaId' => 'nullable|exists:disciplinas,id',
            'listaFormObservacao' => 'nullable|string|max:1000',
        ]);

        $duplicado = ListaEngenharia::where('documento_engenharia_revisao_id', $this->revisaoId)
            ->where('tipo', $this->listaFormTipo)
            ->where('codigo', $this->listaFormCodigo)
            ->exists();

        if ($duplicado) {
            $this->addError('listaFormCodigo', 'Já existe uma lista deste tipo com este código nesta revisão.');
            return;
        }

        $lista = null;

        $this->transacaoSegura(function () use (&$lista) {
            $lista = ListaEngenharia::create([
                'documento_engenharia_revisao_id' => $this->revisaoId,
                'tipo' => $this->listaFormTipo,
                'codigo' => $this->listaFormCodigo,
                'titulo' => $this->listaFormTitulo !== '' ? $this->listaFormTitulo : null,
                'disciplina_id' => $this->listaFormDisciplinaId,
                'observacao' => $this->listaFormObservacao !== '' ? $this->listaFormObservacao : null,
            ]);
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->listaFormAberto = false;
        $this->listaId = $lista->id;
        unset($this->listasDaRevisao);
        $this->dispatch('show-toast', message: 'Lista criada.', type: 'success');
    }

    // =========================================================================
    // CRUD MANUAL DE ITEM
    // =========================================================================

    public function abrirNovoItem(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        if (! $this->garantirListaSelecionadaEditavel()) {
            return;
        }

        $this->itemEditandoId = null;
        $this->formCodigo = '';
        $this->formDescricao = '';
        $this->formUnidadeId = null;
        $this->formFamiliaId = null;
        $this->formDisciplinaId = null;
        $this->formQuantidade = '';
        $this->formObservacoes = '';
        $this->resetErrorBag();
        $this->itemFormAberto = true;
    }

    public function abrirEdicaoItem(string $itemId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        if (! $this->garantirListaSelecionadaEditavel()) {
            return;
        }
        $item = ItemTakeOff::where('lista_engenharia_id', $this->listaId)->findOrFail($itemId);

        $this->itemEditandoId = $item->id;
        $this->formCodigo = (string) $item->codigo;
        $this->formDescricao = $item->descricao;
        $this->formUnidadeId = $item->unidade_medida_id;
        $this->formFamiliaId = $item->familia_material_id;
        $this->formDisciplinaId = $item->disciplina_id;
        $this->formQuantidade = (string) $item->quantidade;
        $this->formObservacoes = (string) $item->observacoes;
        $this->resetErrorBag();
        $this->itemFormAberto = true;
    }

    public function fecharFormItem(): void
    {
        $this->itemFormAberto = false;
    }

    public function salvarItem(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        if (! $this->garantirListaSelecionadaEditavel()) {
            return;
        }

        $this->validate([
            'formCodigo' => 'nullable|string|max:100',
            'formDescricao' => 'required|string|max:500',
            'formUnidadeId' => 'nullable|exists:unidades_medida,id',
            'formFamiliaId' => 'nullable|exists:familias_material,id',
            'formDisciplinaId' => 'nullable|exists:disciplinas,id',
            'formQuantidade' => 'required|numeric|gt:0',
            'formObservacoes' => 'nullable|string|max:1000',
        ]);

        $codigo = $this->formCodigo !== '' ? $this->formCodigo : null;

        if ($codigo) {
            $duplicado = ItemTakeOff::where('lista_engenharia_id', $this->listaId)
                ->where('codigo', $codigo)
                ->when($this->itemEditandoId, fn ($q) => $q->where('id', '!=', $this->itemEditandoId))
                ->exists();

            if ($duplicado) {
                $this->addError('formCodigo', 'Já existe um item com este código nesta lista.');
                return;
            }
        }

        $dados = [
            'codigo' => $codigo,
            'descricao' => $this->formDescricao,
            'unidade_medida_id' => $this->formUnidadeId,
            'familia_material_id' => $this->formFamiliaId,
            'disciplina_id' => $this->formDisciplinaId,
            'quantidade' => $this->formQuantidade,
            'observacoes' => $this->formObservacoes !== '' ? $this->formObservacoes : null,
        ];

        $this->transacaoSegura(function () use ($dados) {
            if ($this->itemEditandoId) {
                ItemTakeOff::where('lista_engenharia_id', $this->listaId)
                    ->findOrFail($this->itemEditandoId)
                    ->update($dados);
            } else {
                ItemTakeOff::create($dados + [
                    'lista_engenharia_id' => $this->listaId,
                    'origem' => OrigemItemTakeOff::Manual->value,
                    'created_by_id' => Auth::id(),
                ]);
            }
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->itemFormAberto = false;
        unset($this->itensDaLista, $this->listasDaRevisao);
        $this->dispatch('show-toast', message: $this->itemEditandoId ? 'Item atualizado.' : 'Item adicionado.', type: 'success');
    }

    public function excluirItem(string $itemId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');

        // Ciclo 19, Etapa 19.2.CORREÇÃO — trava a linha ANTES de checar/
        // excluir, dentro da MESMA transação, pra disputar o lock com
        // AtualizarRascunhoRequisicaoPlanejamento::adicionarItem() (que
        // trava esta MESMA linha antes de criar um RequisicaoPlanejamentoItem
        // novo). Sem isso, um delete concorrente com uma inclusão em RP
        // poderia observar "sem referência" e "não deletado" ao mesmo
        // tempo — exatamente a corrida vetada pela auditoria adversarial.
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($itemId) {
                $item = ItemTakeOff::where('lista_engenharia_id', $this->listaId)
                    ->whereKey($itemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $item->delete();
            });
        } catch (ListaEngenhariaImutavelException|\App\Exceptions\ItemTakeOffReferenciadoException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
            return;
        }

        unset($this->itensDaLista, $this->listasDaRevisao);
        $this->dispatch('show-toast', message: 'Item removido.', type: 'success');
    }

    // =========================================================================
    // IMPORTAÇÃO EXCEL
    // =========================================================================

    public function abrirImportacao(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        if (! $this->garantirListaSelecionadaEditavel()) {
            return;
        }

        $this->arquivoImportacao = null;
        $this->previaImportacao = null;
        $this->resetErrorBag();
        $this->importModalAberto = true;
    }

    public function fecharImportacao(): void
    {
        $this->importModalAberto = false;
        $this->arquivoImportacao = null;
        $this->previaImportacao = null;
    }

    public function analisarImportacao(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        if (! $this->garantirListaSelecionadaEditavel()) {
            return;
        }
        $this->validate(['arquivoImportacao' => 'required|file|mimes:xlsx,xlsm'], [], ['arquivoImportacao' => 'planilha']);

        $importador = new TakeOffImporter();

        try {
            $linhas = $importador->lerLinhas($this->arquivoImportacao->getRealPath());
        } catch (\RuntimeException $e) {
            $this->addError('arquivoImportacao', $e->getMessage());
            return;
        }

        if (empty($linhas)) {
            $this->addError('arquivoImportacao', 'Nenhuma linha com descrição encontrada na aba "TAKEOFF" da planilha.');
            return;
        }

        $lista = $this->resolverListaDaObraAtual($this->listaId);
        $this->previaImportacao = $importador->analisar($linhas, $lista);
    }

    public function confirmarImportacao(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');

        if (! $this->previaImportacao) {
            return;
        }

        // Revalida vigência aqui também (não só em abrirImportacao()) —
        // seção 12/13 do pedido: reimportação de revisão superada
        // precisa ser rejeitada antes do primeiro write, mesmo se o
        // tempo entre abrir o modal e confirmar permitir uma nova
        // revisão nascer no meio do caminho.
        if (! $this->garantirListaSelecionadaEditavel()) {
            return;
        }

        $importador = new TakeOffImporter();
        $lista = $this->resolverListaDaObraAtual($this->listaId);
        $linhas = $this->previaImportacao['linhas'];
        $usuarioId = Auth::id();
        $resultado = null;

        $this->transacaoSegura(function () use ($importador, $linhas, $lista, $usuarioId, &$resultado) {
            $resultado = $importador->aplicar($linhas, $lista, $usuarioId);
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->fecharImportacao();
        unset($this->itensDaLista, $this->listasDaRevisao);
        $this->dispatch('show-toast', message: "Importação concluída: {$resultado['novos']} novos, {$resultado['atualizados']} atualizados.", type: 'success');
    }

    // =========================================================================
    // CONSOLIDADO + CURVA ABC
    // =========================================================================

    public function updatedConsolidadoTipoFiltro(): void
    {
        unset($this->itensConsolidados, $this->curvaAbcPorUnidade, $this->conciliacaoConsolidado);
    }

    public function updatedConsolidadoListaFiltro(): void
    {
        unset($this->itensConsolidados, $this->curvaAbcPorUnidade, $this->conciliacaoConsolidado);
    }

    #[Computed]
    public function listasVigentesDaObra(): Collection
    {
        if (! $this->obraId) {
            return collect();
        }

        return TakeOffConsolidado::listasVigentes($this->obraId);
    }

    #[Computed]
    public function itensConsolidados(): Collection
    {
        if (! $this->obraId) {
            return collect();
        }

        $tipo = $this->consolidadoTipoFiltro !== '' ? TipoItemTakeOff::from($this->consolidadoTipoFiltro) : null;
        $listaId = $this->consolidadoListaFiltro !== '' ? $this->consolidadoListaFiltro : null;

        return TakeOffConsolidado::itensVigentes($this->obraId, $tipo, $listaId);
    }

    #[Computed]
    public function curvaAbcPorUnidade(): array
    {
        return CurvaAbcTakeOff::calcularAgrupadoPorUnidade($this->itensConsolidados);
    }

    /**
     * Ciclo 19, Etapa 19.2 — leitura SOMENTE (Previsto/Requisitado/Saldo/
     * Situação), seção 26 do pedido: a Engenharia nunca emite RP, só lê a
     * conciliação já calculada por App\Support\Suprimentos\ConciliacaoTakeOff
     * (mesmo serviço usado pela tela de Planejamento) — 1 query em lote,
     * nunca por item.
     */
    #[Computed]
    public function conciliacaoConsolidado(): \Illuminate\Support\Collection
    {
        return \App\Support\Suprimentos\ConciliacaoTakeOff::porItens($this->itensConsolidados);
    }

    #[Computed]
    public function conciliacaoPorListaConsolidado(): \Illuminate\Support\Collection
    {
        $listas = $this->listasVigentesDaObra->load('itens');

        return \App\Support\Suprimentos\ConciliacaoTakeOff::porListas($listas);
    }
}; ?>

<div>
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label mb-1">Obra</label>
                <select class="form-select" wire:model.live="obraId" style="min-width: 260px">
                    <option value="">Selecione uma obra…</option>
                    @foreach ($this->obras as $obra)
                        <option value="{{ $obra->id }}">{{ $obra->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if (! $obraId)
        <div class="alert alert-info">Selecione uma obra para gerenciar o Take Off.</div>
    @else
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <button type="button" class="nav-link @if ($abaAtiva === 'gerenciar') active @endif" wire:click="$set('abaAtiva', 'gerenciar')">Gerenciar por Lista</button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link @if ($abaAtiva === 'consolidado') active @endif" wire:click="$set('abaAtiva', 'consolidado')">Take Off Consolidado (Curva ABC)</button>
            </li>
        </ul>

        {{-- =================== ABA GERENCIAR =================== --}}
        @if ($abaAtiva === 'gerenciar')
            <div class="row">
                <div class="col-md-3 mb-4">
                    <div class="card h-100">
                        <div class="card-header">Documentos</div>
                        <div class="card-body">
                            <input type="text" class="form-control mb-3" placeholder="Buscar código/descrição…" wire:model.live.debounce.400ms="buscaDocumento">

                            <div class="list-group" style="max-height: 460px; overflow-y: auto;">
                                @forelse ($this->documentos as $documento)
                                    <button type="button"
                                        wire:key="doc-{{ $documento->id }}"
                                        wire:click="selecionarDocumento('{{ $documento->id }}')"
                                        class="list-group-item list-group-item-action @if ($documentoId === $documento->id) active @endif">
                                        <div class="fw-semibold">{{ $documento->codigo }}</div>
                                        <div class="small text-truncate">{{ $documento->descricao }}</div>
                                    </button>
                                @empty
                                    <div class="text-muted small">Nenhum documento encontrado.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-4">
                    @if (! $documentoId)
                        <div class="alert alert-secondary">Selecione um documento.</div>
                    @else
                        @php($documentoAtual = $this->documentos->firstWhere('id', $documentoId))
                        <div class="card h-100">
                            <div class="card-header">Revisão</div>
                            <div class="card-body">
                                <select class="form-select mb-3" wire:change="selecionarRevisao($event.target.value)">
                                    @foreach ($documentoAtual->revisoes as $rev)
                                        <option value="{{ $rev->id }}" @selected($revisaoId === $rev->id)>
                                            {{ $rev->revisao }} @if ($rev->id === $documentoAtual->revisaoVigente()?->id) (vigente) @endif
                                        </option>
                                    @endforeach
                                </select>

                                @if ($revisaoId)
                                    @php($revisaoAtualVigente = $this->revisaoAtual?->documento?->revisaoVigente()?->id === $revisaoId)
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="small text-muted">
                                            Listas (LM/LI)
                                            @unless ($revisaoAtualVigente)
                                                <span class="badge bg-label-secondary">Revisão histórica</span>
                                            @endunless
                                        </span>
                                        @if ($revisaoAtualVigente)
                                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirNovaLista">
                                                <i class="bx bx-plus"></i> Nova
                                            </button>
                                        @endif
                                    </div>

                                    <div class="list-group" style="max-height: 360px; overflow-y: auto;">
                                        @forelse ($this->listasDaRevisao as $lista)
                                            <button type="button"
                                                wire:key="lista-{{ $lista->id }}"
                                                wire:click="selecionarLista('{{ $lista->id }}')"
                                                class="list-group-item list-group-item-action @if ($listaId === $lista->id) active @endif">
                                                <div class="fw-semibold">
                                                    {{ $lista->codigo }} <span class="badge bg-label-secondary">{{ $lista->tipo->label() }}</span>
                                                    @unless ($revisaoAtualVigente)
                                                        <i class="bx bx-lock-alt text-muted" title="Histórica"></i>
                                                    @endunless
                                                </div>
                                                @if ($lista->titulo)
                                                    <div class="small text-truncate">{{ $lista->titulo }}</div>
                                                @endif
                                                <div class="small text-muted">{{ $lista->itens_count }} {{ $lista->itens_count === 1 ? 'item' : 'itens' }}</div>
                                            </button>
                                        @empty
                                            <div class="text-muted small">Nenhuma lista nesta revisão ainda.</div>
                                        @endforelse
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div class="col-md-6 mb-4">
                    @if (! $listaId)
                        <div class="alert alert-secondary">Selecione (ou crie) uma lista para ver/gerenciar seus itens.</div>
                    @else
                        @php($listaVigente = $this->listaAtual->estaVigente())
                        <div class="card mb-3">
                            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                                <div>
                                    <div class="fw-semibold">
                                        {{ $this->listaAtual->codigo }} — {{ $this->listaAtual->titulo ?? $this->listaAtual->tipo->label() }}
                                        @unless ($listaVigente)
                                            <span class="badge bg-label-secondary ms-1"><i class="bx bx-lock-alt"></i> Histórica (somente leitura)</span>
                                        @endunless
                                    </div>
                                    <div class="small text-muted">
                                        {{ $this->listaAtual->tipo->label() }} ·
                                        Documento {{ $this->listaAtual->revisao->documento->codigo }} — {{ $this->listaAtual->revisao->revisao }}
                                        @if ($this->listaAtual->disciplina) · {{ $this->listaAtual->disciplina->nome }} @endif
                                    </div>
                                </div>

                                @if ($listaVigente)
                                    <div class="ms-auto d-flex gap-2">
                                        <button type="button" class="btn btn-outline-primary" wire:click="abrirImportacao">
                                            <i class="bx bx-upload me-1"></i> Importar planilha
                                        </button>
                                        <button type="button" class="btn btn-primary" wire:click="abrirNovoItem">
                                            <i class="bx bx-plus me-1"></i> Novo item
                                        </button>
                                    </div>
                                @else
                                    <div class="ms-auto small text-muted">
                                        Esta revisão não é mais a vigente do documento — lista congelada, somente leitura.
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="card">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Código</th>
                                            <th>Descrição</th>
                                            <th>Unidade</th>
                                            <th>Família</th>
                                            <th>Disciplina</th>
                                            <th class="text-end">Quantidade</th>
                                            <th>Origem</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($this->itensDaLista as $item)
                                            <tr wire:key="item-{{ $item->id }}">
                                                <td>{{ $item->codigo ?? '—' }}</td>
                                                <td>{{ $item->descricao }}</td>
                                                <td>{{ $item->unidadeMedida?->codigo ?? '—' }}</td>
                                                <td>{{ $item->familiaMaterial?->nome ?? '—' }}</td>
                                                <td>{{ $item->disciplina?->nome ?? '—' }}</td>
                                                <td class="text-end">{{ number_format((float) $item->quantidade, 3, ',', '.') }}</td>
                                                <td><span class="badge bg-label-secondary">{{ $item->origem->label() }}</span></td>
                                                <td class="text-end">
                                                    @if ($listaVigente)
                                                        <button type="button" class="btn btn-sm btn-icon" wire:click="abrirEdicaoItem('{{ $item->id }}')"><i class="bx bx-edit"></i></button>
                                                        <button type="button" class="btn btn-sm btn-icon text-danger"
                                                            onclick="confirmarAcao(this, { mensagem: 'Excluir este item de Take Off?', metodo: 'excluirItem', args: ['{{ $item->id }}'], corBotao: 'danger', icone: 'bx-trash' })">
                                                            <i class="bx bx-trash"></i>
                                                        </button>
                                                    @else
                                                        <i class="bx bx-lock-alt text-muted" title="Histórico — somente leitura"></i>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="8" class="text-center text-muted py-4">Nenhum item nesta lista ainda.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- =================== ABA CONSOLIDADO =================== --}}
        @if ($abaAtiva === 'consolidado')
            <div class="card mb-4">
                <div class="card-body d-flex flex-wrap align-items-end gap-3">
                    <div>
                        <label class="form-label mb-1">Tipo</label>
                        <select class="form-select" wire:model.live="consolidadoTipoFiltro">
                            <option value="">Todos</option>
                            <option value="material">Material</option>
                            <option value="instrumento">Instrumento</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-1">Lista</label>
                        <select class="form-select" wire:model.live="consolidadoListaFiltro" style="min-width: 220px">
                            <option value="">Todas</option>
                            @foreach ($this->listasVigentesDaObra as $lista)
                                <option value="{{ $lista->id }}">{{ $lista->codigo }} — {{ $lista->revisao->documento->codigo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="ms-auto">
                        <a href="{{ route('planejamento.requisicoes') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bx bx-link-external"></i> Ver requisições relacionadas
                        </a>
                    </div>
                </div>
            </div>

            {{-- Ciclo 19, Etapa 19.2 — cobertura por lista, seção 26 do
                 pedido: SOMENTE leitura, nunca emite RP aqui. Contagem de
                 itens (nunca soma de quantidade entre unidades). --}}
            @if ($this->conciliacaoPorListaConsolidado->isNotEmpty())
                <div class="card mb-4">
                    <div class="card-header">Cobertura de Requisição por Lista</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Lista</th>
                                    <th class="text-end">Itens</th>
                                    <th class="text-end">Não requisitados</th>
                                    <th class="text-end">Parciais</th>
                                    <th class="text-end">Completos</th>
                                    <th class="text-end">% Completo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->conciliacaoPorListaConsolidado as $l)
                                    <tr wire:key="cobertura-{{ $l['lista_id'] }}">
                                        <td>{{ $l['lista_codigo'] }}</td>
                                        <td class="text-end">{{ $l['total_itens'] }}</td>
                                        <td class="text-end">{{ $l['itens_nao_requisitados'] }}</td>
                                        <td class="text-end">{{ $l['itens_parciais'] }}</td>
                                        <td class="text-end">{{ $l['itens_completos'] }}</td>
                                        <td class="text-end">
                                            @if ($l['percentual_itens_completos'] === null)
                                                —
                                            @else
                                                <span class="badge bg-label-{{ $l['percentual_itens_completos'] == 100 ? 'success' : ($l['percentual_itens_completos'] == 0 ? 'secondary' : 'warning') }}">
                                                    {{ number_format($l['percentual_itens_completos'], 1, ',', '.') }}%
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @forelse ($this->curvaAbcPorUnidade as $grupo)
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Unidade: <strong>{{ $grupo['unidade_label'] }}</strong> — total {{ number_format($grupo['total_quantidade'], 3, ',', '.') }}</span>
                        <div class="d-flex gap-2">
                            @php($resumo = ['A' => 0, 'B' => 0, 'C' => 0])
                            @foreach ($grupo['linhas'] as $l)
                                @php($resumo[$l['classe']]++)
                            @endforeach
                            <span class="badge bg-label-success">A: {{ $resumo['A'] }}</span>
                            <span class="badge bg-label-warning">B: {{ $resumo['B'] }}</span>
                            <span class="badge bg-label-secondary">C: {{ $resumo['C'] }}</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Classe</th>
                                    <th>Código</th>
                                    <th>Descrição</th>
                                    <th>Lista</th>
                                    <th>Documento / Revisão</th>
                                    <th>Disciplina</th>
                                    <th class="text-end">Previsto</th>
                                    <th class="text-end">Requisitado</th>
                                    <th class="text-end">Saldo</th>
                                    <th>Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grupo['linhas'] as $linha)
                                    @php($item = $linha['item'])
                                    @php($c = $this->conciliacaoConsolidado->get($item->id))
                                    <tr wire:key="abc-{{ $item->id }}">
                                        <td>
                                            <span class="badge bg-label-{{ $linha['classe'] === 'A' ? 'success' : ($linha['classe'] === 'B' ? 'warning' : 'secondary') }}">
                                                {{ $linha['classe'] }}
                                            </span>
                                        </td>
                                        <td>{{ $item->codigo ?? '—' }}</td>
                                        <td>{{ $item->descricao }}</td>
                                        <td class="small">{{ $item->lista->codigo }} <span class="badge bg-label-secondary">{{ $item->lista->tipo->label() }}</span></td>
                                        <td class="small">{{ $item->lista->revisao->documento->codigo }} / {{ $item->lista->revisao->revisao }}</td>
                                        <td>{{ $item->disciplina?->nome ?? '—' }}</td>
                                        <td class="text-end">{{ number_format((float) $item->quantidade, 3, ',', '.') }}</td>
                                        <td class="text-end">{{ number_format($c['quantidade_requisitada'] ?? 0, 3, ',', '.') }}</td>
                                        <td class="text-end">{{ number_format($c['saldo'] ?? (float) $item->quantidade, 3, ',', '.') }}</td>
                                        <td>
                                            @php($situacao = $c['status'] ?? 'nao_requisitado')
                                            @if ($situacao === 'completo')
                                                <span class="badge bg-label-success">Completo</span>
                                            @elseif ($situacao === 'parcial')
                                                <span class="badge bg-label-warning">Parcial</span>
                                            @else
                                                <span class="badge bg-label-secondary">Não requisitado</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="alert alert-secondary">Nenhum item de Take Off em revisão vigente desta obra ainda.</div>
            @endforelse
        @endif
    @endif

    {{-- =================== MODAL NOVA LISTA =================== --}}
    @if ($listaFormAberto)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Nova Lista (LM/LI)</h5>
                        <button type="button" class="btn-close" wire:click="fecharFormLista"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" wire:model="listaFormTipo">
                                    <option value="material">Material</option>
                                    <option value="instrumento">Instrumento</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Código</label>
                                <input type="text" class="form-control @error('listaFormCodigo') is-invalid @enderror" wire:model="listaFormCodigo" placeholder="LM-001">
                                @error('listaFormCodigo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Título</label>
                                <input type="text" class="form-control" wire:model="listaFormTitulo" placeholder="Ex.: Tubulação — Área 100">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Disciplina</label>
                                <select class="form-select" wire:model="listaFormDisciplinaId">
                                    <option value="">—</option>
                                    @foreach ($this->disciplinas as $d)
                                        <option value="{{ $d->id }}">{{ $d->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observação</label>
                                <textarea class="form-control" rows="2" wire:model="listaFormObservacao"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharFormLista">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="salvarLista">Salvar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- =================== MODAL ITEM MANUAL =================== --}}
    @if ($itemFormAberto)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $itemEditandoId ? 'Editar item' : 'Novo item de Take Off' }}</h5>
                        <button type="button" class="btn-close" wire:click="fecharFormItem"></button>
                    </div>
                    <div class="modal-body">
                        @if ($this->listaAtual)
                            <div class="alert alert-light border small mb-3">
                                Origem: <strong>{{ $this->listaAtual->codigo }}</strong>
                                @if ($this->listaAtual->titulo) — {{ $this->listaAtual->titulo }} @endif
                                · Documento {{ $this->listaAtual->revisao->documento->codigo }} — {{ $this->listaAtual->revisao->revisao }}
                            </div>
                        @endif
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Código</label>
                                <input type="text" class="form-control @error('formCodigo') is-invalid @enderror" wire:model="formCodigo">
                                @error('formCodigo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quantidade</label>
                                <input type="text" class="form-control @error('formQuantidade') is-invalid @enderror" wire:model="formQuantidade">
                                @error('formQuantidade') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Unidade</label>
                                <select class="form-select" wire:model="formUnidadeId">
                                    <option value="">—</option>
                                    @foreach ($this->unidadesMedida as $u)
                                        <option value="{{ $u->id }}">{{ $u->codigo }} — {{ $u->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descrição</label>
                                <input type="text" class="form-control @error('formDescricao') is-invalid @enderror" wire:model="formDescricao">
                                @error('formDescricao') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Família</label>
                                <select class="form-select" wire:model="formFamiliaId">
                                    <option value="">—</option>
                                    @foreach ($this->familiasMaterial as $f)
                                        <option value="{{ $f->id }}">{{ $f->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Disciplina</label>
                                <select class="form-select" wire:model="formDisciplinaId">
                                    <option value="">—</option>
                                    @foreach ($this->disciplinas as $d)
                                        <option value="{{ $d->id }}">{{ $d->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observações</label>
                                <textarea class="form-control" rows="2" wire:model="formObservacoes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharFormItem">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="salvarItem">Salvar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- =================== MODAL IMPORTAÇÃO =================== --}}
    @if ($importModalAberto)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Importar Take Off — {{ $this->listaAtual?->codigo }}</h5>
                        <button type="button" class="btn-close" wire:click="fecharImportacao"></button>
                    </div>
                    <div class="modal-body">
                        @if (! $previaImportacao)
                            <p class="text-muted">A planilha precisa ter uma aba chamada <strong>TAKEOFF</strong> com as colunas: Código, Descrição, Unidade, Família, Disciplina, Quantidade, Observações. Todos os itens importados entram nesta lista (<strong>{{ $this->listaAtual?->codigo }}</strong>) — nunca em outra.</p>
                            <input type="file" class="form-control @error('arquivoImportacao') is-invalid @enderror" wire:model="arquivoImportacao">
                            @error('arquivoImportacao') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        @else
                            <div class="d-flex gap-3 mb-3">
                                <span class="badge bg-label-primary">Novos: {{ $previaImportacao['novos'] }}</span>
                                <span class="badge bg-label-info">Atualizados: {{ $previaImportacao['atualizados'] }}</span>
                                @if ($previaImportacao['ignoradas'] > 0)
                                    <span class="badge bg-label-warning">Ignoradas: {{ $previaImportacao['ignoradas'] }}</span>
                                @endif
                            </div>

                            @if (!empty($previaImportacao['avisos']))
                                <div class="alert alert-warning small">
                                    <ul class="mb-0">
                                        @foreach ($previaImportacao['avisos'] as $aviso)
                                            <li>{{ $aviso }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharImportacao">Cancelar</button>
                        @if (! $previaImportacao)
                            <button type="button" class="btn btn-primary" wire:click="analisarImportacao">Analisar planilha</button>
                        @else
                            <button type="button" class="btn btn-primary" wire:click="confirmarImportacao">Confirmar importação</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
