<?php

use App\Actions\LicoesAprendidas\AnexarEvidenciaALicao;
use App\Actions\LicoesAprendidas\ArquivarLicaoAprendida;
use App\Actions\LicoesAprendidas\AtualizarLicaoAprendida;
use App\Actions\LicoesAprendidas\CriarLicaoAprendida;
use App\Actions\LicoesAprendidas\ConverterCandidatoEmLicao;
use App\Actions\LicoesAprendidas\CriarLicaoComOrigem;
use App\Actions\LicoesAprendidas\DescartarCandidatoLicaoAprendida;
use App\Actions\LicoesAprendidas\DevolverLicaoParaRascunho;
use App\Actions\LicoesAprendidas\EnviarLicaoParaValidacao;
use App\Actions\LicoesAprendidas\PrepararContextoNovaLicao;
use App\Actions\LicoesAprendidas\PublicarLicaoAprendida;
use App\Actions\LicoesAprendidas\RemoverEvidenciaDaLicao;
use App\Actions\LicoesAprendidas\RemoverVinculoDaLicao;
use App\Actions\LicoesAprendidas\VincularEntidadeALicao;
use App\DTOs\LicoesAprendidas\ContextoNovaLicao;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\StatusCandidatoLicaoAprendida;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoCandidatoLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Exceptions\CandidatoLicaoAprendidaJaTratadoException;
use App\Exceptions\LicaoAprendidaIncompletaException;
use App\Exceptions\LicaoAprendidaImutavelException;
use App\Exceptions\LicaoAprendidaTransicaoInvalidaException;
use App\Exceptions\VinculoLicaoInvalidoException;
use App\Models\Atividade;
use App\Models\CandidatoLicaoAprendida;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaEvidencia;
use App\Models\LicaoAprendidaVinculo;
use App\Models\Material;
use App\Models\Restricao;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use App\Support\LicoesAprendidas\GerarCandidatosLicoesObra;
use App\Support\LicoesAprendidas\InteligenciaLicoesQuery;
use App\Support\LicoesAprendidas\VinculoLicaoResolver;
use App\Support\ObraContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Ciclo 23, Etapa 23.1 — biblioteca corporativa de Lições Aprendidas.
 * ESCOPO_TENANT (mesmo padrão de gestao.benchmarking/engenharia.pacotes)
 * — seletor de obra próprio, nunca depende de `obra.context`.
 *
 * Ciclo 23, Etapa 23.2 — link vivo (Seção 13, corrige a decisão da 23.1
 * acima): `VinculoLicaoResolver::usuarioTemAcessoIndependente()` decide,
 * POR USUÁRIO e por REQUISIÇÃO, se o vínculo vira link clicável — o
 * `titulo_snapshot` continua SEMPRE exibido (nunca escondido), só o
 * link em cima dele é condicional. Nunca concede acesso à entidade só
 * por causa da lição (a checagem é a MESMA que a tela de origem daquele
 * tipo já usa pra decidir se o item aparece nela).
 *
 * Captura contextual (Seção 20/32/33): `?origem_tipo=&origem_id=` na URL
 * (mais `?origem_obra=` quando a origem não tem obra própria, ex.:
 * Material) faz `mount()` pré-preencher e abrir o MESMO modal de criação
 * já existente — nunca um segundo formulário. Nada é persistido até o
 * clique explícito em "Salvar Rascunho".
 */
new class extends Component {
    use WithFileUploads, WithPagination, ExecutaComTransacaoSegura, \App\Support\Concerns\LidaComReaplicacaoLicao;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'modo')]
    public string $modoVisualizacao = 'esta_obra';

    #[Url(as: 'origem_tipo')]
    public string $origemTipoQuery = '';

    #[Url(as: 'origem_id')]
    public string $origemIdQuery = '';

    #[Url(as: 'origem_obra')]
    public string $origemObraQuery = '';

    public string $busca = '';
    public string $statusFiltro = '';
    public string $tipoFiltro = '';
    public string $criticidadeFiltro = '';
    public string $areaFiltro = '';
    public string $disciplinaFiltroId = '';
    public string $obraFiltroId = '';
    public string $dataDe = '';
    public string $dataAte = '';

    /** Ciclo 23, Etapa 23.5.A (Decisão 8) — único filtro novo, criado só para o drill-down "Material X → lições vinculadas". */
    public string $materialFiltroId = '';
    public string $materialFiltroTitulo = '';

    public ?string $licaoAbertaId = null;
    public bool $modalFormAberto = false;
    public ?string $editandoId = null;

    public string $formObraId = '';
    public string $formDisciplinaId = '';
    public string $formTitulo = '';
    public string $formSituacao = '';
    public string $formCausa = '';
    public string $formImpacto = '';
    public string $formAcao = '';
    public string $formResultado = '';
    public string $formRecomendacao = '';
    public string $formTipo = '';
    public string $formCriticidade = '';
    public string $formArea = '';
    public string $formDataOcorrencia = '';
    public string $formDataOcorrenciaFim = '';
    public string $formObservacoesInternas = '';

    public string $vinculoTipo = '';
    public string $vinculoBusca = '';

    /** Ciclo 23, Etapa 23.2 — estado da captura contextual, vive só em memória (Seção 20/33). */
    public ?string $contextoOrigemTipo = null;
    public ?string $contextoOrigemId = null;
    public string $contextoOrigemTitulo = '';
    public bool $contextoObraDeterministica = false;

    /** @var array<int, array{tipo: string, id: string, titulo: string}> */
    public array $contextoVinculosComplementares = [];

    public $novaEvidenciaArquivo = null;

    public ?string $erro = null;

    // =========================================================================
    // REVISÃO DA OBRA (Ciclo 23, Etapa 23.3)
    // =========================================================================

    #[Url(as: 'aba')]
    public string $abaAtiva = 'biblioteca';

    public string $candidatoStatusFiltro = 'pendente';
    public string $candidatoTipoFiltro = '';
    public string $candidatoDataDe = '';
    public string $candidatoDataAte = '';

    public ?string $candidatoDescartandoId = null;
    public string $motivoDescarte = '';

    /** Preenchido quando o modal de criação foi aberto A PARTIR de um candidato — item 22/23. */
    public ?string $candidatoConvertendoId = null;

    public ?string $erroCandidatos = null;

    // =========================================================================
    // REAPLICAÇÃO (Ciclo 23, Etapa 23.5.B) — obra-select próprio da
    // Biblioteca (Seção 21); avaliar/registrar em si vêm do trait
    // LidaComReaplicacaoLicao, compartilhado com Lookahead/Estoque.
    // =========================================================================

    public bool $modalReaplicarAberto = false;
    public ?string $reaplicarLicaoId = null;
    public string $reaplicarObraId = '';
    public string $reaplicarObservacao = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('viewAny', LicaoAprendida::class), 403);

        if (! ObraContext::current()) {
            $this->modoVisualizacao = 'todas_obras';
        }

        if ($this->origemTipoQuery !== '' && $this->origemIdQuery !== '') {
            $this->prepararCapturaContextual();
        }
    }

    /**
     * Ciclo 23, Etapa 23.2 (Seção 4/20/38) — ponto de entrada único da
     * captura contextual: resolve o contexto real (nunca confia no
     * texto da URL como prova de nada) e, se autorizado, abre o MESMO
     * modal de criação já usado pelo botão "Nova Lição Aprendida"
     * (Seção 32/33 — nunca um segundo formulário). Falha de resolução
     * (entidade removida, sem acesso, tipo inválido) nunca quebra a
     * página — só mostra um aviso amigável e deixa o fluxo normal
     * (botão manual) disponível.
     */
    private function prepararCapturaContextual(): void
    {
        $tipo = TipoEntidadeVinculoLicao::tryFrom($this->origemTipoQuery);
        if (! $tipo) {
            $this->erro = 'O tipo de origem informado não é válido.';
            return;
        }

        $obraContextoFallback = $this->origemObraQuery !== '' ? Work::find($this->origemObraQuery) : null;

        try {
            $contexto = app(PrepararContextoNovaLicao::class)
                ->execute(Auth::user(), $tipo, $this->origemIdQuery, $obraContextoFallback);
        } catch (VinculoLicaoInvalidoException $e) {
            $this->erro = $e->getMessage();
            return;
        }

        $this->abrirCriarComContexto($contexto);
    }

    private function abrirCriarComContexto(ContextoNovaLicao $contexto): void
    {
        if ($contexto->obraDeterministica) {
            if (! $contexto->obraSugerida || ! Auth::user()->can('create', [LicaoAprendida::class, $contexto->obraSugerida])) {
                $this->erro = 'Você não tem permissão para criar lições aprendidas na obra desta entidade.';
                $this->candidatoConvertendoId = null;
                return;
            }
        } elseif ($this->obrasComAcessoParaCriar->isEmpty()) {
            $this->erro = 'Você não tem permissão para criar lições aprendidas em nenhuma obra.';
            $this->candidatoConvertendoId = null;
            return;
        }

        $this->reset(['editandoId', 'formObraId', 'formDisciplinaId', 'formTitulo', 'formSituacao', 'formCausa', 'formImpacto', 'formAcao', 'formResultado', 'formRecomendacao', 'formTipo', 'formCriticidade', 'formArea', 'formDataOcorrencia', 'formDataOcorrenciaFim', 'formObservacoesInternas']);
        $this->erro = null;

        $this->contextoOrigemTipo = $contexto->tipoOrigem->value;
        $this->contextoOrigemId = $contexto->entidadeOrigemId;
        $this->contextoOrigemTitulo = $contexto->tituloOrigemSnapshot;
        $this->contextoObraDeterministica = $contexto->obraDeterministica;
        $this->contextoVinculosComplementares = array_map(
            fn (array $v) => ['tipo' => $v['tipo']->value, 'id' => $v['id'], 'titulo' => $v['titulo']],
            $contexto->vinculosComplementares
        );

        $this->formTitulo = $contexto->tituloSugerido;
        $this->formArea = $contexto->areaSugerida->value;
        $this->formDisciplinaId = $contexto->disciplinaIdSugerida ?? '';

        if ($contexto->obraDeterministica && $contexto->obraSugerida) {
            $this->formObraId = $contexto->obraSugerida->id;
        }

        $this->modalFormAberto = true;
    }

    private function limparContextoOrigem(): void
    {
        $this->contextoOrigemTipo = null;
        $this->contextoOrigemId = null;
        $this->contextoOrigemTitulo = '';
        $this->contextoObraDeterministica = false;
        $this->contextoVinculosComplementares = [];
        // Seção 22 (23.3): abrir/cancelar nunca altera o candidato — só
        // salvar com sucesso marca Convertido (ver salvarForm()).
        $this->candidatoConvertendoId = null;
    }

    // =========================================================================
    // DADOS DE REFERÊNCIA
    // =========================================================================

    #[Computed]
    public function obraAtual(): ?Work
    {
        return ObraContext::current();
    }

    #[Computed]
    public function obrasComAcessoParaCriar()
    {
        return Work::orderBy('name')->get()
            ->filter(fn (Work $obra) => Auth::user()->can('create', [LicaoAprendida::class, $obra]))
            ->values();
    }

    #[Computed]
    public function todasAsObrasDoTenant()
    {
        return Work::orderBy('name')->get();
    }

    #[Computed]
    public function disciplinas()
    {
        return Disciplina::orderBy('nome')->get();
    }

    // =========================================================================
    // LISTAGEM E FILTROS
    // =========================================================================

    public function updatedModoVisualizacao(): void
    {
        $this->resetPage();
    }

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function limparFiltros(): void
    {
        $this->reset(['busca', 'statusFiltro', 'tipoFiltro', 'criticidadeFiltro', 'areaFiltro', 'disciplinaFiltroId', 'obraFiltroId', 'dataDe', 'dataAte', 'materialFiltroId', 'materialFiltroTitulo']);
        $this->resetPage();
    }

    /**
     * Escopo de visibilidade — espelha `LicaoAprendidaPolicy::view()` em
     * forma agregada de query: "Esta obra" mostra tudo da obra ativa
     * (usuário já tem `ver` ali, checado no mount); "Todas as obras"
     * mostra Publicada de QUALQUER obra do tenant + qualquer status das
     * obras onde o usuário tem acesso direto — nunca rascunho de obra
     * sem acesso.
     */
    #[Computed]
    public function licoes()
    {
        $query = LicaoAprendida::query()->with(['obraOrigem', 'disciplina', 'criadoPor']);

        if ($this->modoVisualizacao === 'esta_obra') {
            $obra = $this->obraAtual;
            if (! $obra) {
                return LicaoAprendida::whereRaw('1 = 0')->paginate(10);
            }
            $query->where('obra_origem_id', $obra->id);
        } else {
            $obraIdsComAcesso = $this->todasAsObrasDoTenant
                ->filter(fn (Work $obra) => Auth::user()->temPermissaoNaObra($obra->id, 'gestao.licoes-aprendidas', 'ver'))
                ->pluck('id');

            $query->where(function ($q) use ($obraIdsComAcesso) {
                $q->where('status', StatusLicaoAprendida::Publicada->value)
                    ->orWhereIn('obra_origem_id', $obraIdsComAcesso);
            });

            if ($this->obraFiltroId !== '') {
                $query->where('obra_origem_id', $this->obraFiltroId);
            }
        }

        if ($this->busca !== '') {
            $termo = '%'.$this->busca.'%';
            $query->where(function ($q) use ($termo) {
                $q->where('titulo', 'like', $termo)
                    ->orWhere('situacao_observada', 'like', $termo)
                    ->orWhere('causa', 'like', $termo)
                    ->orWhere('recomendacao_futura', 'like', $termo);
            });
        }

        if ($this->statusFiltro !== '') {
            $query->where('status', $this->statusFiltro);
        } else {
            // Padrão: arquivada nunca aparece na consulta operacional
            // padrão (item 10/18 do pedido) — só quando filtrada
            // explicitamente.
            $query->where('status', '!=', StatusLicaoAprendida::Arquivada->value);
        }

        if ($this->tipoFiltro !== '') {
            $query->where('tipo', $this->tipoFiltro);
        }

        if ($this->criticidadeFiltro !== '') {
            $query->where('criticidade', $this->criticidadeFiltro);
        }

        if ($this->areaFiltro !== '') {
            $query->where('area_funcional', $this->areaFiltro);
        }

        if ($this->disciplinaFiltroId !== '') {
            $query->where('disciplina_id', $this->disciplinaFiltroId);
        }

        if ($this->dataDe !== '') {
            $query->whereDate('data_ocorrencia', '>=', $this->dataDe);
        }

        if ($this->dataAte !== '') {
            $query->whereDate('data_ocorrencia', '<=', $this->dataAte);
        }

        // Ciclo 23, Etapa 23.5.A (Decisão 8) — drill-down "Material X → ver
        // lições publicadas vinculadas ao Material X", único filtro novo
        // desta etapa. `whereHas` já herda o global scope de tenant de
        // `LicaoAprendidaVinculo` de graça (BelongsToTenant).
        if ($this->materialFiltroId !== '') {
            $query->whereHas('vinculos', fn ($q) => $q
                ->where('entidade_tipo', TipoEntidadeVinculoLicao::Material->value)
                ->where('entidade_id', $this->materialFiltroId));
        }

        return $query->orderByDesc('created_at')->paginate(10);
    }

    // =========================================================================
    // CRIAR / EDITAR
    // =========================================================================

    public function abrirCriar(): void
    {
        $this->reset(['editandoId', 'formObraId', 'formDisciplinaId', 'formTitulo', 'formSituacao', 'formCausa', 'formImpacto', 'formAcao', 'formResultado', 'formRecomendacao', 'formTipo', 'formCriticidade', 'formArea', 'formDataOcorrencia', 'formDataOcorrenciaFim', 'formObservacoesInternas', 'erro']);
        // Seção 31: a captura contextual é um atalho, não uma obrigação —
        // o botão simples continua abrindo um formulário 100% em branco,
        // mesmo que a página tenha chegado com um contexto na URL antes.
        $this->limparContextoOrigem();

        if ($this->obraAtual && Auth::user()->can('create', [LicaoAprendida::class, $this->obraAtual])) {
            $this->formObraId = $this->obraAtual->id;
        }

        $this->modalFormAberto = true;
    }

    public function abrirEditar(string $id): void
    {
        $licao = $this->licaoDaObraAcessivel($id);
        if (! $licao || ! Auth::user()->can('update', $licao)) {
            $this->erro = 'Você não tem permissão para editar esta lição.';
            return;
        }

        $this->limparContextoOrigem();

        $this->editandoId = $licao->id;
        $this->formObraId = $licao->obra_origem_id;
        $this->formDisciplinaId = (string) $licao->disciplina_id;
        $this->formTitulo = $licao->titulo;
        $this->formSituacao = $licao->situacao_observada;
        $this->formCausa = (string) $licao->causa;
        $this->formImpacto = (string) $licao->impacto;
        $this->formAcao = (string) $licao->acao_adotada;
        $this->formResultado = (string) $licao->resultado;
        $this->formRecomendacao = (string) $licao->recomendacao_futura;
        $this->formTipo = $licao->tipo->value;
        $this->formCriticidade = $licao->criticidade->value;
        $this->formArea = $licao->area_funcional->value;
        $this->formDataOcorrencia = $licao->data_ocorrencia?->toDateString() ?? '';
        $this->formDataOcorrenciaFim = $licao->data_ocorrencia_fim?->toDateString() ?? '';
        $this->formObservacoesInternas = (string) $licao->observacoes_internas;
        $this->erro = null;
        $this->modalFormAberto = true;
    }

    public function fecharModalForm(): void
    {
        // Seção 21: cancelar nunca persiste nada — só fecha o modal e
        // descarta qualquer contexto em memória.
        $this->modalFormAberto = false;
        $this->limparContextoOrigem();
    }

    private function regrasValidacaoForm(): array
    {
        return [
            'formObraId' => 'required|string',
            'formTitulo' => 'required|string|max:255',
            'formSituacao' => 'required|string',
            'formRecomendacao' => 'required|string',
            'formTipo' => ['required', \Illuminate\Validation\Rule::in(array_column(TipoLicaoAprendida::cases(), 'value'))],
            'formCriticidade' => ['required', \Illuminate\Validation\Rule::in(array_column(CriticidadeLicao::cases(), 'value'))],
            'formArea' => ['required', \Illuminate\Validation\Rule::in(array_column(AreaFuncionalLicao::cases(), 'value'))],
        ];
    }

    public function salvarForm(): void
    {
        $this->validate($this->regrasValidacaoForm());

        $dados = [
            'disciplina_id' => $this->formDisciplinaId !== '' ? $this->formDisciplinaId : null,
            'titulo' => $this->formTitulo,
            'situacao_observada' => $this->formSituacao,
            'causa' => $this->formCausa !== '' ? $this->formCausa : null,
            'impacto' => $this->formImpacto !== '' ? $this->formImpacto : null,
            'acao_adotada' => $this->formAcao !== '' ? $this->formAcao : null,
            'resultado' => $this->formResultado !== '' ? $this->formResultado : null,
            'recomendacao_futura' => $this->formRecomendacao,
            'tipo' => $this->formTipo,
            'criticidade' => $this->formCriticidade,
            'area_funcional' => $this->formArea,
            'data_ocorrencia' => $this->formDataOcorrencia !== '' ? $this->formDataOcorrencia : null,
            'data_ocorrencia_fim' => $this->formDataOcorrenciaFim !== '' ? $this->formDataOcorrenciaFim : null,
            'observacoes_internas' => $this->formObservacoesInternas !== '' ? $this->formObservacoesInternas : null,
        ];

        $this->transacaoSegura(function () use ($dados) {
            if ($this->editandoId) {
                $licao = $this->licaoDaObraAcessivel($this->editandoId);
                abort_unless($licao && Auth::user()->can('update', $licao), 403);

                app(AtualizarLicaoAprendida::class)->execute($licao, $dados);
            } elseif ($this->contextoOrigemTipo && $this->contextoOrigemId) {
                // Ciclo 23, Etapa 23.2 (Seção 16/22/38) — revalida o
                // contexto TODO DE NOVO no momento de salvar, nunca
                // confia no que foi resolvido quando o modal abriu (a
                // entidade pode ter sido removida/mudado de obra nesse
                // meio-tempo) — e cria lição+vínculo de origem+vínculos
                // complementares numa ÚNICA transação (Seção 22/48).
                $tipo = TipoEntidadeVinculoLicao::tryFrom($this->contextoOrigemTipo);
                abort_unless($tipo, 422);

                $obraContextoFallback = $this->origemObraQuery !== '' ? Work::find($this->origemObraQuery) : null;
                $contexto = app(PrepararContextoNovaLicao::class)
                    ->execute(Auth::user(), $tipo, $this->contextoOrigemId, $obraContextoFallback);

                $obraEscolhidaPeloUsuario = null;
                if (! $contexto->obraDeterministica) {
                    $obraEscolhidaPeloUsuario = Work::find($this->formObraId);
                    abort_unless(
                        $obraEscolhidaPeloUsuario && Auth::user()->can('create', [LicaoAprendida::class, $obraEscolhidaPeloUsuario]),
                        403
                    );
                } else {
                    abort_unless(
                        $contexto->obraSugerida && Auth::user()->can('create', [LicaoAprendida::class, $contexto->obraSugerida]),
                        403
                    );
                }

                // Ciclo 23, Etapa 23.3 (Seção 22/23/24) — quando o modal foi
                // aberto a partir de um candidato, a criação da lição e a
                // transição do candidato pra Convertido são atomicamente
                // consistentes dentro de UMA transação
                // (`ConverterCandidatoEmLicao`) — nunca uma lição órfã, nunca
                // duas lições pro mesmo candidato.
                if ($this->candidatoConvertendoId) {
                    $candidato = CandidatoLicaoAprendida::find($this->candidatoConvertendoId);
                    abort_unless(
                        $candidato && $candidato->estaPendente() && Auth::user()->can('converterCandidato', $candidato),
                        403
                    );

                    app(ConverterCandidatoEmLicao::class)->execute($candidato, $contexto, $obraEscolhidaPeloUsuario, Auth::user(), $dados);
                } else {
                    app(CriarLicaoComOrigem::class)->execute($contexto, $obraEscolhidaPeloUsuario, Auth::user(), $dados);
                }
            } else {
                $obra = Work::find($this->formObraId);
                abort_unless($obra && Auth::user()->can('create', [LicaoAprendida::class, $obra]), 403);

                app(CriarLicaoAprendida::class)->execute($obra, Auth::user(), $dados);
            }
        }, 'Não foi possível salvar a lição. Tente novamente.');

        if (! $this->transacaoSeguraFalhou()) {
            $this->modalFormAberto = false;
            $eraConversaoDeCandidato = (bool) $this->candidatoConvertendoId;
            $this->limparContextoOrigem();
            $this->dispatch('show-toast', message: 'Lição salva com sucesso.', type: 'success');
            unset($this->licoes);
            if ($eraConversaoDeCandidato) {
                unset($this->candidatosDaObra, $this->contadoresCandidatos);
            }
        }
    }

    // =========================================================================
    // DETALHE
    // =========================================================================

    public function abrirDetalhe(string $id): void
    {
        $licao = LicaoAprendida::find($id);
        if (! $licao || ! Auth::user()->can('view', $licao)) {
            $this->erro = 'Você não tem permissão para ver esta lição.';
            return;
        }

        $this->licaoAbertaId = $licao->id;
        $this->vinculoTipo = '';
        $this->vinculoBusca = '';
    }

    public function fecharDetalhe(): void
    {
        $this->licaoAbertaId = null;
    }

    #[Computed]
    public function licaoAberta(): ?LicaoAprendida
    {
        if (! $this->licaoAbertaId) {
            return null;
        }

        $licao = LicaoAprendida::with(['obraOrigem', 'disciplina', 'criadoPor', 'publicadoPor', 'arquivadoPor', 'vinculos.criadoPor', 'evidencias.enviadoPor'])
            ->find($this->licaoAbertaId);

        return $licao && Auth::user()->can('view', $licao) ? $licao : null;
    }

    /** Resolve uma lição só se o usuário atual tiver `view` nela — usado pelos fluxos de mutação. */
    private function licaoDaObraAcessivel(string $id): ?LicaoAprendida
    {
        $licao = LicaoAprendida::find($id);

        return $licao ?: null;
    }

    // =========================================================================
    // WORKFLOW
    // =========================================================================

    public function enviarParaValidacao(string $id): void
    {
        $licao = $this->licaoDaObraAcessivel($id);
        if (! $licao || ! Auth::user()->can('enviarParaValidacao', $licao)) {
            $this->erro = 'Você não tem permissão para esta ação.';
            return;
        }

        $this->executarTransicao(fn () => app(EnviarLicaoParaValidacao::class)->execute($licao), 'Lição enviada para validação.');
    }

    public function devolverParaRascunho(string $id): void
    {
        $licao = $this->licaoDaObraAcessivel($id);
        if (! $licao || ! Auth::user()->can('devolverParaRascunho', $licao)) {
            $this->erro = 'Você não tem permissão para esta ação.';
            return;
        }

        $this->executarTransicao(fn () => app(DevolverLicaoParaRascunho::class)->execute($licao), 'Lição devolvida para rascunho.');
    }

    public function publicar(string $id): void
    {
        $licao = $this->licaoDaObraAcessivel($id);
        if (! $licao || ! Auth::user()->can('publicar', $licao)) {
            $this->erro = 'Você não tem permissão para esta ação.';
            return;
        }

        try {
            app(PublicarLicaoAprendida::class)->execute($licao, Auth::user());
            unset($this->licoes, $this->licaoAberta);
            $this->dispatch('show-toast', message: 'Lição publicada na biblioteca corporativa.', type: 'success');
        } catch (LicaoAprendidaIncompletaException $e) {
            $this->erro = 'Lição incompleta: preencha '.implode(', ', $e->camposFaltantes).' antes de publicar.';
        } catch (LicaoAprendidaTransicaoInvalidaException $e) {
            $this->erro = $e->getMessage();
        }
    }

    public function arquivar(string $id): void
    {
        $licao = $this->licaoDaObraAcessivel($id);
        if (! $licao || ! Auth::user()->can('arquivar', $licao)) {
            $this->erro = 'Você não tem permissão para esta ação.';
            return;
        }

        $this->executarTransicao(fn () => app(ArquivarLicaoAprendida::class)->execute($licao, Auth::user()), 'Lição arquivada.');
    }

    public function excluirLicao(string $id): void
    {
        $licao = $this->licaoDaObraAcessivel($id);
        if (! $licao || ! Auth::user()->can('delete', $licao)) {
            $this->erro = 'Você não tem permissão para excluir esta lição.';
            return;
        }

        try {
            $licao->delete();
            $this->licaoAbertaId = null;
            unset($this->licoes);
            $this->dispatch('show-toast', message: 'Lição excluída.', type: 'success');
        } catch (LicaoAprendidaImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    private function executarTransicao(\Closure $callback, string $mensagemSucesso): void
    {
        try {
            $callback();
            unset($this->licoes, $this->licaoAberta);
            $this->dispatch('show-toast', message: $mensagemSucesso, type: 'success');
        } catch (LicaoAprendidaTransicaoInvalidaException|LicaoAprendidaImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    // =========================================================================
    // VÍNCULOS
    // =========================================================================

    #[Computed]
    public function candidatosVinculo()
    {
        if ($this->vinculoTipo === '' || mb_strlen($this->vinculoBusca) < 2 || ! $this->licaoAberta) {
            return collect();
        }

        $tipo = TipoEntidadeVinculoLicao::tryFrom($this->vinculoTipo);
        if (! $tipo) {
            return collect();
        }

        $obraId = $this->licaoAberta->obra_origem_id;
        $termo = '%'.$this->vinculoBusca.'%';

        return match ($tipo) {
            TipoEntidadeVinculoLicao::Atividade => Atividade::where('obra_id', $obraId)
                ->where('nome', 'like', $termo)->limit(10)->get(['id', 'nome', 'codigo_cronograma']),
            TipoEntidadeVinculoLicao::Restricao => Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $obraId))
                ->where('descricao', 'like', $termo)->limit(10)->get(['id', 'descricao']),
            TipoEntidadeVinculoLicao::DocumentoEngenharia => DocumentoEngenharia::where('obra_id', $obraId)
                ->where(fn ($q) => $q->where('codigo', 'like', $termo)->orWhere('descricao', 'like', $termo))
                ->limit(10)->get(['id', 'codigo', 'descricao']),
            TipoEntidadeVinculoLicao::Material => Material::where(fn ($q) => $q->where('codigo', 'like', $termo)->orWhere('descricao', 'like', $termo))
                ->limit(10)->get(['id', 'codigo', 'descricao']),
            TipoEntidadeVinculoLicao::Fornecedor => Fornecedor::where('obra_id', $obraId)
                ->where('nome', 'like', $termo)->limit(10)->get(['id', 'nome']),
            TipoEntidadeVinculoLicao::Pacote => ItemSuprimento::where('obra_id', $obraId)
                ->where('nome', 'like', $termo)->limit(10)->get(['id', 'nome']),
        };
    }

    public function adicionarVinculo(string $entidadeId): void
    {
        $licao = $this->licaoAberta;
        if (! $licao || ! Auth::user()->can('vincular', $licao)) {
            $this->erro = 'Você não tem permissão para vincular entidades a esta lição.';
            return;
        }

        $tipo = TipoEntidadeVinculoLicao::tryFrom($this->vinculoTipo);
        if (! $tipo) {
            $this->erro = 'Tipo de vínculo inválido.';
            return;
        }

        try {
            app(VincularEntidadeALicao::class)->execute($licao, $tipo, $entidadeId, Auth::user());
            $this->vinculoBusca = '';
            unset($this->licaoAberta, $this->candidatosVinculo);
            $this->dispatch('show-toast', message: 'Vínculo adicionado.', type: 'success');
        } catch (LicaoAprendidaImutavelException|VinculoLicaoInvalidoException $e) {
            $this->erro = $e->getMessage();
        }
    }

    public function removerVinculo(string $vinculoId): void
    {
        $licao = $this->licaoAberta;
        if (! $licao || ! Auth::user()->can('vincular', $licao)) {
            $this->erro = 'Você não tem permissão para remover vínculos desta lição.';
            return;
        }

        $vinculo = LicaoAprendidaVinculo::where('licao_aprendida_id', $licao->id)->find($vinculoId);
        if (! $vinculo) {
            return;
        }

        try {
            app(RemoverVinculoDaLicao::class)->execute($vinculo);
            unset($this->licaoAberta);
            $this->dispatch('show-toast', message: 'Vínculo removido.', type: 'success');
        } catch (LicaoAprendidaImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    /**
     * Ciclo 23, Etapa 23.2 (Seção 13) — "link vivo somente se a
     * entidade ainda existir e o usuário atual possuir autorização
     * independente para acessá-la". Chamado por vínculo (Seção 42: um
     * punhado de vínculos por lição, nunca uma listagem — não é o
     * cenário de N+1 que a Seção 42 pede pra evitar, que é sobre a
     * BIBLIOTECA, que nunca resolve vínculo nenhum por linha).
     *
     * @return array{rota: string, parametros: array<string, mixed>}|null
     */
    public function linkVivoParaVinculo(LicaoAprendidaVinculo $vinculo): ?array
    {
        $entidade = VinculoLicaoResolver::resolver($vinculo->entidade_tipo, $vinculo->entidade_id);
        if (! $entidade) {
            return null;
        }

        $obraId = VinculoLicaoResolver::obraIdDaEntidade($vinculo->entidade_tipo, $entidade);

        if (! VinculoLicaoResolver::usuarioTemAcessoIndependente(Auth::user(), $vinculo->entidade_tipo, $obraId)) {
            return null;
        }

        return VinculoLicaoResolver::deepLinkParaEntidade($vinculo->entidade_tipo);
    }

    // =========================================================================
    // EVIDÊNCIAS (Ciclo 23, Etapa 23.2 — Seções 23-28)
    // =========================================================================

    public function anexarEvidencia(): void
    {
        $licao = $this->licaoAberta;
        if (! $licao || ! Auth::user()->can('update', $licao)) {
            $this->erro = 'Você não tem permissão para anexar evidências a esta lição.';
            return;
        }

        $this->validate([
            'novaEvidenciaArquivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.LicaoAprendidaEvidencia::TAMANHO_MAXIMO_KB],
        ]);

        try {
            app(AnexarEvidenciaALicao::class)->execute($licao, $this->novaEvidenciaArquivo, Auth::user());
            $this->novaEvidenciaArquivo = null;
            unset($this->licaoAberta);
            $this->dispatch('show-toast', message: 'Evidência anexada.', type: 'success');
        } catch (LicaoAprendidaImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    public function removerEvidencia(string $evidenciaId): void
    {
        $licao = $this->licaoAberta;
        if (! $licao || ! Auth::user()->can('update', $licao)) {
            $this->erro = 'Você não tem permissão para remover evidências desta lição.';
            return;
        }

        $evidencia = LicaoAprendidaEvidencia::where('licao_aprendida_id', $licao->id)->find($evidenciaId);
        if (! $evidencia) {
            return;
        }

        try {
            app(RemoverEvidenciaDaLicao::class)->execute($evidencia);
            unset($this->licaoAberta);
            $this->dispatch('show-toast', message: 'Evidência removida.', type: 'success');
        } catch (LicaoAprendidaImutavelException $e) {
            $this->erro = $e->getMessage();
        }
    }

    // =========================================================================
    // REVISÃO DA OBRA (Ciclo 23, Etapa 23.3)
    // =========================================================================

    public function updatedAbaAtiva(): void
    {
        $this->erroCandidatos = null;
    }

    /** Cada candidato pertence obrigatoriamente a UMA obra (Seção 37) — sempre escopado por `$this->obraAtual`, nunca o tenant inteiro. */
    #[Computed]
    public function candidatosDaObra()
    {
        if (! $this->obraAtual) {
            return collect();
        }

        $query = CandidatoLicaoAprendida::where('obra_id', $this->obraAtual->id)
            ->with(['descartadoPor', 'licaoAprendida']);

        if ($this->candidatoStatusFiltro !== '') {
            $query->where('status', $this->candidatoStatusFiltro);
        }

        if ($this->candidatoTipoFiltro !== '') {
            $query->where('tipo', $this->candidatoTipoFiltro);
        }

        if ($this->candidatoDataDe !== '') {
            $query->whereDate('gerado_em', '>=', $this->candidatoDataDe);
        }

        if ($this->candidatoDataAte !== '') {
            $query->whereDate('gerado_em', '<=', $this->candidatoDataAte);
        }

        return $query->orderByDesc('gerado_em')->get();
    }

    /** Contagens objetivas (Seção 33 — nunca score/índice/percentual artificial). */
    #[Computed]
    public function contadoresCandidatos(): array
    {
        $base = ['pendente' => 0, 'convertido' => 0, 'descartado' => 0];

        if (! $this->obraAtual) {
            return $base;
        }

        $contagens = CandidatoLicaoAprendida::where('obra_id', $this->obraAtual->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        foreach ($contagens as $status => $total) {
            $base[$status] = (int) $total;
        }

        return $base;
    }

    public function limparFiltrosCandidatos(): void
    {
        $this->reset(['candidatoStatusFiltro', 'candidatoTipoFiltro', 'candidatoDataDe', 'candidatoDataAte']);
    }

    public function atualizarCandidatos(): void
    {
        $obra = $this->obraAtual;
        if (! $obra || ! Auth::user()->can('gerarCandidatos', [CandidatoLicaoAprendida::class, $obra])) {
            $this->erroCandidatos = 'Você não tem permissão para atualizar candidatos nesta obra.';
            return;
        }

        $criados = app(GerarCandidatosLicoesObra::class)->execute($obra);

        unset($this->candidatosDaObra, $this->contadoresCandidatos);
        $this->erroCandidatos = null;
        $this->dispatch(
            'show-toast',
            message: $criados > 0 ? "{$criados} novo(s) candidato(s) encontrado(s)." : 'Nenhum candidato novo encontrado.',
            type: 'success'
        );
    }

    private function candidatoDaObraAtual(string $id): ?CandidatoLicaoAprendida
    {
        if (! $this->obraAtual) {
            return null;
        }

        return CandidatoLicaoAprendida::where('obra_id', $this->obraAtual->id)->find($id);
    }

    public function abrirDescarte(string $candidatoId): void
    {
        $candidato = $this->candidatoDaObraAtual($candidatoId);
        if (! $candidato || ! Auth::user()->can('descartarCandidato', $candidato)) {
            $this->erroCandidatos = 'Você não tem permissão para descartar este candidato.';
            return;
        }

        $this->candidatoDescartandoId = $candidato->id;
        $this->motivoDescarte = '';
        $this->erroCandidatos = null;
    }

    public function fecharDescarte(): void
    {
        $this->candidatoDescartandoId = null;
    }

    public function confirmarDescarte(): void
    {
        $candidato = $this->candidatoDescartandoId ? $this->candidatoDaObraAtual($this->candidatoDescartandoId) : null;
        if (! $candidato || ! Auth::user()->can('descartarCandidato', $candidato)) {
            $this->erroCandidatos = 'Você não tem permissão para descartar este candidato.';
            return;
        }

        try {
            app(DescartarCandidatoLicaoAprendida::class)->execute(
                $candidato,
                Auth::user(),
                $this->motivoDescarte !== '' ? $this->motivoDescarte : null
            );

            $this->candidatoDescartandoId = null;
            unset($this->candidatosDaObra, $this->contadoresCandidatos);
            $this->dispatch('show-toast', message: 'Candidato descartado.', type: 'success');
        } catch (CandidatoLicaoAprendidaJaTratadoException $e) {
            $this->erroCandidatos = $e->getMessage();
        }
    }

    /**
     * Seção 22 — inicia a conversão: resolve o contexto a partir da
     * ENTIDADE DE ORIGEM real do candidato (nunca o candidato em si,
     * Seção 25) e abre o MESMO modal de criação já usado pelo botão
     * simples/CTAs contextuais (Seção 32/33) — nada é persistido aqui.
     */
    public function iniciarConversao(string $candidatoId): void
    {
        $candidato = $this->candidatoDaObraAtual($candidatoId);
        if (! $candidato || ! $candidato->estaPendente() || ! Auth::user()->can('converterCandidato', $candidato)) {
            $this->erroCandidatos = 'Você não tem permissão para converter este candidato, ou ele já foi tratado.';
            return;
        }

        try {
            $contexto = app(PrepararContextoNovaLicao::class)->execute(
                Auth::user(),
                $candidato->entidade_tipo,
                $candidato->entidade_id,
            );
        } catch (VinculoLicaoInvalidoException $e) {
            $this->erroCandidatos = 'Não foi possível iniciar a conversão: '.$e->getMessage();
            return;
        }

        $this->erroCandidatos = null;
        $this->candidatoConvertendoId = $candidato->id;
        $this->abrirCriarComContexto($contexto);
    }

    // =========================================================================
    // INTELIGÊNCIA CORPORATIVA (Ciclo 23, Etapa 23.5.A)
    // =========================================================================

    /**
     * Decisão 9 — TODA a agregação vive em `InteligenciaLicoesQuery`; este
     * componente só lê o resultado já pronto. Cacheado por request (mesmo
     * mecanismo de `#[Computed]` já usado em todo o resto do arquivo) —
     * lido várias vezes na Blade (cabeçalho + 4 seções de distribuição +
     * evolução + materiais), nunca recalculado por leitura.
     */
    #[Computed]
    public function inteligencia(): \App\DTOs\LicoesAprendidas\ResumoInteligenciaLicoes
    {
        return InteligenciaLicoesQuery::resumo();
    }

    /**
     * Decisão 8 — reutiliza a MESMA Biblioteca Corporativa e seus filtros
     * já existentes, nunca uma tela paralela. Sempre força
     * `modo=todas_obras` + `status=Publicada` (o painel de inteligência só
     * conhece dado Publicado — o drill-down precisa aterrissar exatamente
     * no mesmo recorte que originou o número clicado, nunca um recorte
     * mais amplo que incluiria rascunho/em validação).
     *
     * @param  array<string, string>  $filtros
     */
    private function irParaBibliotecaComFiltro(array $filtros): void
    {
        $this->limparFiltros();
        $this->modoVisualizacao = 'todas_obras';
        $this->statusFiltro = StatusLicaoAprendida::Publicada->value;

        foreach ($filtros as $propriedade => $valor) {
            $this->{$propriedade} = $valor;
        }

        $this->abaAtiva = 'biblioteca';
        $this->resetPage();
    }

    public function irParaBibliotecaPorArea(string $area): void
    {
        $this->irParaBibliotecaComFiltro(['areaFiltro' => $area]);
    }

    public function irParaBibliotecaPorDisciplina(string $disciplinaId): void
    {
        $this->irParaBibliotecaComFiltro(['disciplinaFiltroId' => $disciplinaId]);
    }

    public function irParaBibliotecaPorTipo(string $tipo): void
    {
        $this->irParaBibliotecaComFiltro(['tipoFiltro' => $tipo]);
    }

    public function irParaBibliotecaPorCriticidade(string $criticidade): void
    {
        $this->irParaBibliotecaComFiltro(['criticidadeFiltro' => $criticidade]);
    }

    public function irParaBibliotecaPorMaterial(string $materialId, string $tituloMaterial): void
    {
        $this->irParaBibliotecaComFiltro(['materialFiltroId' => $materialId, 'materialFiltroTitulo' => $tituloMaterial]);
    }

    // =========================================================================
    // REAPLICAÇÃO (Ciclo 23, Etapa 23.5.B)
    // =========================================================================

    /**
     * Decisão 21 — obras onde o usuário tem `criar` em
     * `gestao.licoes-aprendidas` (mesma checagem de `obrasComAcessoParaCriar`,
     * já existente), **exceto** a obra de origem da própria lição — nunca
     * é possível "reaplicar" na obra onde o conhecimento já nasceu.
     */
    #[Computed]
    public function obrasParaReaplicar()
    {
        $licao = $this->licaoAberta;
        if (! $licao) {
            return collect();
        }

        return $this->obrasComAcessoParaCriar->reject(fn (Work $obra) => $obra->id === $licao->obra_origem_id)->values();
    }

    /** Decisão 22 — histórico completo (todas as obras) da lição aberta, em lote. */
    #[Computed]
    public function reaplicacoesDaLicaoAberta()
    {
        $licao = $this->licaoAberta;

        return $licao ? \App\Support\LicoesAprendidas\ReaplicacaoLicaoQuery::historicoDaLicao($licao) : collect();
    }

    public function abrirModalReaplicar(): void
    {
        $licao = $this->licaoAberta;
        if (! $licao || ! $licao->estaPublicada()) {
            return;
        }

        $this->reaplicarLicaoId = $licao->id;
        $this->reaplicarObraId = '';
        $this->reaplicarObservacao = '';
        $this->reaplicacaoErro = null;
        $this->modalReaplicarAberto = true;
    }

    public function fecharModalReaplicar(): void
    {
        $this->modalReaplicarAberto = false;
    }

    public function confirmarModalReaplicar(): void
    {
        if (! $this->reaplicarLicaoId || $this->reaplicarObraId === '') {
            $this->reaplicacaoErro = 'Selecione a obra de destino.';
            return;
        }

        $sucesso = $this->registrarReaplicacaoLicao(
            $this->reaplicarLicaoId,
            $this->reaplicarObraId,
            $this->reaplicarObservacao !== '' ? $this->reaplicarObservacao : null,
        );

        if ($sucesso) {
            $this->modalReaplicarAberto = false;
            unset($this->reaplicacoesDaLicaoAberta);
        }
    }

    /** Wrapper local — chama o método do trait e invalida o computed certo desta tela. */
    public function confirmarAvaliar(): void
    {
        if ($this->confirmarAvaliarReaplicacao()) {
            unset($this->reaplicacoesDaLicaoAberta);
        }
    }
}; ?>

<div>
    @if ($erro)
        <div class="alert alert-danger alert-dismissible" role="alert">
            {{ $erro }}
            <button type="button" class="btn-close" wire:click="$set('erro', null)"></button>
        </div>
    @endif

    {{-- Ciclo 23, Etapa 23.3 (Seção 27) — integrada no MESMO domínio, nunca um módulo isolado. --}}
    <ul class="nav nav-pills mb-3">
        <li class="nav-item">
            <button type="button" class="nav-link {{ $abaAtiva === 'biblioteca' ? 'active' : '' }}" wire:click="$set('abaAtiva', 'biblioteca')">
                <i class="bx bx-library me-1"></i> Biblioteca
            </button>
        </li>
        <li class="nav-item">
            <button type="button" class="nav-link {{ $abaAtiva === 'revisao' ? 'active' : '' }}" wire:click="$set('abaAtiva', 'revisao')">
                <i class="bx bx-search-alt me-1"></i> Revisão da Obra
                @if ($this->obraAtual && $this->contadoresCandidatos['pendente'] > 0)
                    <span class="badge bg-warning text-dark ms-1">{{ $this->contadoresCandidatos['pendente'] }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item">
            <button type="button" class="nav-link {{ $abaAtiva === 'inteligencia' ? 'active' : '' }}" wire:click="$set('abaAtiva', 'inteligencia')">
                <i class="bx bx-line-chart me-1"></i> Inteligência Corporativa
            </button>
        </li>
    </ul>

    @if ($abaAtiva === 'biblioteca')
    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h5 class="mb-0"><i class="bx bx-bulb me-1 text-warning"></i> Biblioteca de Lições Aprendidas</h5>
                <small class="text-muted">Experiência real → análise → validação → conhecimento corporativo publicado.</small>
            </div>
            <div class="d-flex gap-2">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-sm {{ $modoVisualizacao === 'esta_obra' ? 'btn-primary' : 'btn-outline-primary' }}"
                            wire:click="$set('modoVisualizacao', 'esta_obra')" @disabled(! $this->obraAtual)>
                        Esta obra
                    </button>
                    <button type="button" class="btn btn-sm {{ $modoVisualizacao === 'todas_obras' ? 'btn-primary' : 'btn-outline-primary' }}"
                            wire:click="$set('modoVisualizacao', 'todas_obras')">
                        Todas as obras
                    </button>
                </div>
                <button type="button" class="btn btn-sm btn-primary" wire:click="abrirCriar">
                    <i class="bx bx-plus me-1"></i> Nova Lição Aprendida
                </button>
            </div>
        </div>

        @if ($materialFiltroId !== '')
            <div class="card-body border-bottom py-2">
                <span class="badge bg-label-primary">
                    <i class="bx bx-filter-alt me-1"></i> Filtrando por Material: {{ $materialFiltroTitulo ?: $materialFiltroId }}
                    <a href="#" wire:click.prevent="$set('materialFiltroId', '')" class="text-reset ms-2" title="Remover filtro de material"><i class="bx bx-x"></i></a>
                </span>
            </div>
        @endif

        <div class="card-body border-bottom">
            <div class="row g-2">
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" placeholder="Buscar por título, situação, causa, recomendação..." wire:model.live.debounce.400ms="busca">
                </div>
                @if ($modoVisualizacao === 'todas_obras')
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="obraFiltroId">
                        <option value="">Todas as obras</option>
                        @foreach ($this->todasAsObrasDoTenant as $obra)
                            <option value="{{ $obra->id }}">{{ $obra->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="statusFiltro">
                        <option value="">Status (padrão: sem arquivadas)</option>
                        @foreach (\App\Enums\StatusLicaoAprendida::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="tipoFiltro">
                        <option value="">Tipo</option>
                        @foreach (\App\Enums\TipoLicaoAprendida::cases() as $tipo)
                            <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="criticidadeFiltro">
                        <option value="">Criticidade</option>
                        @foreach (\App\Enums\CriticidadeLicao::cases() as $crit)
                            <option value="{{ $crit->value }}">{{ $crit->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" wire:click="limparFiltros" title="Limpar filtros">
                        <i class="bx bx-x"></i>
                    </button>
                </div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="areaFiltro">
                        <option value="">Área funcional</option>
                        @foreach (\App\Enums\AreaFuncionalLicao::cases() as $area)
                            <option value="{{ $area->value }}">{{ $area->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" wire:model.live="disciplinaFiltroId">
                        <option value="">Disciplina</option>
                        @foreach ($this->disciplinas as $disc)
                            <option value="{{ $disc->id }}">{{ $disc->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" wire:model.live="dataDe" title="Data de ocorrência — de">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" wire:model.live="dataAte" title="Data de ocorrência — até">
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Tipo</th>
                        <th>Criticidade</th>
                        <th>Área</th>
                        <th>Obra de Origem</th>
                        <th>Status</th>
                        <th>Data</th>
                        <th>Autor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->licoes as $licao)
                        <tr wire:key="licao-{{ $licao->id }}" style="cursor:pointer" wire:click="abrirDetalhe('{{ $licao->id }}')">
                            <td>
                                <strong>{{ $licao->titulo }}</strong>
                                @if ($licao->disciplina)
                                    <br><small class="text-muted">{{ $licao->disciplina->nome }}</small>
                                @endif
                            </td>
                            <td><span class="badge bg-label-{{ $licao->tipo->cor() }}"><i class="bx {{ $licao->tipo->icone() }} me-1"></i>{{ $licao->tipo->label() }}</span></td>
                            <td><span class="badge bg-label-{{ $licao->criticidade->cor() }}">{{ $licao->criticidade->label() }}</span></td>
                            <td>{{ $licao->area_funcional->label() }}</td>
                            <td>{{ $licao->obraOrigem?->name }}</td>
                            <td><span class="badge bg-label-{{ $licao->status->cor() }}">{{ $licao->status->label() }}</span></td>
                            <td>{{ $licao->data_ocorrencia?->format('d/m/Y') ?? $licao->created_at->format('d/m/Y') }}</td>
                            <td>{{ $licao->criadoPor->name ?? 'Usuário removido' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bx bx-bulb-off fs-1 d-block mb-2"></i>
                                Nenhuma lição aprendida encontrada com os filtros atuais.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            {{ $this->licoes->links() }}
        </div>
    </div>
    @endif

    {{-- ===================== ABA: REVISÃO DA OBRA (Ciclo 23, Etapa 23.3) ===================== --}}
    @if ($abaAtiva === 'revisao')
        @if ($erroCandidatos)
            <div class="alert alert-danger alert-dismissible" role="alert">
                {{ $erroCandidatos }}
                <button type="button" class="btn-close" wire:click="$set('erroCandidatos', null)"></button>
            </div>
        @endif

        @if (! $this->obraAtual)
            <div class="card mb-4">
                <div class="card-body text-center text-muted py-5">
                    <i class="bx bx-map-pin fs-1 d-block mb-2"></i>
                    Selecione uma obra ativa para revisar os fatos ocorridos nela.
                </div>
            </div>
        @else
            <div class="card mb-4">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 class="mb-0"><i class="bx bx-search-alt me-1"></i> Revisão da Obra — {{ $this->obraAtual->name }}</h5>
                        <small class="text-muted">O que aconteceu nesta obra que merece ser discutido antes de seguirmos para o próximo projeto?</small>
                    </div>
                    @can('gerarCandidatos', [\App\Models\CandidatoLicaoAprendida::class, $this->obraAtual])
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="atualizarCandidatos" wire:loading.attr="disabled" wire:target="atualizarCandidatos">
                            <i class="bx bx-refresh me-1"></i> Atualizar candidatos
                        </button>
                    @endcan
                </div>

                <div class="card-body border-bottom">
                    <div class="d-flex gap-3 mb-3">
                        <span class="badge bg-label-warning">Pendentes: {{ $this->contadoresCandidatos['pendente'] }}</span>
                        <span class="badge bg-label-success">Convertidos: {{ $this->contadoresCandidatos['convertido'] }}</span>
                        <span class="badge bg-label-secondary">Descartados: {{ $this->contadoresCandidatos['descartado'] }}</span>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" wire:model.live="candidatoStatusFiltro">
                                <option value="">Todos os status</option>
                                @foreach (\App\Enums\StatusCandidatoLicaoAprendida::cases() as $statusCand)
                                    <option value="{{ $statusCand->value }}">{{ $statusCand->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" wire:model.live="candidatoTipoFiltro">
                                <option value="">Todos os tipos</option>
                                @foreach (\App\Enums\TipoCandidatoLicaoAprendida::cases() as $tipoCand)
                                    <option value="{{ $tipoCand->value }}">{{ $tipoCand->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control form-control-sm" wire:model.live="candidatoDataDe" title="Gerado de">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control form-control-sm" wire:model.live="candidatoDataAte" title="Gerado até">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary w-100" wire:click="limparFiltrosCandidatos">
                                <i class="bx bx-x"></i> Limpar
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    @forelse ($this->candidatosDaObra as $candidato)
                        <div class="border rounded p-3 mb-2" wire:key="candidato-{{ $candidato->id }}">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <span class="badge bg-label-{{ $candidato->status->cor() }} me-2">{{ $candidato->status->label() }}</span>
                                    <span class="badge bg-label-secondary me-2">{{ $candidato->tipo->label() }}</span>
                                    <strong>{{ $candidato->titulo }}</strong>
                                    <p class="mb-1 mt-1 text-muted small">{{ $candidato->descricao }}</p>
                                    <small class="text-muted">Gerado em {{ $candidato->gerado_em->format('d/m/Y H:i') }}</small>

                                    @if ($candidato->status === \App\Enums\StatusCandidatoLicaoAprendida::Convertido)
                                        <div class="mt-1">
                                            @can('view', $candidato->licaoAprendida)
                                                <a href="#" wire:click.prevent="abrirDetalhe('{{ $candidato->licao_aprendida_id }}')" class="small">
                                                    <i class="bx bx-link-alt me-1"></i>Ver lição criada
                                                </a>
                                            @else
                                                <span class="small text-muted"><i class="bx bx-link-alt me-1"></i>Lição criada</span>
                                            @endcan
                                        </div>
                                    @endif

                                    @if ($candidato->status === \App\Enums\StatusCandidatoLicaoAprendida::Descartado)
                                        <div class="mt-1 small text-muted">
                                            Descartado por {{ $candidato->descartadoPor->name ?? 'Usuário removido' }} em {{ $candidato->descartado_em?->format('d/m/Y H:i') }}
                                            @if ($candidato->motivo_descarte)
                                                — "{{ $candidato->motivo_descarte }}"
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                @if ($candidato->status === \App\Enums\StatusCandidatoLicaoAprendida::Pendente)
                                    <div class="d-flex gap-2 flex-shrink-0">
                                        @can('converterCandidato', $candidato)
                                            <button type="button" class="btn btn-sm btn-primary" wire:click="iniciarConversao('{{ $candidato->id }}')">
                                                <i class="bx bx-bulb me-1"></i> Registrar como lição
                                            </button>
                                        @endcan
                                        @can('descartarCandidato', $candidato)
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirDescarte('{{ $candidato->id }}')">
                                                Descartar
                                            </button>
                                        @endcan
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5">
                            <i class="bx bx-check-circle fs-1 d-block mb-2"></i>
                            Nenhum candidato encontrado com os filtros atuais.
                        </div>
                    @endforelse
                </div>
            </div>
        @endif
    @endif

    {{-- ===================== ABA: INTELIGÊNCIA CORPORATIVA (Ciclo 23, Etapa 23.5.A) ===================== --}}
    @if ($abaAtiva === 'inteligencia')
        @php
            $resumo = $this->inteligencia;
            $maxArea = max($resumo->distribuicaoPorArea->max('quantidade') ?? 0, 1);
            $maxDisciplina = max($resumo->distribuicaoPorDisciplina->max('quantidade') ?? 0, 1);
            $maxTipo = max($resumo->distribuicaoPorTipo->max('quantidade') ?? 0, 1);
            $maxCriticidade = max($resumo->distribuicaoPorCriticidade->max('quantidade') ?? 0, 1);
            $maxEvolucao = max($resumo->evolucaoTemporal->max('quantidade') ?? 0, 1);
            $mesesPtCurto = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr', '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
        @endphp

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bx bx-line-chart me-1 text-primary"></i> Inteligência de Lições Aprendidas</h5>
                <small class="text-muted">O que a memória publicada das nossas obras está mostrando?</small>
            </div>

            <div class="card-body border-bottom">
                <div class="d-flex flex-wrap gap-4">
                    <div>
                        <div class="fs-3 fw-bold text-primary">{{ $resumo->totalLicoesPublicadas }}</div>
                        <small class="text-muted">Lições publicadas</small>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold text-primary">{{ $resumo->totalObrasComLicaoPublicada }}</div>
                        <small class="text-muted">Obras contribuíram</small>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold text-success">{{ $resumo->totalBoasPraticasPublicadas }}</div>
                        <small class="text-muted">Boas práticas publicadas</small>
                    </div>
                </div>
            </div>

            @if ($resumo->totalLicoesPublicadas === 0)
                <div class="card-body text-center text-muted py-5">
                    <i class="bx bx-bulb-off fs-1 d-block mb-2"></i>
                    Nenhuma lição publicada ainda. Assim que a primeira lição for publicada em qualquer obra, a memória corporativa aparecerá aqui.
                </div>
            @else
                <div class="card-body border-bottom">
                    <h6 class="text-uppercase text-muted small mb-3">Distribuição da memória registrada</h6>
                    <p class="text-muted small mb-3">
                        <i class="bx bx-info-circle me-1"></i>
                        Estes números representam o que foi <strong>registrado e publicado</strong> — nunca a incidência real de problemas nas obras.
                    </p>

                    <div class="row g-4">
                        <div class="col-md-6 col-xl-3">
                            <div class="small fw-semibold text-uppercase text-muted mb-2">Distribuição das lições publicadas por área funcional</div>
                            @foreach ($resumo->distribuicaoPorArea as $item)
                                <div class="mb-2" wire:key="area-dist-{{ $item->chave }}" style="cursor:pointer" wire:click="irParaBibliotecaPorArea('{{ $item->chave }}')" title="Ver lições publicadas desta área">
                                    <div class="d-flex justify-content-between small">
                                        <span>{{ $item->rotulo }}</span>
                                        <span class="fw-semibold">{{ $item->quantidade }}</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-primary" style="width: {{ round($item->quantidade / $maxArea * 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="col-md-6 col-xl-3">
                            <div class="small fw-semibold text-uppercase text-muted mb-2">Distribuição das lições publicadas por disciplina</div>
                            @foreach ($resumo->distribuicaoPorDisciplina as $item)
                                <div class="mb-2" wire:key="disciplina-dist-{{ $item->chave ?: 'sem-disciplina' }}" style="cursor:pointer" wire:click="irParaBibliotecaPorDisciplina('{{ $item->chave }}')" title="Ver lições publicadas desta disciplina">
                                    <div class="d-flex justify-content-between small">
                                        <span>{{ $item->rotulo }}</span>
                                        <span class="fw-semibold">{{ $item->quantidade }}</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-info" style="width: {{ round($item->quantidade / $maxDisciplina * 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="col-md-6 col-xl-3">
                            <div class="small fw-semibold text-uppercase text-muted mb-2">Distribuição das lições publicadas por tipo</div>
                            @foreach ($resumo->distribuicaoPorTipo as $item)
                                <div class="mb-2" wire:key="tipo-dist-{{ $item->chave }}" style="cursor:pointer" wire:click="irParaBibliotecaPorTipo('{{ $item->chave }}')" title="Ver lições publicadas deste tipo">
                                    <div class="d-flex justify-content-between small">
                                        <span>{{ $item->rotulo }}</span>
                                        <span class="fw-semibold">{{ $item->quantidade }}</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-warning" style="width: {{ round($item->quantidade / $maxTipo * 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="col-md-6 col-xl-3">
                            <div class="small fw-semibold text-uppercase text-muted mb-2">Distribuição das lições publicadas por criticidade</div>
                            @foreach ($resumo->distribuicaoPorCriticidade as $item)
                                <div class="mb-2" wire:key="criticidade-dist-{{ $item->chave }}" style="cursor:pointer" wire:click="irParaBibliotecaPorCriticidade('{{ $item->chave }}')" title="Ver lições publicadas desta criticidade">
                                    <div class="d-flex justify-content-between small">
                                        <span>{{ $item->rotulo }}</span>
                                        <span class="fw-semibold">{{ $item->quantidade }}</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-danger" style="width: {{ round($item->quantidade / $maxCriticidade * 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="card-body border-bottom">
                    <h6 class="text-uppercase text-muted small mb-3">Evolução das publicações</h6>
                    @if ($resumo->evolucaoTemporal->isEmpty())
                        <p class="text-muted small mb-0">Nenhuma publicação registrada ainda.</p>
                    @else
                        <div class="d-flex align-items-end gap-2" style="min-height: 100px;">
                            @foreach ($resumo->evolucaoTemporal as $item)
                                @php
                                    [$ano, $mes] = explode('-', $item->periodo);
                                    $alturaPx = max(6, round($item->quantidade / $maxEvolucao * 80));
                                @endphp
                                <div class="text-center" wire:key="evolucao-{{ $item->periodo }}" style="min-width: 32px;">
                                    <div class="fw-semibold small mb-1">{{ $item->quantidade }}</div>
                                    <div class="bg-primary rounded-top mx-auto" style="width: 20px; height: {{ $alturaPx }}px;" title="{{ $item->quantidade }} publicação(ões)"></div>
                                    <div class="text-muted small mt-1">{{ $mesesPtCurto[$mes] ?? $mes }}/{{ substr($ano, 2) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="card-body">
                    <h6 class="text-uppercase text-muted small mb-1">Presença cross-obra</h6>
                    <p class="text-muted small mb-3">Materiais associados a lições publicadas provenientes de mais de uma obra — presença não implica causalidade.</p>

                    @if ($resumo->materiaisCrossObra->isEmpty())
                        <p class="text-muted small mb-0">Nenhum material com presença comprovada em 2 ou mais obras ainda.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th class="text-center">Lições publicadas</th>
                                        <th class="text-center">Obras distintas</th>
                                        <th class="text-center">Boas práticas</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($resumo->materiaisCrossObra as $item)
                                        <tr wire:key="material-cross-obra-{{ $item->materialId }}">
                                            <td>{{ $item->titulo }}</td>
                                            <td class="text-center">{{ $item->quantidadeLicoes }}</td>
                                            <td class="text-center"><span class="badge bg-label-info">{{ $item->quantidadeObras }}</span></td>
                                            <td class="text-center">
                                                @if ($item->quantidadeBoasPraticas > 0)
                                                    <span class="badge bg-label-success">{{ $item->quantidadeBoasPraticas }}</span>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="irParaBibliotecaPorMaterial('{{ $item->materialId }}', '{{ addslashes($item->titulo) }}')">
                                                    Ver lições <i class="bx bx-right-arrow-alt ms-1"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- ===================== MODAL: DESCARTAR CANDIDATO ===================== --}}
    @if ($candidatoDescartandoId)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Descartar candidato</h5>
                        <button type="button" class="btn-close" wire:click="fecharDescarte"></button>
                    </div>
                    <div class="modal-body">
                        <p>Este candidato deixará de aparecer entre os pendentes. O histórico é sempre preservado — nunca é excluído.</p>
                        <label class="form-label">Motivo (opcional)</label>
                        <textarea class="form-control" rows="2" wire:model="motivoDescarte"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fecharDescarte">Cancelar</button>
                        <button type="button" class="btn btn-danger" wire:click="confirmarDescarte">Confirmar descarte</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== MODAL: DETALHE ===================== --}}
    @if ($licaoAbertaId && $this->licaoAberta)
        @php
            $licao = $this->licaoAberta;
        @endphp
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $licao->titulo }}</h5>
                        <button type="button" class="btn-close" wire:click="fecharDetalhe"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <span class="badge bg-label-{{ $licao->tipo->cor() }}">{{ $licao->tipo->label() }}</span>
                            <span class="badge bg-label-{{ $licao->criticidade->cor() }}">{{ $licao->criticidade->label() }}</span>
                            <span class="badge bg-label-secondary">{{ $licao->area_funcional->label() }}</span>
                            @if ($licao->disciplina)
                                <span class="badge bg-label-info">{{ $licao->disciplina->nome }}</span>
                            @endif
                            <span class="badge bg-label-{{ $licao->status->cor() }}">{{ $licao->status->label() }}</span>
                        </div>

                        <h6 class="text-uppercase text-muted small">Contexto</h6>
                        <p class="mb-1"><strong>Obra de origem:</strong> {{ $licao->obraOrigem?->name }}</p>
                        @if ($licao->data_ocorrencia)
                            <p class="mb-3"><strong>Período:</strong> {{ $licao->data_ocorrencia->format('d/m/Y') }}@if ($licao->data_ocorrencia_fim) até {{ $licao->data_ocorrencia_fim->format('d/m/Y') }}@endif</p>
                        @endif

                        <h6 class="text-uppercase text-muted small">O que aconteceu</h6>
                        <p class="mb-3">{{ $licao->situacao_observada }}</p>

                        @if ($licao->causa)
                            <h6 class="text-uppercase text-muted small">Causa</h6>
                            <p class="mb-3">{{ $licao->causa }}</p>
                        @endif

                        @if ($licao->impacto)
                            <h6 class="text-uppercase text-muted small">Impacto</h6>
                            <p class="mb-3">{{ $licao->impacto }}</p>
                        @endif

                        @if ($licao->acao_adotada)
                            <h6 class="text-uppercase text-muted small">Ação Tomada</h6>
                            <p class="mb-3">{{ $licao->acao_adotada }}</p>
                        @endif

                        @if ($licao->resultado)
                            <h6 class="text-uppercase text-muted small">Resultado</h6>
                            <p class="mb-3">{{ $licao->resultado }}</p>
                        @endif

                        <div class="alert alert-primary">
                            <h6 class="text-uppercase small mb-2"><i class="bx bx-bulb me-1"></i> Recomendação para Projetos Futuros</h6>
                            <p class="mb-0 fw-semibold">{{ $licao->recomendacao_futura }}</p>
                        </div>

                        @can('update', $licao)
                            @if ($licao->observacoes_internas)
                                <h6 class="text-uppercase text-muted small">Observações Internas</h6>
                                <p class="mb-3">{{ $licao->observacoes_internas }}</p>
                            @endif
                        @endcan

                        @php
                            $vinculoOrigem = $licao->vinculos->firstWhere('e_origem', true);
                            $vinculosComplementares = $licao->vinculos->where('e_origem', false);
                        @endphp

                        @if ($vinculoOrigem)
                            <h6 class="text-uppercase text-muted small">Contexto de origem</h6>
                            <div class="d-flex align-items-center border rounded p-2 mb-3 bg-light bg-opacity-50" wire:key="vinculo-origem-{{ $vinculoOrigem->id }}">
                                <i class="bx bx-link-alt me-2 text-primary"></i>
                                <span>
                                    <span class="badge bg-label-primary me-2">{{ $vinculoOrigem->entidade_tipo->label() }}</span>
                                    @php $linkVivoOrigem = $this->linkVivoParaVinculo($vinculoOrigem); @endphp
                                    @if ($linkVivoOrigem)
                                        <a href="{{ route($linkVivoOrigem['rota'], $linkVivoOrigem['parametros']) }}" wire:navigate>{{ $vinculoOrigem->titulo_snapshot }}</a>
                                    @else
                                        {{ $vinculoOrigem->titulo_snapshot }}
                                    @endif
                                </span>
                            </div>
                        @endif

                        <h6 class="text-uppercase text-muted small">Vínculos complementares</h6>
                        @forelse ($vinculosComplementares as $vinculo)
                            <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-1" wire:key="vinculo-{{ $vinculo->id }}">
                                <span>
                                    <span class="badge bg-label-secondary me-2">{{ $vinculo->entidade_tipo->label() }}</span>
                                    @php $linkVivo = $this->linkVivoParaVinculo($vinculo); @endphp
                                    @if ($linkVivo)
                                        <a href="{{ route($linkVivo['rota'], $linkVivo['parametros']) }}" wire:navigate>{{ $vinculo->titulo_snapshot }}</a>
                                    @else
                                        {{ $vinculo->titulo_snapshot }}
                                    @endif
                                </span>
                                @can('vincular', $licao)
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removerVinculo('{{ $vinculo->id }}')">
                                        <i class="bx bx-trash"></i>
                                    </button>
                                @endcan
                            </div>
                        @empty
                            <p class="text-muted small">Nenhum vínculo complementar registrado.</p>
                        @endforelse

                        @can('vincular', $licao)
                            <div class="border rounded p-2 mt-2">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <select class="form-select form-select-sm" wire:model.live="vinculoTipo">
                                            <option value="">Tipo de entidade...</option>
                                            @foreach (\App\Enums\TipoEntidadeVinculoLicao::cases() as $tipoVinculo)
                                                <option value="{{ $tipoVinculo->value }}">{{ $tipoVinculo->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control form-control-sm" placeholder="Buscar por código/nome..." wire:model.live.debounce.400ms="vinculoBusca">
                                    </div>
                                </div>
                                @if ($this->candidatosVinculo->isNotEmpty())
                                    <ul class="list-group mt-2">
                                        @foreach ($this->candidatosVinculo as $candidato)
                                            <li class="list-group-item d-flex justify-content-between align-items-center" wire:key="cand-{{ $candidato->id }}">
                                                {{ $candidato->nome ?? $candidato->descricao ?? $candidato->codigo }}
                                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="adicionarVinculo('{{ $candidato->id }}')">Adicionar</button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endcan

                        <h6 class="text-uppercase text-muted small mt-3">Evidências</h6>
                        @forelse ($licao->evidencias as $evidencia)
                            <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-1" wire:key="evidencia-{{ $evidencia->id }}">
                                <span>
                                    <i class="bx bx-paperclip me-1"></i>
                                    <a href="{{ route('licao-evidencias.download', $evidencia) }}" target="_blank">{{ $evidencia->nome_original }}</a>
                                    <small class="text-muted d-block">{{ number_format($evidencia->tamanho_bytes / 1024, 0) }} KB &middot; enviado por {{ $evidencia->enviadoPor->name ?? 'Usuário removido' }}</small>
                                </span>
                                @can('update', $licao)
                                    @if (! $licao->estaImutavel())
                                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removerEvidencia('{{ $evidencia->id }}')">
                                            <i class="bx bx-trash"></i>
                                        </button>
                                    @endif
                                @endcan
                            </div>
                        @empty
                            <p class="text-muted small">Nenhuma evidência anexada.</p>
                        @endforelse

                        @can('update', $licao)
                            @if (! $licao->estaImutavel())
                                <div class="input-group input-group-sm mt-2">
                                    <input type="file" class="form-control" wire:model="novaEvidenciaArquivo">
                                    <button type="button" class="btn btn-outline-primary" wire:click="anexarEvidencia" wire:loading.attr="disabled" wire:target="novaEvidenciaArquivo,anexarEvidencia">
                                        <i class="bx bx-upload me-1"></i> Anexar
                                    </button>
                                </div>
                                @error('novaEvidenciaArquivo') <span class="text-danger small">{{ $message }}</span> @enderror
                                <small class="text-muted">PDF, JPG ou PNG, até 10 MB.</small>
                            @endif
                        @endcan

                        @if ($licao->estaPublicada())
                            <h6 class="text-uppercase text-muted small mt-3 d-flex align-items-center justify-content-between">
                                <span>Reaplicação em outras obras</span>
                                @if ($this->obrasParaReaplicar->isNotEmpty())
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirModalReaplicar">
                                        <i class="bx bx-repost me-1"></i> Registrar reaplicação
                                    </button>
                                @endif
                            </h6>
                            <p class="text-muted small mb-2">
                                Registro de que a equipe adotou conscientemente esta lição em outra obra — nunca inferido automaticamente.
                            </p>
                            @forelse ($this->reaplicacoesDaLicaoAberta as $reaplicacao)
                                @php $ultimaAvaliacao = $reaplicacao->ultimaAvaliacao(); @endphp
                                <div class="border rounded p-2 mb-2" wire:key="reaplicacao-{{ $reaplicacao->id }}">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <strong>Reaplicada na Obra {{ $reaplicacao->obra->name ?? 'removida' }}</strong>
                                            <div class="small text-muted">
                                                {{ $reaplicacao->created_at->format('d/m/Y') }} — registrado por {{ $reaplicacao->criadoPor->name ?? 'Usuário removido' }}
                                            </div>
                                            @if ($reaplicacao->observacao_inicial)
                                                <p class="small mb-0 mt-1">{{ $reaplicacao->observacao_inicial }}</p>
                                            @endif
                                        </div>
                                        @can('avaliar', $reaplicacao)
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirAvaliarReaplicacao('{{ $reaplicacao->id }}')">
                                                Avaliar resultado
                                            </button>
                                        @endcan
                                    </div>

                                    @if ($reaplicacao->avaliacoes->isEmpty())
                                        <div class="small text-muted mt-2"><i class="bx bx-time me-1"></i>Aguardando avaliação</div>
                                    @else
                                        <div class="mt-2">
                                            @foreach ($reaplicacao->avaliacoes as $avaliacao)
                                                <div class="d-flex align-items-center gap-2 small mb-1" wire:key="avaliacao-{{ $avaliacao->id }}">
                                                    <span class="text-muted">{{ $avaliacao->avaliado_em->format('d/m/Y') }}</span>
                                                    <span class="badge bg-label-{{ $avaliacao->resultado->cor() }}">{{ $avaliacao->resultado->label() }}</span>
                                                    @if ($avaliacao->observacao)
                                                        <span class="text-muted">— {{ $avaliacao->observacao }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-muted small">Nenhuma reaplicação registrada ainda.</p>
                            @endforelse
                        @endif

                        <h6 class="text-uppercase text-muted small mt-3">Governança</h6>
                        <p class="mb-1 small">Criado por: {{ $licao->criadoPor->name ?? 'Usuário removido' }} em {{ $licao->created_at->format('d/m/Y H:i') }}</p>
                        @if ($licao->publicado_em)
                            <p class="mb-1 small">Publicado por: {{ $licao->publicadoPor->name ?? 'Usuário removido' }} em {{ $licao->publicado_em->format('d/m/Y H:i') }}</p>
                        @endif
                        @if ($licao->arquivado_em)
                            <p class="mb-1 small">Arquivado por: {{ $licao->arquivadoPor->name ?? 'Usuário removido' }} em {{ $licao->arquivado_em->format('d/m/Y H:i') }}</p>
                        @endif
                    </div>
                    <div class="modal-footer flex-wrap">
                        @can('update', $licao)
                            @if ($licao->estaEditavel())
                                <button type="button" class="btn btn-outline-primary" wire:click="abrirEditar('{{ $licao->id }}')">Editar</button>
                            @endif
                        @endcan
                        @can('enviarParaValidacao', $licao)
                            @if ($licao->status === \App\Enums\StatusLicaoAprendida::Rascunho)
                                <button type="button" class="btn btn-warning" wire:click="enviarParaValidacao('{{ $licao->id }}')">Enviar para Validação</button>
                            @endif
                        @endcan
                        @can('devolverParaRascunho', $licao)
                            @if ($licao->status === \App\Enums\StatusLicaoAprendida::EmValidacao)
                                <button type="button" class="btn btn-outline-secondary" wire:click="devolverParaRascunho('{{ $licao->id }}')">Devolver para Rascunho</button>
                            @endif
                        @endcan
                        @can('publicar', $licao)
                            @if ($licao->status === \App\Enums\StatusLicaoAprendida::EmValidacao)
                                <button type="button" class="btn btn-success" wire:click="publicar('{{ $licao->id }}')">
                                    <i class="bx bx-check-circle me-1"></i> Publicar
                                </button>
                            @endif
                        @endcan
                        @can('arquivar', $licao)
                            @if ($licao->status === \App\Enums\StatusLicaoAprendida::Publicada)
                                <button type="button" class="btn btn-outline-dark" wire:click="arquivar('{{ $licao->id }}')">Arquivar</button>
                            @endif
                        @endcan
                        @can('delete', $licao)
                            @if (! $licao->status->estaImutavel())
                                <button type="button" class="btn btn-outline-danger" wire:click="excluirLicao('{{ $licao->id }}')">Excluir</button>
                            @endif
                        @endcan
                        <button type="button" class="btn btn-secondary" wire:click="fecharDetalhe">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== MODAL: REGISTRAR REAPLICAÇÃO (Ciclo 23, Etapa 23.5.B — Seção 21) ===================== --}}
    @if ($modalReaplicarAberto)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Registrar reaplicação</h5>
                        <button type="button" class="btn-close" wire:click="fecharModalReaplicar"></button>
                    </div>
                    <div class="modal-body">
                        @if ($reaplicacaoErro)
                            <div class="alert alert-danger py-2 px-3 small">{{ $reaplicacaoErro }}</div>
                        @endif
                        <p class="text-muted small">
                            Registre que a equipe adotou conscientemente esta lição em outra obra. Só é possível escolher obras onde você tem autorização — nunca a obra de origem desta lição.
                        </p>
                        <label class="form-label">Obra de destino</label>
                        <select class="form-select mb-3" wire:model="reaplicarObraId">
                            <option value="">Selecione a obra...</option>
                            @foreach ($this->obrasParaReaplicar as $obraDestino)
                                <option value="{{ $obraDestino->id }}">{{ $obraDestino->name }}</option>
                            @endforeach
                        </select>
                        <label class="form-label">Observação inicial (opcional)</label>
                        <textarea class="form-control" rows="3" wire:model="reaplicarObservacao"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalReaplicar">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmarModalReaplicar">Registrar reaplicação</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @include('components.licoes-aprendidas.reaplicacao-avaliar-modal')

    {{-- ===================== MODAL: CRIAR / EDITAR ===================== --}}
    @if ($modalFormAberto)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            {{ $editandoId ? 'Editar Lição Aprendida' : ($candidatoConvertendoId ? 'Registrar Lição a partir de Candidato' : 'Nova Lição Aprendida') }}
                        </h5>
                        <button type="button" class="btn-close" wire:click="fecharModalForm"></button>
                    </div>
                    <div class="modal-body">
                        @if ($candidatoConvertendoId)
                            <div class="alert alert-warning small mb-3">
                                <i class="bx bx-bulb me-1"></i> Este candidato será marcado como <strong>Convertido</strong> somente após salvar com sucesso. Cancelar não altera o candidato.
                            </div>
                        @endif
                        @if ($contextoOrigemTipo)
                            <div class="alert alert-info d-flex align-items-start gap-2 mb-3">
                                <i class="bx bx-link-alt fs-4"></i>
                                <div>
                                    <strong>Origem desta lição</strong><br>
                                    <span class="badge bg-label-primary me-1">{{ \App\Enums\TipoEntidadeVinculoLicao::from($contextoOrigemTipo)->label() }}</span>
                                    {{ $contextoOrigemTitulo }}
                                    @if (! empty($contextoVinculosComplementares))
                                        <div class="mt-2 small text-muted">
                                            Também será vinculada a:
                                            @foreach ($contextoVinculosComplementares as $c)
                                                <span class="badge bg-label-secondary border me-1">{{ \App\Enums\TipoEntidadeVinculoLicao::from($c['tipo'])->label() }}: {{ $c['titulo'] }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if (! $editandoId)
                            <div class="mb-3">
                                <label class="form-label">Obra de origem</label>
                                @if ($contextoOrigemTipo && $contextoObraDeterministica)
                                    <input type="text" class="form-control" value="{{ \App\Models\Work::find($formObraId)?->name }}" disabled>
                                    <small class="text-muted">Determinada automaticamente pela entidade de origem — não pode ser alterada aqui.</small>
                                @else
                                    <select class="form-select" wire:model="formObraId">
                                        <option value="">Selecione...</option>
                                        @foreach ($this->obrasComAcessoParaCriar as $obra)
                                            <option value="{{ $obra->id }}">{{ $obra->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @error('formObraId') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label">Título</label>
                            <input type="text" class="form-control" wire:model="formTitulo">
                            @error('formTitulo') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" wire:model="formTipo">
                                    <option value="">Selecione...</option>
                                    @foreach (\App\Enums\TipoLicaoAprendida::cases() as $tipo)
                                        <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                                    @endforeach
                                </select>
                                @error('formTipo') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Criticidade</label>
                                <select class="form-select" wire:model="formCriticidade">
                                    <option value="">Selecione...</option>
                                    @foreach (\App\Enums\CriticidadeLicao::cases() as $crit)
                                        <option value="{{ $crit->value }}">{{ $crit->label() }}</option>
                                    @endforeach
                                </select>
                                @error('formCriticidade') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Área Funcional</label>
                                <select class="form-select" wire:model="formArea">
                                    <option value="">Selecione...</option>
                                    @foreach (\App\Enums\AreaFuncionalLicao::cases() as $area)
                                        <option value="{{ $area->value }}">{{ $area->label() }}</option>
                                    @endforeach
                                </select>
                                @error('formArea') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Disciplina (opcional)</label>
                                <select class="form-select" wire:model="formDisciplinaId">
                                    <option value="">Nenhuma</option>
                                    @foreach ($this->disciplinas as $disc)
                                        <option value="{{ $disc->id }}">{{ $disc->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data da ocorrência (opcional)</label>
                                <input type="date" class="form-control" wire:model="formDataOcorrencia">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data fim do período (opcional)</label>
                                <input type="date" class="form-control" wire:model="formDataOcorrenciaFim">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Situação Observada</label>
                            <textarea class="form-control" rows="3" wire:model="formSituacao"></textarea>
                            @error('formSituacao') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Causa (opcional)</label>
                            <textarea class="form-control" rows="2" wire:model="formCausa"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Impacto (opcional)</label>
                            <textarea class="form-control" rows="2" wire:model="formImpacto"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Ação Adotada (opcional)</label>
                            <textarea class="form-control" rows="2" wire:model="formAcao"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Resultado (opcional)</label>
                            <textarea class="form-control" rows="2" wire:model="formResultado"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Recomendação para Projetos Futuros</label>
                            <textarea class="form-control" rows="3" wire:model="formRecomendacao"></textarea>
                            @error('formRecomendacao') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Observações Internas (opcional, não aparece na versão publicada para outras obras)</label>
                            <textarea class="form-control" rows="2" wire:model="formObservacoesInternas"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fecharModalForm">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="salvarForm">Salvar Rascunho</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
