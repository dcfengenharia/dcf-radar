<?php

use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\InvalidarAceiteEntrega;
use App\Actions\Engenharia\RegistrarAceiteEntrega;
use App\Actions\Engenharia\RegistrarRecolhimento;
use App\Enums\ResultadoRecolhimento;
use App\Enums\StatusGrd;
use App\Enums\TipoAceiteGrd;
use App\Exceptions\GrdAceiteInvalidoException;
use App\Exceptions\GrdAceiteJaAtivoException;
use App\Exceptions\GrdAceiteJaInvalidadoException;
use App\Exceptions\GrdEmissaoInvalidaException;
use App\Exceptions\GrdImutavelException;
use App\Exceptions\GrdRecolhimentoInvalidoException;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdAceiteEntrega;
use App\Models\GrdDestinatario;
use App\Models\GrdDistribuicao;
use App\Models\GrdItem;
use App\Models\GrdRecolhimento;
use App\Models\Work;
use App\Support\Grd\CandidatosNovaEntregaGrd;
use App\Support\Grd\DetectorCopiasObsoletasGrd;
use App\Support\Grd\MontarDadosComprovanteEntrega;
use App\Support\Grd\MontarDadosComprovanteRecolhimento;
use App\Support\Grd\MontarDadosPdfGrd;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ciclo 18, Etapa 18.5.2 — UI operacional de GRD (distribuição física de
 * documentos). Reaproveita EXCLUSIVAMENTE o domínio já aprovado em 18.5.1/
 * 18.5.1.HARDENING (Actions/Queries) — nenhuma regra de negócio nova
 * aqui, só apresentação/autorização/resolução de IDs.
 *
 * Mesmo padrão de seletor de obra próprio de ⚡documentos-engenharia.blade.php
 * (`engenharia.pacotes` é ESCOPO_TENANT — página não trava na obra ativa
 * da sessão): `$obraId` público, sem middleware `obra.context`.
 */
new class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /**
     * Etapa 18.5.5 — `#[Url]` habilita o link das Notifications de
     * distribuição (`route('engenharia.grds', ['obra' => ..., 'aba' =>
     * ...])`) a abrir já na obra/aba corretas. `mount()` revalida
     * `ver` em `engenharia.pacotes` pra `obraId` vindo da URL — mesma
     * garantia já existente pra `grdAbertaId` logo abaixo: clicar num
     * link antigo depois de perder acesso à obra nunca deve escancarar
     * o dado (nunca confiar em query string sem reautorizar).
     */
    #[Url(as: 'obra')]
    public ?string $obraId = null;

    #[Url(as: 'aba')]
    public string $abaAtiva = 'grds';

    // ---- Listagem ----
    public string $buscaGrd = '';
    public ?string $statusFiltro = null;
    public ?string $destinatarioFiltroId = null;
    public string $documentoFiltro = '';
    public int $perPage = 15;

    // ---- GRD aberta (rascunho em edição OU emitida em detalhe) ----
    #[Url(as: 'grd')]
    public ?string $grdAbertaId = null;

    // ---- Modal adicionar documento ----
    public bool $modalAdicionarDocumentoAberto = false;
    public string $buscaDocumento = '';

    // ---- Modal adicionar/criar destinatário ----
    public bool $modalDestinatarioAberto = false;
    public string $buscaDestinatario = '';
    public bool $formNovoDestinatarioAberto = false;
    public string $novoDestinatarioNome = '';
    public string $novoDestinatarioEmpresa = '';
    public string $novoDestinatarioSetor = '';
    public string $novoDestinatarioEmail = '';
    public string $novoDestinatarioTelefone = '';

    // ---- Modal emitir ----
    public bool $modalEmitirAberto = false;
    public ?string $erroEmissao = null;

    // ---- Modal recolhimento ----
    public ?string $recolhimentoDistribuicaoId = null;
    public string $recolhimentoResultado = 'recolhido';
    public $recolhimentoQuantidade = 1;
    public string $recolhimentoData = '';
    public string $recolhimentoObservacao = '';
    public ?string $recolhimentoErro = null;

    // ---- Modal aceite de entrega (18.5.9) ----
    public ?string $aceiteDestinatarioId = null;
    public string $aceiteNomeRecebedor = '';
    public string $aceiteTipo = 'assinatura';
    public string $aceiteObservacao = '';
    public ?string $aceiteAssinaturaBase64 = null;
    public ?string $aceiteErro = null;

    // ---- Modal invalidar aceite (18.5.9) ----
    public ?string $aceiteInvalidarId = null;
    public string $aceiteMotivoInvalidacao = '';
    public ?string $aceiteInvalidarErro = null;

    // ---- Central Operacional de Distribuição (Etapa 18.5.4) — filtros em memória sobre as coleções já derivadas ----
    public string $buscaObsoletaDocumento = '';
    public ?string $destinatarioObsoletaFiltro = null;
    public ?string $estadoObsoletaFiltro = null;
    public string $buscaCandidatoDocumento = '';
    public ?string $destinatarioCandidatoFiltro = null;

    private const ABAS_VALIDAS = ['grds', 'obsoletas', 'candidatos'];

    public function mount(): void
    {
        // Etapa 18.5.5.HARDENING — `?aba=` chega de fora (deep-link de
        // Notification, ou digitado à mão) e nunca deve ser confiado
        // cru: um valor fora das 3 abas reais renderizava um painel
        // vazio sem nenhuma indicação do que aconteceu. Normaliza pro
        // default real da tela ('grds') — nunca um redirect, só o
        // valor caindo pro estado inicial de sempre.
        if (! in_array($this->abaAtiva, self::ABAS_VALIDAS, true)) {
            $this->abaAtiva = 'grds';
        }

        if ($this->obraId && ! Auth::user()?->temPermissaoNaObra($this->obraId, 'engenharia.pacotes', 'ver')) {
            $this->obraId = null;
            $this->grdAbertaId = null;
        }

        if ($this->grdAbertaId) {
            $grd = Grd::find($this->grdAbertaId);
            if ($grd && Auth::user()?->temPermissaoNaObra($grd->obra_id, 'engenharia.pacotes', 'ver')) {
                $this->obraId = $grd->obra_id;
            } else {
                $this->grdAbertaId = null;
            }
        }
    }

    // =========================================================================
    // AUTORIZAÇÃO / RESOLUÇÃO ESCOPADA — mesmo padrão de
    // ⚡documentos-engenharia.blade.php (garantirPermissaoNaObraAtual /
    // resolverDocumentoDaObraAtual)
    // =========================================================================

    private function garantirPermissaoNaObraAtual(string $acao): void
    {
        abort_unless(
            $this->obraId && Auth::user()?->temPermissaoNaObra($this->obraId, 'engenharia.pacotes', $acao),
            403
        );
    }

    private function resolverGrdDaObraAtual(?string $id): Grd
    {
        return Grd::where('obra_id', $this->obraId)->findOrFail($id);
    }

    private function resolverDestinatarioDaObraAtual(string $id): Destinatario
    {
        return Destinatario::where('obra_id', $this->obraId)->findOrFail($id);
    }

    private function resolverRevisaoDaObraAtual(string $id): DocumentoEngenhariaRevisao
    {
        return DocumentoEngenhariaRevisao::whereHas('documento', fn ($q) => $q->where('obra_id', $this->obraId))
            ->findOrFail($id);
    }

    // =========================================================================
    // COMPUTED
    // =========================================================================

    #[Computed]
    public function obras(): Collection
    {
        $usuario = Auth::user();

        return Work::orderBy('name')->get(['id', 'name'])
            ->filter(fn (Work $obra) => $usuario?->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'ver'))
            ->values();
    }

    #[Computed]
    public function destinatariosDaObra(): Collection
    {
        if (! $this->obraId) {
            return collect();
        }

        return Destinatario::where('obra_id', $this->obraId)->orderBy('nome')->get();
    }

    #[Computed]
    public function grds(): LengthAwarePaginator
    {
        if (! $this->obraId) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        return Grd::where('obra_id', $this->obraId)
            ->withCount(['itens', 'destinatarios', 'distribuicoes'])
            ->with(['criador', 'emitidoPor'])
            ->when($this->statusFiltro, fn ($q) => $q->where('status', $this->statusFiltro))
            ->when($this->buscaGrd !== '' && is_numeric($this->buscaGrd), fn ($q) => $q->where('numero', (int) $this->buscaGrd))
            ->when($this->destinatarioFiltroId, fn ($q) => $q->whereHas(
                'destinatarios',
                fn ($qq) => $qq->where('destinatario_id', $this->destinatarioFiltroId)
            ))
            ->when($this->documentoFiltro !== '', fn ($q) => $q->whereHas(
                'itens.revisao.documento',
                fn ($qq) => $qq->where('codigo', 'like', "%{$this->documentoFiltro}%")
            ))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function grdAberta(): ?Grd
    {
        if (! $this->obraId || ! $this->grdAbertaId) {
            return null;
        }

        return Grd::where('obra_id', $this->obraId)
            ->with([
                'itens.revisao.documento.latestRevisao',
                'itens.revisao.ultimaLiberacao',
                'destinatarios.destinatario',
                'criador', 'emitidoPor',
            ])
            ->find($this->grdAbertaId);
    }

    /** Matriz item×destinatário — chave 'itemId|grdDestinatarioId', recolhimentos já eager-carregados (sem N+1). */
    #[Computed]
    public function distribuicoesDaGrdAberta(): Collection
    {
        $grd = $this->grdAberta;
        if (! $grd) {
            return collect();
        }

        return GrdDistribuicao::whereIn('grd_item_id', $grd->itens->pluck('id'))
            ->with('recolhimentos.registradoPor')
            ->get()
            ->keyBy(fn (GrdDistribuicao $d) => $d->grd_item_id . '|' . $d->grd_destinatario_id);
    }

    /** Documento/revisão com problema (deixou de ser vigente, ou não liberada) — nunca remove o item automaticamente. */
    #[Computed]
    public function alertasPorItem(): array
    {
        $grd = $this->grdAberta;
        if (! $grd) {
            return [];
        }

        $alertas = [];
        foreach ($grd->itens as $item) {
            $documento = $item->revisao?->documento;
            if (! $documento) {
                continue;
            }
            $vigente = $documento->revisaoVigente();
            if ($vigente === null || $vigente->id !== $item->documento_engenharia_revisao_id) {
                $alertas[$item->id] = 'Este documento possui uma revisão vigente diferente da selecionada. Atualize o rascunho antes de emitir.';

                continue;
            }
            if (! $item->revisao->estaLiberadaParaConstrucao()) {
                $alertas[$item->id] = 'Esta revisão ainda não está liberada para construção.';
            }
        }

        return $alertas;
    }

    /**
     * Etapa 18.5.2.HARDENING (fecha B1) — mesmo padrão de
     * `destinatariosDisponiveisParaAdicionar()`: `limit()` explícito
     * (nunca carrega o catálogo inteiro da obra, mesmo sem busca) — antes
     * desta etapa não havia limite algum. Busca cobre código, descrição
     * e o texto da revisão (`orWhereHas('latestRevisao', ...)`), sempre
     * dentro do MESMO grupo de closure escopado por `where('obra_id',
     * ...)` — nenhum `orWhere` de nível superior, nunca escapa a obra
     * atual. `EmitirGrd` continua sendo a única fronteira real de
     * validação — a UI continua mostrando revisão vigente não liberada
     * com badge de alerta, nunca filtrando por liberação (decisão já
     * aprovada em 18.5.2, não alterada aqui).
     */
    #[Computed]
    public function revisoesDisponiveisParaAdicionar(): Collection
    {
        $grd = $this->grdAberta;
        if (! $grd) {
            return collect();
        }

        $jaAdicionadas = $grd->itens->pluck('documento_engenharia_revisao_id')->all();

        return DocumentoEngenharia::where('obra_id', $this->obraId)
            ->with('latestRevisao.ultimaLiberacao')
            ->when($this->buscaDocumento !== '', fn ($q) => $q->where(function ($qq) {
                $qq->where('codigo', 'like', "%{$this->buscaDocumento}%")
                    ->orWhere('descricao', 'like', "%{$this->buscaDocumento}%")
                    ->orWhereHas('latestRevisao', fn ($r) => $r->where('revisao', 'like', "%{$this->buscaDocumento}%"));
            }))
            ->orderBy('codigo')
            ->limit(30)
            ->get()
            ->filter(fn (DocumentoEngenharia $d) => $d->latestRevisao !== null)
            ->reject(fn (DocumentoEngenharia $d) => in_array($d->latestRevisao->id, $jaAdicionadas, true))
            ->values();
    }

    #[Computed]
    public function destinatariosDisponiveisParaAdicionar(): Collection
    {
        $grd = $this->grdAberta;
        if (! $grd) {
            return collect();
        }

        $jaAdicionados = $grd->destinatarios->pluck('destinatario_id')->all();

        return Destinatario::where('obra_id', $this->obraId)
            ->when($this->buscaDestinatario !== '', fn ($q) => $q->where('nome', 'like', "%{$this->buscaDestinatario}%"))
            ->when($jaAdicionados !== [], fn ($q) => $q->whereNotIn('id', $jaAdicionados))
            ->orderBy('nome')
            ->limit(30)
            ->get();
    }

    /**
     * Etapa 18.5.4 — "Central Operacional de Distribuição". Consome
     * DIRETAMENTE `DetectorCopiasObsoletasGrd::porObra()` (nunca
     * reimplementa a regra de obsolescência) e só filtra/ordena EM
     * MEMÓRIA sobre o resultado já derivado — os cards (`cardsObsoletas()`)
     * leem essa MESMA coleção, nunca uma contagem SQL separada, então
     * cards e lista nunca podem divergir (seção 19 do briefing).
     *
     * "Estado" do filtro usa `ultimo_resultado_recolhimento` (já
     * pré-computado em lote pelo Detector), nunca `GrdDistribuicao::
     * estado()` no model — aquele método precisa de `recolhimentos`
     * eager-carregado, o que o Detector não faz (não precisa pra sua
     * própria regra), então chamá-lo aqui geraria N+1/exceção de lazy
     * load. Mesma semântica de qualquer forma: como o Detector já garante
     * `quantidade_pendente > 0` pra toda linha, só resta distinguir
     * pendente/não-localizado — exatamente o que `estado()` faria.
     */
    #[Computed]
    public function obsoletas(): Collection
    {
        if (! $this->obraId || $this->abaAtiva !== 'obsoletas') {
            return collect();
        }

        $busca = trim(mb_strtolower($this->buscaObsoletaDocumento));

        return (new DetectorCopiasObsoletasGrd())->porObra(Work::findOrFail($this->obraId))
            ->when($busca !== '', fn ($c) => $c->filter(
                fn ($r) => str_contains(mb_strtolower($r->documento->codigo), $busca)
                    || str_contains(mb_strtolower($r->documento->descricao), $busca)
            ))
            ->when($this->destinatarioObsoletaFiltro, fn ($c) => $c->filter(
                fn ($r) => $r->grd_destinatario->destinatario_id === $this->destinatarioObsoletaFiltro
            ))
            ->when($this->estadoObsoletaFiltro, fn ($c) => $c->filter(
                fn ($r) => $this->estadoObsoletaRegistro($r) === $this->estadoObsoletaFiltro
            ))
            ->sortByDesc(fn ($r) => $r->quantidade_pendente)
            ->values();
    }

    /** Mesma semântica de GrdDistribuicao::estado() (pendente/não-localizado, nunca "recolhido" aqui — garantido pelo Detector), lida do campo já pré-computado — nunca chama ->estado() no model (evitaria N+1). */
    private function estadoObsoletaRegistro(object $registro): string
    {
        return $registro->ultimo_resultado_recolhimento === ResultadoRecolhimento::NaoLocalizado
            ? 'nao_localizado'
            : 'pendente';
    }

    #[Computed]
    public function cardsObsoletas(): array
    {
        $lista = $this->obsoletas;

        return [
            'copias_pendentes' => $lista->count(),
            'destinatarios' => $lista->pluck('grd_destinatario.destinatario_id')->unique()->count(),
            'documentos' => $lista->pluck('documento.id')->unique()->count(),
            'quantidade_total_pendente' => $lista->sum('quantidade_pendente'),
        ];
    }

    #[Computed]
    public function candidatos(): Collection
    {
        if (! $this->obraId || $this->abaAtiva !== 'candidatos') {
            return collect();
        }

        $busca = trim(mb_strtolower($this->buscaCandidatoDocumento));

        return (new CandidatosNovaEntregaGrd())->porObra(Work::findOrFail($this->obraId))
            ->when($busca !== '', fn ($c) => $c->filter(
                fn ($r) => str_contains(mb_strtolower($r->documento->codigo), $busca)
                    || str_contains(mb_strtolower($r->documento->descricao), $busca)
            ))
            ->when($this->destinatarioCandidatoFiltro, fn ($c) => $c->filter(
                fn ($r) => $r->destinatario->id === $this->destinatarioCandidatoFiltro
            ))
            ->sortBy(fn ($r) => $r->documento->codigo)
            ->values();
    }

    #[Computed]
    public function cardsCandidatos(): array
    {
        return [
            'destinatarios' => $this->candidatos->pluck('destinatario.id')->unique()->count(),
        ];
    }

    /**
     * Opções do `<select>` de filtro por destinatário — derivadas da
     * MESMA chamada ao serviço (não filtrada ainda), nunca de
     * `destinatariosDaObra()` (que só lista cadastro ATIVO): um
     * destinatário inativo pode ter cópia obsoleta em campo (snapshot
     * histórico) e precisa continuar filtrável mesmo sem cadastro ativo.
     */
    #[Computed]
    public function opcoesDestinatarioObsoletas(): Collection
    {
        if (! $this->obraId || $this->abaAtiva !== 'obsoletas') {
            return collect();
        }

        return (new DetectorCopiasObsoletasGrd())->porObra(Work::findOrFail($this->obraId))
            ->pluck('grd_destinatario')
            ->unique('destinatario_id')
            ->sortBy('nome_snapshot')
            ->values();
    }

    /** `CandidatosNovaEntregaGrd` já exclui destinatário inativo — nunca aparece aqui, coerente com a lista em si. */
    #[Computed]
    public function opcoesDestinatarioCandidatos(): Collection
    {
        if (! $this->obraId || $this->abaAtiva !== 'candidatos') {
            return collect();
        }

        return (new CandidatosNovaEntregaGrd())->porObra(Work::findOrFail($this->obraId))
            ->pluck('destinatario')
            ->unique('id')
            ->sortBy('nome')
            ->values();
    }

    #[Computed]
    public function distribuicaoEmRecolhimento(): ?GrdDistribuicao
    {
        if (! $this->recolhimentoDistribuicaoId || ! $this->obraId) {
            return null;
        }

        return GrdDistribuicao::whereHas('item.grd', fn ($q) => $q->where('obra_id', $this->obraId))
            ->with(['item.revisao.documento', 'grdDestinatario'])
            ->find($this->recolhimentoDistribuicaoId);
    }

    #[Computed]
    public function grdDestinatarioEmAceite(): ?GrdDestinatario
    {
        if (! $this->aceiteDestinatarioId || ! $this->obraId) {
            return null;
        }

        return GrdDestinatario::whereHas('grd', fn ($q) => $q->where('obra_id', $this->obraId))
            ->find($this->aceiteDestinatarioId);
    }

    #[Computed]
    public function aceiteEmInvalidacao(): ?GrdAceiteEntrega
    {
        if (! $this->aceiteInvalidarId || ! $this->obraId) {
            return null;
        }

        return GrdAceiteEntrega::whereHas('grdDestinatario.grd', fn ($q) => $q->where('obra_id', $this->obraId))
            ->with('grdDestinatario')
            ->find($this->aceiteInvalidarId);
    }

    /**
     * Aceites ATIVOS de todos os destinatários da GRD aberta, indexados por
     * `grd_destinatario_id` — 1 query pra toda a página (nunca N+1 por
     * destinatário na tabela/seção de status).
     *
     * @return \Illuminate\Support\Collection<string, GrdAceiteEntrega>
     */
    #[Computed]
    public function aceitesAtivosDaGrdAberta(): Collection
    {
        $grd = $this->grdAberta;
        if (! $grd || $grd->destinatarios->isEmpty()) {
            return collect();
        }

        return GrdAceiteEntrega::whereIn('grd_destinatario_id', $grd->destinatarios->pluck('id'))
            ->whereNull('invalidado_em')
            ->get()
            ->keyBy('grd_destinatario_id');
    }

    // =========================================================================
    // NAVEGAÇÃO / OBRA / ABAS
    // =========================================================================

    public function updatedObraId(): void
    {
        // Item A/B do briefing 18.5.2: 'ver' é o portão de LEITURA da
        // página inteira — nunca confiado só ao dropdown filtrado (que
        // já exclui obras sem acesso, mas um `set('obraId', ...)` direto
        // via Livewire, teste ou manipulação de payload, precisa do
        // mesmo guard server-side de qualquer mutação).
        if ($this->obraId) {
            $this->garantirPermissaoNaObraAtual('ver');
        }

        $this->grdAbertaId = null;
        $this->resetPage();
    }

    public function updatedBuscaGrd(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFiltro(): void
    {
        $this->resetPage();
    }

    public function updatedDestinatarioFiltroId(): void
    {
        $this->resetPage();
    }

    public function updatedDocumentoFiltro(): void
    {
        $this->resetPage();
    }

    public function setAba(string $aba): void
    {
        $this->abaAtiva = $aba;
        $this->grdAbertaId = null;
    }

    public function abrirGrd(string $id): void
    {
        $this->garantirPermissaoNaObraAtual('ver');
        $this->resolverGrdDaObraAtual($id); // 404 se não pertence à obra atual
        $this->grdAbertaId = $id;
    }

    public function fecharGrd(): void
    {
        $this->grdAbertaId = null;
    }

    // =========================================================================
    // CRIAR RASCUNHO
    // =========================================================================

    public function criarGrd(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');

        $obra = Work::findOrFail($this->obraId);
        $grd = (new CriarGrd())->execute($obra, Auth::user());

        $this->grdAbertaId = $grd->id;
        unset($this->grds);
        $this->dispatch('show-toast', message: 'Rascunho de GRD criado — número será atribuído na emissão.', type: 'success');
    }

    /**
     * Item 18 do briefing — atalho a partir de "Possíveis destinatários
     * da revisão vigente". `revisaoVigente()` é sempre resolvida FRESH
     * aqui (nunca uma revisão passada pelo estado da listagem) — se uma
     * revisão mais nova nascer entre a renderização da lista e o clique,
     * é ELA que entra no rascunho, nunca a stale que o usuário viu
     * (mesma garantia de segurança de `EmitirGrd`, que revalida de novo
     * na emissão de qualquer forma).
     *
     * Etapa 18.5.2.HARDENING (fecha D2): `$documento`/`$destinatario`
     * são resolvidos ANTES de qualquer escrita — se o destinatário foi
     * soft-deletado no intervalo entre a lista e o clique (ou o
     * documento deixou de existir na obra), `ModelNotFoundException` é
     * capturada aqui e vira toast didático, sem nunca criar Grd/item/
     * destinatário/distribuição parcial (nada é escrito antes deste
     * try/catch).
     */
    public function criarGrdAPartirDeCandidato(string $documentoId, string $destinatarioId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');

        try {
            $obra = Work::findOrFail($this->obraId);
            $documento = DocumentoEngenharia::where('obra_id', $this->obraId)->findOrFail($documentoId);
            $destinatario = $this->resolverDestinatarioDaObraAtual($destinatarioId);
        } catch (ModelNotFoundException $e) {
            $this->dispatch('show-toast', message: 'Este destinatário não está mais disponível. Atualize a lista e tente novamente.', type: 'error');
            unset($this->candidatos);

            return;
        }

        $revisaoVigente = $documento->revisaoVigente();

        if ($revisaoVigente === null) {
            $this->dispatch('show-toast', message: 'Este documento não possui revisão vigente.', type: 'error');

            return;
        }

        $grd = (new CriarGrd())->execute($obra, Auth::user());
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisaoVigente);
        $gd = $acoes->adicionarDestinatario($grd, $destinatario);
        $acoes->marcarDistribuicao($grd, $item, $gd, 1);

        $this->abaAtiva = 'grds';
        $this->grdAbertaId = $grd->id;
        unset($this->grds);
        $this->dispatch('show-toast', message: 'Rascunho criado com este documento e destinatário — revise e emita quando pronto.', type: 'success');
    }

    // =========================================================================
    // DOCUMENTOS DO RASCUNHO
    // =========================================================================

    public function abrirModalAdicionarDocumento(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $this->buscaDocumento = '';
        $this->modalAdicionarDocumentoAberto = true;
    }

    public function fecharModalAdicionarDocumento(): void
    {
        $this->modalAdicionarDocumentoAberto = false;
    }

    public function adicionarDocumento(string $revisaoId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);
        $revisao = $this->resolverRevisaoDaObraAtual($revisaoId);

        try {
            (new AtualizarRascunhoGrd())->adicionarItem($grd, $revisao);
            unset($this->grdAberta, $this->revisoesDisponiveisParaAdicionar, $this->distribuicoesDaGrdAberta, $this->alertasPorItem);
            $this->dispatch('show-toast', message: 'Documento adicionado ao rascunho.', type: 'success');
        } catch (GrdImutavelException|\InvalidArgumentException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function removerDocumento(string $itemId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);
        $item = GrdItem::where('grd_id', $grd->id)->findOrFail($itemId);

        try {
            (new AtualizarRascunhoGrd())->removerItem($grd, $item);
            unset($this->grdAberta, $this->revisoesDisponiveisParaAdicionar, $this->distribuicoesDaGrdAberta, $this->alertasPorItem);
            $this->dispatch('show-toast', message: 'Documento removido do rascunho.', type: 'success');
        } catch (GrdImutavelException|\InvalidArgumentException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    // =========================================================================
    // DESTINATÁRIOS DO RASCUNHO
    // =========================================================================

    public function abrirModalDestinatario(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $this->buscaDestinatario = '';
        $this->formNovoDestinatarioAberto = false;
        $this->modalDestinatarioAberto = true;
    }

    public function fecharModalDestinatario(): void
    {
        $this->modalDestinatarioAberto = false;
        $this->formNovoDestinatarioAberto = false;
    }

    public function adicionarDestinatarioExistente(string $destinatarioId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);
        $destinatario = $this->resolverDestinatarioDaObraAtual($destinatarioId);

        try {
            (new AtualizarRascunhoGrd())->adicionarDestinatario($grd, $destinatario);
            unset($this->grdAberta, $this->destinatariosDisponiveisParaAdicionar, $this->distribuicoesDaGrdAberta);
            $this->dispatch('show-toast', message: 'Destinatário adicionado ao rascunho.', type: 'success');
        } catch (GrdImutavelException|\InvalidArgumentException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function toggleFormNovoDestinatario(): void
    {
        $this->formNovoDestinatarioAberto = ! $this->formNovoDestinatarioAberto;
    }

    public function salvarNovoDestinatarioEAdicionar(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $this->validate([
            'novoDestinatarioNome' => 'required|string|max:255',
            'novoDestinatarioEmpresa' => 'nullable|string|max:255',
            'novoDestinatarioSetor' => 'nullable|string|max:255',
            'novoDestinatarioEmail' => 'nullable|email|max:255',
            'novoDestinatarioTelefone' => 'nullable|string|max:30',
        ]);

        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);

        $destinatario = Destinatario::create([
            'obra_id' => $this->obraId,
            'nome' => $this->novoDestinatarioNome,
            'empresa' => $this->novoDestinatarioEmpresa !== '' ? $this->novoDestinatarioEmpresa : null,
            'setor' => $this->novoDestinatarioSetor !== '' ? $this->novoDestinatarioSetor : null,
            'email' => $this->novoDestinatarioEmail !== '' ? $this->novoDestinatarioEmail : null,
            'telefone' => $this->novoDestinatarioTelefone !== '' ? $this->novoDestinatarioTelefone : null,
        ]);

        try {
            (new AtualizarRascunhoGrd())->adicionarDestinatario($grd, $destinatario);
            $this->reset(['novoDestinatarioNome', 'novoDestinatarioEmpresa', 'novoDestinatarioSetor', 'novoDestinatarioEmail', 'novoDestinatarioTelefone']);
            $this->formNovoDestinatarioAberto = false;
            unset($this->grdAberta, $this->destinatariosDaObra, $this->destinatariosDisponiveisParaAdicionar, $this->distribuicoesDaGrdAberta);
            $this->dispatch('show-toast', message: 'Destinatário criado e adicionado ao rascunho.', type: 'success');
        } catch (GrdImutavelException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function removerDestinatarioDaGrd(string $grdDestinatarioId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);
        $grdDest = GrdDestinatario::where('grd_id', $grd->id)->findOrFail($grdDestinatarioId);

        try {
            (new AtualizarRascunhoGrd())->removerDestinatario($grd, $grdDest);
            unset($this->grdAberta, $this->destinatariosDisponiveisParaAdicionar, $this->distribuicoesDaGrdAberta);
            $this->dispatch('show-toast', message: 'Destinatário removido do rascunho.', type: 'success');
        } catch (GrdImutavelException|\InvalidArgumentException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    // =========================================================================
    // MATRIZ DOCUMENTO × DESTINATÁRIO
    // =========================================================================

    public function marcarCelula(string $itemId, string $grdDestinatarioId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);
        $item = GrdItem::where('grd_id', $grd->id)->findOrFail($itemId);
        $grdDest = GrdDestinatario::where('grd_id', $grd->id)->findOrFail($grdDestinatarioId);

        try {
            (new AtualizarRascunhoGrd())->marcarDistribuicao($grd, $item, $grdDest, 1);
            unset($this->distribuicoesDaGrdAberta);
        } catch (GrdImutavelException|\InvalidArgumentException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function desmarcarCelula(string $itemId, string $grdDestinatarioId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);
        $item = GrdItem::where('grd_id', $grd->id)->findOrFail($itemId);
        $grdDest = GrdDestinatario::where('grd_id', $grd->id)->findOrFail($grdDestinatarioId);

        try {
            (new AtualizarRascunhoGrd())->desmarcarDistribuicao($grd, $item, $grdDest);
            unset($this->distribuicoesDaGrdAberta);
        } catch (GrdImutavelException|\InvalidArgumentException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function atualizarQuantidadeCelula(string $itemId, string $grdDestinatarioId, $quantidade): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $quantidade = (int) $quantidade;
        if ($quantidade < 1) {
            $this->dispatch('show-toast', message: 'Quantidade precisa ser maior ou igual a 1.', type: 'error');

            return;
        }

        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);
        $item = GrdItem::where('grd_id', $grd->id)->findOrFail($itemId);
        $grdDest = GrdDestinatario::where('grd_id', $grd->id)->findOrFail($grdDestinatarioId);
        $distribuicao = GrdDistribuicao::where('grd_item_id', $item->id)->where('grd_destinatario_id', $grdDest->id)->firstOrFail();

        try {
            (new AtualizarRascunhoGrd())->alterarQuantidade($grd, $distribuicao, $quantidade);
            unset($this->distribuicoesDaGrdAberta);
        } catch (GrdImutavelException|\InvalidArgumentException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function atualizarObservacaoRascunho(string $valor): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);

        try {
            (new AtualizarRascunhoGrd())->atualizarObservacao($grd, $valor !== '' ? $valor : null);
            unset($this->grdAberta);
        } catch (GrdImutavelException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    // =========================================================================
    // EMISSÃO
    // =========================================================================

    public function abrirModalEmitir(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $this->erroEmissao = null;
        $this->modalEmitirAberto = true;
    }

    public function fecharModalEmitir(): void
    {
        $this->modalEmitirAberto = false;
        $this->erroEmissao = null;
    }

    public function confirmarEmissao(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $grd = $this->resolverGrdDaObraAtual($this->grdAbertaId);

        try {
            (new EmitirGrd())->execute($grd, Auth::user());
            $this->modalEmitirAberto = false;
            $this->erroEmissao = null;
            unset($this->grdAberta, $this->grds, $this->alertasPorItem);
            $this->dispatch('show-toast', message: 'GRD emitida com sucesso.', type: 'success');
        } catch (GrdEmissaoInvalidaException|GrdImutavelException $e) {
            $this->erroEmissao = $e->getMessage();
        }
    }

    // =========================================================================
    // RECOLHIMENTO
    // =========================================================================

    public function abrirModalRecolhimento(string $distribuicaoId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $this->recolhimentoDistribuicaoId = $distribuicaoId;
        $this->recolhimentoResultado = 'recolhido';
        $this->recolhimentoQuantidade = 1;
        $this->recolhimentoData = now()->format('Y-m-d\TH:i');
        $this->recolhimentoObservacao = '';
        $this->recolhimentoErro = null;
    }

    public function fecharModalRecolhimento(): void
    {
        $this->recolhimentoDistribuicaoId = null;
        $this->recolhimentoErro = null;
    }

    public function confirmarRecolhimento(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');

        $distribuicao = GrdDistribuicao::whereHas('item.grd', fn ($q) => $q->where('obra_id', $this->obraId))
            ->findOrFail($this->recolhimentoDistribuicaoId);

        $quantidade = (int) $this->recolhimentoQuantidade;
        if ($quantidade < 1) {
            $this->recolhimentoErro = 'Quantidade precisa ser maior ou igual a 1.';

            return;
        }

        try {
            (new RegistrarRecolhimento())->execute(
                $distribuicao,
                ResultadoRecolhimento::from($this->recolhimentoResultado),
                $quantidade,
                Auth::user(),
                $this->recolhimentoObservacao !== '' ? $this->recolhimentoObservacao : null,
                $this->recolhimentoData !== '' ? Carbon::parse($this->recolhimentoData) : null
            );
            $this->recolhimentoDistribuicaoId = null;
            $this->recolhimentoErro = null;
            unset($this->grdAberta, $this->distribuicoesDaGrdAberta, $this->obsoletas);
            $this->dispatch('show-toast', message: 'Recolhimento registrado.', type: 'success');
        } catch (GrdRecolhimentoInvalidoException $e) {
            $this->recolhimentoErro = $e->getMessage();
        }
    }

    // =========================================================================
    // ACEITE DE ENTREGA — Etapa 18.5.9. Aceite/assinatura manuscrita
    // capturada em tela — NUNCA assinatura digital ICP-Brasil.
    // =========================================================================

    public function abrirModalAceite(string $grdDestinatarioId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        $gd = GrdDestinatario::whereHas('grd', fn ($q) => $q->where('obra_id', $this->obraId))->findOrFail($grdDestinatarioId);

        $this->aceiteDestinatarioId = $gd->id;
        $this->aceiteNomeRecebedor = $gd->nome_snapshot;
        $this->aceiteTipo = 'assinatura';
        $this->aceiteObservacao = '';
        $this->aceiteAssinaturaBase64 = null;
        $this->aceiteErro = null;
    }

    public function fecharModalAceite(): void
    {
        $this->aceiteDestinatarioId = null;
        $this->aceiteAssinaturaBase64 = null;
        $this->aceiteErro = null;
    }

    /**
     * `$assinaturaBase64` chega como argumento direto do JS do canvas
     * (`canvas.toDataURL('image/png')`) — nunca via upload de arquivo do
     * Livewire (que é pensado pra `<input type=file>`, não pra blob gerado
     * em canvas). Validação de formato/tamanho é feita inteiramente dentro
     * de `RegistrarAceiteEntrega` (nunca confia só no que o JS mandou).
     */
    public function confirmarAceite(?string $assinaturaBase64 = null): void
    {
        $this->garantirPermissaoNaObraAtual('editar');

        $gd = GrdDestinatario::whereHas('grd', fn ($q) => $q->where('obra_id', $this->obraId))
            ->findOrFail($this->aceiteDestinatarioId);

        try {
            (new RegistrarAceiteEntrega())->execute(
                $gd,
                TipoAceiteGrd::from($this->aceiteTipo),
                $this->aceiteNomeRecebedor,
                Auth::user(),
                $this->aceiteTipo === TipoAceiteGrd::Assinatura->value ? $assinaturaBase64 : null,
                $this->aceiteObservacao !== '' ? $this->aceiteObservacao : null,
            );

            $this->aceiteDestinatarioId = null;
            $this->aceiteAssinaturaBase64 = null;
            $this->aceiteErro = null;
            unset($this->grdAberta, $this->aceitesAtivosDaGrdAberta);
            $this->dispatch('show-toast', message: 'Aceite de recebimento registrado.', type: 'success');
        } catch (GrdAceiteInvalidoException|GrdAceiteJaAtivoException $e) {
            $this->aceiteErro = $e->getMessage();
        }
    }

    public function abrirModalInvalidarAceite(string $aceiteId): void
    {
        $this->garantirPermissaoNaObraAtual('editar');
        GrdAceiteEntrega::whereHas('grdDestinatario.grd', fn ($q) => $q->where('obra_id', $this->obraId))->findOrFail($aceiteId);

        $this->aceiteInvalidarId = $aceiteId;
        $this->aceiteMotivoInvalidacao = '';
        $this->aceiteInvalidarErro = null;
    }

    public function fecharModalInvalidarAceite(): void
    {
        $this->aceiteInvalidarId = null;
        $this->aceiteInvalidarErro = null;
    }

    public function confirmarInvalidarAceite(): void
    {
        $this->garantirPermissaoNaObraAtual('editar');

        $aceite = GrdAceiteEntrega::whereHas('grdDestinatario.grd', fn ($q) => $q->where('obra_id', $this->obraId))
            ->findOrFail($this->aceiteInvalidarId);

        if (trim($this->aceiteMotivoInvalidacao) === '') {
            $this->aceiteInvalidarErro = 'O motivo da invalidação é obrigatório.';

            return;
        }

        try {
            (new InvalidarAceiteEntrega())->execute($aceite, $this->aceiteMotivoInvalidacao, Auth::user());

            $this->aceiteInvalidarId = null;
            $this->aceiteInvalidarErro = null;
            unset($this->grdAberta, $this->aceitesAtivosDaGrdAberta);
            $this->dispatch('show-toast', message: 'Aceite invalidado.', type: 'success');
        } catch (GrdAceiteJaInvalidadoException $e) {
            $this->aceiteInvalidarErro = $e->getMessage();
        }
    }

    // =========================================================================
    // PDF / IMPRESSÃO — Etapa 18.5.3
    // =========================================================================

    /**
     * Só GRD Emitida tem PDF formal (Rascunho nunca teve conteúdo
     * congelado — não existe "histórico" pra imprimir ainda).
     * `MontarDadosPdfGrd` reconstrói tudo a partir de snapshots/fatos já
     * congelados na emissão — nunca de `revisaoVigente()`/`Destinatario`
     * ao vivo. Mesmo padrão de PDF já usado em todo o projeto
     * (`Pdf::loadView()` + `response()->streamDownload()`, ex.:
     * `⚡central-prontidao.blade.php::exportarPdf()`), nenhuma lib nova.
     */
    public function exportarPdfGrd(string $grdId): mixed
    {
        $this->garantirPermissaoNaObraAtual('ver');
        $grd = $this->resolverGrdDaObraAtual($grdId);

        abort_unless($grd->estaEmitida(), 404);

        $dados = (new MontarDadosPdfGrd())->paraGrd($grd);
        $pdf = Pdf::loadView('exports.grd-pdf', $dados);

        return response()->streamDownload(
            fn () => print $pdf->output(),
            "grd-{$grd->numero}.pdf"
        );
    }

    // =========================================================================
    // COMPROVANTES — Etapa 18.5.8. Diferente do PDF histórico da GRD
    // (18.5.3, "o que foi emitido/distribuído no todo"), cada comprovante
    // responde por UM evento operacional específico (1 entrega a 1
    // destinatário, ou 1 evento de recolhimento) — geração 100% sob
    // demanda a partir de fatos/snapshots já imutáveis, sem nenhuma
    // entidade/migration nova (ver docblocks de MontarDadosComprovante*).
    // =========================================================================

    private function resolverGrdDestinatarioDaObraAtual(string $id): GrdDestinatario
    {
        return GrdDestinatario::whereHas('grd', fn ($q) => $q->where('obra_id', $this->obraId))->findOrFail($id);
    }

    private function resolverRecolhimentoDaObraAtual(string $id): GrdRecolhimento
    {
        return GrdRecolhimento::whereHas('distribuicao.item.grd', fn ($q) => $q->where('obra_id', $this->obraId))->findOrFail($id);
    }

    /**
     * Comprovante de Entrega — só GRD Emitida (Rascunho nunca teve o fato
     * de entrega congelado; ver docblock de `MontarDadosComprovanteEntrega`
     * sobre por que "emitida" já é "entregue" neste domínio).
     */
    public function exportarComprovanteEntrega(string $grdDestinatarioId): mixed
    {
        $this->garantirPermissaoNaObraAtual('ver');
        $grdDestinatario = $this->resolverGrdDestinatarioDaObraAtual($grdDestinatarioId);

        abort_unless($grdDestinatario->grd->estaEmitida(), 404);

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($grdDestinatario);
        $pdf = Pdf::loadView('exports.grd-comprovante-entrega-pdf', $dados);

        $nomeArquivo = Str::slug("comprovante-entrega-grd-{$dados['grd']->numero}-{$grdDestinatario->nome_snapshot}") . '.pdf';

        return response()->streamDownload(fn () => print $pdf->output(), $nomeArquivo);
    }

    /**
     * Comprovante de Recolhimento — reproduz EXATAMENTE 1 evento
     * (`GrdRecolhimento`, append-only), nunca o estado atual derivado da
     * distribuição. Nenhum `abort_unless(estaEmitida())` aqui: um
     * `GrdRecolhimento` só pode existir sobre uma distribuição de uma GRD
     * já Emitida (`RegistrarRecolhimento` já bloqueia isso no domínio,
     * `GrdRecolhimentoInvalidoException` numa GRD Rascunho) — a checagem
     * seria sempre verdadeira, código morto.
     */
    public function exportarComprovanteRecolhimento(string $recolhimentoId): mixed
    {
        $this->garantirPermissaoNaObraAtual('ver');
        $evento = $this->resolverRecolhimentoDaObraAtual($recolhimentoId);

        $dados = (new MontarDadosComprovanteRecolhimento())->paraEvento($evento);
        $pdf = Pdf::loadView('exports.grd-comprovante-recolhimento-pdf', $dados);

        $nomeArquivo = Str::slug("comprovante-recolhimento-grd-{$dados['grd']->numero}-{$evento->ocorrido_em->format('Y-m-d-Hi')}") . '.pdf';

        return response()->streamDownload(fn () => print $pdf->output(), $nomeArquivo);
    }
}; ?>

<div>
    <div class="row align-items-end g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label mb-1">Obra</label>
            <select class="form-select" wire:model.live="obraId">
                <option value="">— Selecione uma obra —</option>
                @foreach ($this->obras as $obra)
                    <option value="{{ $obra->id }}">{{ $obra->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if (! $obraId)
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="bx bx-buildings fs-1 d-block mb-2"></i>
                Selecione uma obra acima para ver as GRDs.
            </div>
        </div>
    @elseif ($this->grdAberta)
        @include('pages.engenharia._partials.grd-detalhe')
    @else
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <button class="nav-link {{ $abaAtiva === 'grds' ? 'active' : '' }}" wire:click="setAba('grds')" type="button">
                    <i class="bx bx-list-ul me-1"></i>GRDs
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link {{ $abaAtiva === 'obsoletas' ? 'active' : '' }}" wire:click="setAba('obsoletas')" type="button">
                    <i class="bx bx-error-circle me-1"></i>Cópias obsoletas em campo
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link {{ $abaAtiva === 'candidatos' ? 'active' : '' }}" wire:click="setAba('candidatos')" type="button">
                    <i class="bx bx-send me-1"></i>Possíveis destinatários da revisão vigente
                </button>
            </li>
        </ul>

        @if ($abaAtiva === 'grds')
            @include('pages.engenharia._partials.grd-listagem')
        @elseif ($abaAtiva === 'obsoletas')
            @include('pages.engenharia._partials.grd-obsoletas')
        @else
            @include('pages.engenharia._partials.grd-candidatos')
        @endif
    @endif

    @if ($obraId && $recolhimentoDistribuicaoId)
        @include('pages.engenharia._partials.grd-recolhimento-modal')
    @endif

    @if ($obraId && $aceiteDestinatarioId)
        @include('pages.engenharia._partials.grd-aceite-modal')
    @endif

    @if ($obraId && $aceiteInvalidarId)
        @include('pages.engenharia._partials.grd-invalidar-aceite-modal')
    @endif
</div>
