<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use App\Actions\Atividade\MarcarNaoConcluido;
use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Estoque\CriarMaterial;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\GranularidadePeriodo;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\OrigemCadastroMaterial;
use App\Enums\OrigemNecessidadeMaterialAtividade;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\NecessidadeMaterialAtividadeInvalidaException;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Exceptions\SaldoNecessidadeInsuficienteException;
use App\Exports\PlanoSemanalExport;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\AvancoPeriodo;
use App\Models\CategoriaRestricao;
use App\Models\DocumentoEngenharia;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\Etapa;
use App\Models\FamiliaMaterial;
use App\Models\FrenteTrabalho;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\LinhaBase;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\PacoteTrabalho;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Restricao;
use App\Models\UnidadeMedida;
use App\Models\Work;
use App\Services\CurvaAvanco;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use App\Support\Estoque\CoberturaNecessidadeAtividadeQuery;
use App\Support\Estoque\ConciliacaoNecessidadeAtividade;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
    use ExecutaComTransacaoSegura;

    public Work $obra;

    // Semana visualizada (início = segunda-feira). #[Url] permite link
    // direto de Minhas Programações pra uma semana específica
    // (?semana=YYYY-MM-DD); sem o parâmetro, mount() usa a semana atual.
    #[Url(as: 'semana')]
    public string $semanaInicio = '';

    // Modal "Não Concluído"
    public ?string $naoConcluindoId   = null;
    public string  $descricaoCausa    = '';

    // Seleção manual pra "Programar selecionadas" (só Planejado + liberada + não concluída no cronograma)
    public array $selecionadas = [];

    // Banner de validação (mais visível que o toast, some sozinho quando a
    // seleção muda) exibido acima do botão "Programar selecionadas" quando
    // o usuário clica sem nenhuma atividade válida marcada.
    public ?string $erroSelecao = null;

    // ---- Filtros (mesmos do Lookahead, mesmos nomes de campo/lógica) ----
    public string $search = '';
    public ?string $etapaIdFiltro = null;
    public ?string $frenteTrabalhoIdFiltro = null;
    public ?string $faturamentoDiretoFiltro = null; // '' = todos | '1' = sim | '0' = não
    public ?string $entregavelIdFiltro = null;
    public ?string $equipeResponsavelIdFiltro = null;
    public ?string $personalizado1IdFiltro = null;
    public ?string $personalizado2IdFiltro = null;
    public ?string $personalizado3IdFiltro = null;
    public ?string $personalizado4IdFiltro = null;
    public ?string $personalizado5IdFiltro = null;
    public bool $ocultarConcluidas = false;

    // Filtro por liberação — reaproveita EXATAMENTE a mesma fonte que o KPI
    // "Atividades Liberadas" e a coluna Liberação já usam (idsProntas(),
    // que delega 100% pra Atividade::scopeProntas()) — nunca uma segunda
    // definição no Blade. Filtro só de EXIBIÇÃO (aplicado em
    // arvoreAtividades(), mesmo tratamento de ocultarConcluidas) —
    // deliberadamente fora de queryAtividadesPeriodo() pra nunca alterar
    // PPC/HH/cards de período, que continuam refletindo o período inteiro.
    public string $liberacaoFiltro = 'todas'; // 'todas' | 'liberadas' | 'bloqueadas'

    // Popup "Liberação para Programação" — aberto ao clicar no nome da
    // atividade. Carrega sob demanda (ver atividadeDetalhePlano()), nunca
    // eager-load em lote pra todas as linhas da tabela.
    public ?string $atividadeDetalheId = null;

    // Modal "Dar baixa" numa restrição — mesmo padrão de campo/nome já
    // usado em ⚡lookahead.blade.php, pra não inventar uma segunda
    // convenção de UI pro mesmo fluxo.
    public ?string $baixandoRestricaoId = null;
    public ?string $dataBaixaNova = null;
    public string $textoBaixaNova = '';

    // Modal "Nova Restrição" — mesmos campos/nomes de ⚡lookahead.blade.php.
    public bool $modalRestricaoAberto = false;
    public ?string $atividadeIdRestricao = null;
    public string $descricaoNova = '';
    public bool $blocanteNova = true;
    public ?string $prazolimiteNova = null;
    public ?int $probabilidadeNova = null;
    public ?int $impactoNova = null;
    public ?string $categoriaIdNova = null;
    public ?string $responsavelIdNova = null;
    public string $responsavelExternoNova = '';
    public bool $responsavelExterno = false;

    // Melhoria "Posto Operacional" — Documentos de Engenharia no popup
    // (Seção 12): vincular reaproveita a MESMA relação N:N e o MESMO
    // padrão inline (busca + botão, sem modal) já usado em
    // ⚡documentos-engenharia.blade.php::atividadesParaVincular().
    public string $buscaDocumentoVincular = '';

    // Melhoria "Posto Operacional" — Materiais para Execução (Seção 13).
    public bool $modalNecessidadeAberto = false;
    public ?string $necessidadeEditandoId = null;
    public string $origemNovaNecessidade = 'take_off'; // 'take_off' | 'operacional'
    public ?string $itemTakeOffIdNovaNecessidade = null;
    public ?string $materialIdNovaNecessidade = null;
    public ?float $quantidadeNovaNecessidade = null;
    public string $observacaoNovaNecessidade = '';
    public string $buscaItemTakeOffNecessidade = '';
    public string $buscaMaterialNecessidade = '';

    // Melhoria "Posto Operacional" — criação inline de Material Mestre,
    // sem sair do popup, só disponível pra origem=operacional (nunca
    // pra origem=take_off — o Material dessa origem é sempre derivado
    // do ItemTakeOff, nunca criado aqui). Proveniência sempre gravada
    // como OrigemCadastroMaterial::PlanoSemanal, nunca confundida com
    // Engenharia/TakeOff.
    public bool $modalNovoMaterialAberto = false;
    public string $novoMaterialCodigo = '';
    public string $novoMaterialDescricao = '';
    public ?string $novoMaterialUnidadeMedidaId = null;
    public ?string $novoMaterialFamiliaId = null;
    public string $novoMaterialModoRastreabilidade = 'quantitativo';

    // Melhoria "Posto Operacional" — "Reservar agora" (Seção 7/13).
    public ?string $necessidadeReservandoId = null;
    public ?string $pacoteIdReserva = null;
    public ?string $localIdReserva = null;
    public ?float $quantidadeReserva = null;

    // Fonte das datas usadas pra decidir QUAIS atividades caem dentro da
    // semana visualizada (queryAtividadesPeriodo) — 'tendencia' (padrão,
    // preserva o comportamento de sempre) usa inicio_planejado/
    // data_termino "ao vivo" (cronograma atual, já refletindo a última
    // reimportação); 'baseline' usa baseline_inicio/baseline_termino "ao
    // vivo" (a Linha de Base, mesma fonte que o Lookahead usa sem
    // seletor explícito). Não confundir com linhaBaseId abaixo, que só
    // troca o DENOMINADOR dos cards "% Previsto"/"% Avanço" — este aqui
    // muda quais atividades entram na lista/cards, não é comparável.
    public string $fonteDatasPeriodo = 'tendencia';

    // Referência de HH total do projeto pros cards "% Previsto"/"% Avanço".
    // null = importação Baseline/Ambos mais recente (não "ao vivo" —
    // ver App\Services\CurvaAvanco::resolverImportacaoId()).
    public ?string $linhaBaseId = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        if ($this->semanaInicio === '') {
            $this->semanaInicio = Carbon::now()->startOfWeek()->toDateString();
        } else {
            // Link direto (?semana=) sempre normalizado pro início da
            // semana (segunda-feira), igual a qualquer navegação interna.
            $this->semanaInicio = Carbon::parse($this->semanaInicio)->startOfWeek()->toDateString();
        }
    }

    #[Computed]
    public function semanaFim(): string
    {
        return Carbon::parse($this->semanaInicio)->endOfWeek()->toDateString();
    }

    /** Programação (header) vigente pra semana visualizada, se existir — a versão mais recente (ver ProgramacaoSemanal::ativaPara()). */
    #[Computed]
    public function programacaoDaSemana(): ?ProgramacaoSemanal
    {
        return ProgramacaoSemanal::ativaPara($this->obra, $this->semanaInicio)?->load('itens');
    }

    #[Computed]
    public function estaVisualizandoSemanaPassada(): bool
    {
        return Carbon::parse($this->semanaInicio)->lt(Carbon::now()->startOfWeek());
    }

    /**
     * A semana ATUAL nunca é tratada como "congelada" mesmo que já tenha
     * um header (porque já rodou um comprometimento hoje) — só passa a
     * ser congelada quando a semana já ficou no passado. É isso que
     * permite acompanhar ao vivo durante a semana corrente e só "fechar
     * o retrato" quando ela vira passado (ver Contexto do plano).
     */
    #[Computed]
    public function semanaEstaCongelada(): bool
    {
        return $this->programacaoDaSemana !== null && $this->estaVisualizandoSemanaPassada;
    }

    /**
     * Fechamento EXPLÍCITO (botão "Gerar Programação"), independente de
     * ser semana passada ou corrente — diferente de `semanaEstaCongelada`
     * (trava total automática, legado). É esse status que abre a exceção
     * de marcar concluída/não concluído numa semana já travada por ser
     * passado (ver CLAUDE.md, seção "Programação Semanal — fechamento e
     * revisões").
     */
    #[Computed]
    public function semanaEstaFechada(): bool
    {
        return $this->programacaoDaSemana?->estaFechada() ?? false;
    }

    #[Computed]
    public function linhasBase()
    {
        return LinhaBase::where('obra_id', $this->obra->id)
            ->with('importacao:id,importado_em,arquivo')
            ->latest()
            ->get(['id', 'nome', 'cronograma_importacao_id']);
    }

    #[Computed]
    public function linhaBaseSelecionada(): ?LinhaBase
    {
        if (! $this->linhaBaseId) {
            return null;
        }

        return $this->linhasBase->firstWhere('id', $this->linhaBaseId);
    }

    /** HH total do projeto (Previsto/Mensal) — denominador dos cards "% Previsto"/"% Avanço". */
    #[Computed]
    public function totalHhProjeto(): float
    {
        return app(CurvaAvanco::class)->totalCalculado(
            $this->obra, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, null, $this->linhaBaseId
        );
    }

    /**
     * HH previsto desta semana por atividade — vem do congelado quando a
     * semana já está fechada (mesmo HH do momento do commit, imune a
     * reimportação futura); senão lê `AvancoPeriodo` ao vivo. Chave ausente
     * (não `pluck` retornando 0) é o que distingue "sem HH cadastrado" de
     * "HH cadastrado como zero" nos cards que somam esta coleção
     * (percentualAvancoPrevistoPeriodo/percentualAvancoLiberadasPeriodo).
     */
    #[Computed]
    public function hhPrevistoSemanaPorAtividade(): \Illuminate\Support\Collection
    {
        if ($this->semanaEstaCongelada) {
            return $this->programacaoDaSemana->itens->pluck('horas_previstas_congeladas', 'atividade_id');
        }

        $ids = $this->atividades->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return AvancoPeriodo::where('serie', SerieAvanco::Previsto->value)
            ->where('granularidade', GranularidadePeriodo::Semanal->value)
            ->where('periodo_inicio', $this->semanaInicio)
            ->whereIn('atividade_id', $ids)
            ->pluck('horas', 'atividade_id');
    }

    public function updatedLinhaBaseId(): void
    {
        unset($this->totalHhProjeto);
    }

    /**
     * Query-base (sem ->get()) das atividades COMPREENDIDAS no intervalo
     * da semana, segundo `fonteDatasPeriodo`: 'tendencia' (padrão) usa
     * inicio_planejado/data_termino — o cronograma atual, já refletindo
     * a última reimportação; ver App\Models\Atividade e a filosofia
     * "tendência = Work atual" no CLAUDE.md — ou 'baseline', que usa
     * baseline_inicio/baseline_termino "ao vivo" (a Linha de Base, mesma
     * fonte que o Lookahead usa sem seletor explícito). "Compreendida" é
     * sobreposição de intervalo, não só início OU término dentro da
     * semana — senão uma atividade de 3 semanas que começou antes e
     * termina depois da semana em questão nunca apareceria, mesmo
     * estando em execução bem no meio dela. QUALQUER status entra aqui
     * (inclusive Planejado) — é o próprio Lookahead da semana, não só o
     * que já foi comprometido; reaproveitada por atividades()/
     * idsProntas() pra não duplicar as condições de data.
     *
     * Filtros de busca/etapa/frente/terceirizados — mesmos campos e mesma
     * lógica do Lookahead (⚡lookahead.blade.php::atividades()) — entram
     * aqui porque estreitam o ESCOPO ("quais atividades me importam"),
     * então cards/PPC/gráfico devem refletir também. "Ocultar concluídas"
     * fica de fora de propósito: é só preferência de EXIBIÇÃO da árvore
     * (aplicada em arvoreAtividades()), não pode remover concluídas do
     * cálculo de PPC (senão o "concluídas" do PPC sempre zeraria).
     */
    private function queryAtividadesPeriodo()
    {
        [$colunaInicio, $colunaTermino] = $this->fonteDatasPeriodo === 'baseline'
            ? ['baseline_inicio', 'baseline_termino']
            : ['inicio_planejado', 'data_termino'];

        $query = Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->whereNotNull($colunaInicio)
            ->whereNotNull($colunaTermino)
            ->where($colunaInicio, '<=', $this->semanaFim)
            ->where($colunaTermino, '>=', $this->semanaInicio);

        if ($this->search !== '') {
            $query->where('nome', 'like', '%' . $this->search . '%');
        }
        if ($this->etapaIdFiltro) {
            $query->where('etapa_id', $this->etapaIdFiltro);
        }
        if ($this->frenteTrabalhoIdFiltro) {
            $query->where('frente_trabalho_id', $this->frenteTrabalhoIdFiltro);
        }
        if ($this->faturamentoDiretoFiltro !== null && $this->faturamentoDiretoFiltro !== '') {
            $query->where('faturamento_direto', $this->faturamentoDiretoFiltro === '1');
        }
        if ($this->entregavelIdFiltro) {
            $query->where('entregavel_id', $this->entregavelIdFiltro);
        }
        if ($this->equipeResponsavelIdFiltro) {
            $query->where('equipe_responsavel_id', $this->equipeResponsavelIdFiltro);
        }
        if ($this->personalizado1IdFiltro) {
            $query->where('personalizado_1_id', $this->personalizado1IdFiltro);
        }
        if ($this->personalizado2IdFiltro) {
            $query->where('personalizado_2_id', $this->personalizado2IdFiltro);
        }
        if ($this->personalizado3IdFiltro) {
            $query->where('personalizado_3_id', $this->personalizado3IdFiltro);
        }
        if ($this->personalizado4IdFiltro) {
            $query->where('personalizado_4_id', $this->personalizado4IdFiltro);
        }
        if ($this->personalizado5IdFiltro) {
            $query->where('personalizado_5_id', $this->personalizado5IdFiltro);
        }

        return $query;
    }

    #[Computed]
    public function etapas()
    {
        return Etapa::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function frentesTrabalho()
    {
        return FrenteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function entregaveis()
    {
        return Entregavel::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function equipesResponsaveis()
    {
        return EquipeResponsavel::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function personalizados1()
    {
        return Personalizado1::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function personalizados2()
    {
        return Personalizado2::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function personalizados3()
    {
        return Personalizado3::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function personalizados4()
    {
        return Personalizado4::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function personalizados5()
    {
        return Personalizado5::where('obra_id', $this->obra->id)->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function atividades()
    {
        if ($this->semanaEstaCongelada) {
            return $this->atividadesDaProgramacaoCongelada();
        }

        return $this->queryAtividadesPeriodo()
            ->with(['pacoteTrabalho', 'disciplina', 'responsavel'])
            ->get();
    }

    /**
     * Reconstrói a lista a partir do conjunto CONGELADO (itens da
     * programação salva), não da consulta ao vivo por sobreposição de
     * datas. Só as datas planejadas (o lado "previsto") vêm congeladas —
     * status, percentual_concluido, responsável etc. continuam ao vivo
     * de propósito, é exatamente o lado "realizado" sendo comparado.
     * NUNCA chamar ->save() nas instâncias retornadas aqui.
     */
    private function atividadesDaProgramacaoCongelada(): \Illuminate\Support\Collection
    {
        $itens = $this->programacaoDaSemana->itens;

        $atividades = Atividade::whereIn('id', $itens->pluck('atividade_id'))
            ->with(['pacoteTrabalho', 'disciplina', 'responsavel'])
            ->get()
            ->keyBy('id');

        return $itens->map(function (ProgramacaoSemanalItem $item) use ($atividades) {
            $at = $atividades->get($item->atividade_id);
            if (! $at) {
                return null; // defensivo: atividade removida (cascade) não deveria deixar item órfão
            }

            $at->inicio_planejado = $item->inicio_planejado_congelado;
            $at->data_termino = $item->data_termino_congelado;

            return $at;
        })->filter()->values();
    }

    /**
     * Subconjunto de atividades() já COMPROMETIDO (status comprometido ou
     * além) — é o que efetivamente compõe "o plano" pra fins de PPC.
     * Planejado ainda não entrou na programação, não deve contar no
     * denominador do PPC (senão o índice cai artificialmente por
     * atividades que nem foram inseridas ainda).
     */
    #[Computed]
    public function atividadesComprometidas()
    {
        return $this->atividades->filter(fn ($at) => in_array($at->status, [
            StatusAtividade::Comprometido,
            StatusAtividade::EmExecucao,
            StatusAtividade::Concluido,
            StatusAtividade::NaoConcluido,
        ], true))->values();
    }

    /**
     * IDs liberados (sem restrição bloqueante em aberto E itens de
     * prontidão completos) dentro do período — consulta agregada única
     * via Atividade::scopeProntas(), evita N+1 de estaPronta() por linha
     * (mesmo cuidado já tomado em HasObraPapel nesta sessão).
     */
    #[Computed]
    public function idsProntas(): \Illuminate\Support\Collection
    {
        if ($this->semanaEstaCongelada) {
            return collect(); // liberação/restrição não se reavalia numa semana já fechada
        }

        return $this->queryAtividadesPeriodo()->prontas()->pluck('id');
    }

    // =========================================================================
    // MELHORIA — Popup "Liberação para Programação" (auditoria de
    // Atividade::scopeProntas()/estaPronta(), a ÚNICA definição de
    // liberado/bloqueado do projeto — ver CLAUDE.md). Reaproveita as MESMAS
    // fontes de referência/queries já usadas pelo popup do Lookahead
    // (⚡lookahead.blade.php), inclusive as mesmas chaves de cache — nunca
    // uma segunda definição de prontidão/liberação em paralelo.
    // =========================================================================

    #[Computed]
    public function categorias()
    {
        return Cache::remember(
            "tenant_{$this->obra->tenant_id}_categorias",
            300,
            fn () => CategoriaRestricao::orderBy('nome')->get(['id', 'nome', 'pilar_lean'])
        );
    }

    #[Computed]
    public function usuariosDaObra()
    {
        return Cache::remember(
            "obra_{$this->obra->id}_usuarios",
            90,
            fn () => $this->obra
                ->users()
                ->orderBy('users.first_name')
                ->get(['users.id', 'users.first_name', 'users.last_name'])
        );
    }

    #[Computed]
    public function itensProntidao()
    {
        return Cache::remember(
            "obra_{$this->obra->id}_itens_prontidao",
            90,
            fn () => ItemProntidao::where('obra_id', $this->obra->id)
                ->orderBy('ordem')
                ->get()
        );
    }

    public function verAtividadeDetalhe(string $atividadeId): void
    {
        $this->atividadeDetalheId = $atividadeId;
    }

    public function fecharAtividadeDetalhe(): void
    {
        $this->atividadeDetalheId = null;
    }

    /**
     * Diagnóstico "por que posso ou não posso programar esta atividade?" —
     * carregado SOB DEMANDA (só quando o popup está aberto, nunca em lote
     * pra todas as linhas da tabela — item 10 do pedido). Mesmo formato de
     * `⚡lookahead.blade.php::atividadeDetalhe()` (restrições+categoria+
     * responsável+checklist+documentos bloqueantes), deliberadamente sem a
     * Curva S/lições/comentários/anexos daquele popup — este é um popup
     * NOVO e mais enxuto, focado só na pergunta de liberação (escopo
     * mínimo pedido). Guarda cross-obra: `where('obra_id', $this->obra->id)`
     * garante que um ID manipulado de outra obra do mesmo tenant nunca
     * resolve nada aqui.
     */
    #[Computed]
    public function atividadeDetalhePlano(): ?array
    {
        if (! $this->atividadeDetalheId) {
            return null;
        }

        $at = Atividade::with([
            'restricoes' => fn ($q) => $q
                ->with(['categoria:id,nome', 'responsavel:id,first_name,last_name', 'atividade:id,obra_id'])
                ->orderByRaw("FIELD(status,'aberta','em_tratamento','aguardando_terceiros','resolvida')"),
            'frenteTrabalho:id,nome',
            'disciplina:id,nome',
            // Mesma fonte canônica de Atividade::scopeProntas() (Ciclo 18,
            // 18.4.CORREÇÃO) — nunca uma leitura paralela de GED.
            'documentosEngenharia.latestRevisao.ultimaLiberacao',
            'documentosEngenharia.latestRevisao.statusDocumento',
        ])->where('obra_id', $this->obra->id)->find($this->atividadeDetalheId);

        if (! $at) {
            return null;
        }

        $itens = $this->itensProntidao;
        $registros = $itens->isNotEmpty()
            ? AtividadeItemProntidao::where('atividade_id', $at->id)
                ->with('conclusor:id,first_name,last_name')
                ->get()
                ->keyBy('item_prontidao_id')
            : collect();

        $documentosDaObra = $at->documentosEngenharia->filter(fn ($documento) => $documento->obra_id === $at->obra_id);

        $documentosBloqueantes = $documentosDaObra
            ->reject(fn ($documento) => $documento->estaLiberadoParaConstrucao())
            ->map(fn ($documento) => [
                'codigo' => $documento->codigo,
                'revisaoVigente' => $documento->revisaoVigente()?->revisao,
                'motivo' => $documento->motivoLiberacao(),
            ])
            ->values();

        // Melhoria "Posto Operacional" — lista completa (liberados E não
        // liberados) pra seção "Documentos de Engenharia" do popup, nunca
        // só os bloqueantes (que já servem só pro resumo/badge).
        $documentosPopup = $documentosDaObra
            ->map(fn ($documento) => [
                'id' => $documento->id,
                'codigo' => $documento->codigo,
                'descricao' => $documento->descricao,
                'revisaoVigente' => $documento->revisaoVigente()?->revisao,
                'liberado' => $documento->estaLiberadoParaConstrucao(),
                'motivo' => $documento->motivoLiberacao(),
            ])
            ->values();

        return [
            'atividade' => $at,
            'checklist' => $itens->map(fn ($item) => [
                'id' => $item->id,
                'nome' => $item->nome,
                'concluido' => (bool) ($registros->get($item->id)?->concluido ?? false),
                'concluido_por' => $registros->get($item->id)?->conclusor,
                'concluido_em' => $registros->get($item->id)?->concluido_em,
            ]),
            'documentosBloqueantes' => $documentosBloqueantes,
            'documentos' => $documentosPopup,
            'documentosVinculadosIds' => $documentosDaObra->pluck('id'),
        ];
    }

    // =========================================================================
    // MELHORIA "POSTO OPERACIONAL" — % PREVISTO NO PERÍODO (Seção 11)
    // =========================================================================

    /**
     * "% previsto no período" = INCREMENTO dentro da semana selecionada
     * (não o acumulado até o fim dela) — fórmula já autoritativa,
     * reaproveitada sem invenção: `CurvaAvanco::calcular()` já retorna
     * `percentual_periodo` (HH deste período ÷ HH TOTAL da atividade, na
     * série phased real) por ponto — matematicamente idêntico a
     * `acumulado[fim da semana] - acumulado[fim da semana anterior]`,
     * porque `acumuladoExibido` já é uma soma corrida. Nunca distribui
     * linearmente (o dado phased de `avanco_periodos` já é semanal de
     * origem, Ciclo 13). Mesma Linha de Base já selecionada na página
     * ($this->linhaBaseId, "Referência de HH total") — nunca uma segunda
     * seleção só pro popup.
     */
    #[Computed]
    public function percentualPrevistoPeriodoPopup(): ?array
    {
        $detalhe = $this->atividadeDetalhePlano;
        if (! $detalhe) {
            return null;
        }

        $atividadeId = $detalhe['atividade']->id;

        $pontosPrevisto = app(CurvaAvanco::class)->calcular(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Semanal,
            linhaBaseId: $this->linhaBaseId,
            atividadeId: $atividadeId,
        );
        $pontoDaSemana = collect($pontosPrevisto)->firstWhere('periodo_inicio', $this->semanaInicio);

        // Realizado acumulado — mesma resolução "mais recente" já usada
        // em toda a página (nunca um seletor próprio pro popup, Seção 5
        // do pedido original: "mostrar quando barato e consistente").
        $pontosRealizado = app(CurvaAvanco::class)->calcular(
            $this->obra,
            SerieAvanco::Realizado,
            GranularidadePeriodo::Semanal,
            atividadeId: $atividadeId,
        );
        $ultimoRealizado = collect($pontosRealizado)->last();

        return [
            'percentual_previsto_periodo' => $pontoDaSemana['percentual_periodo'] ?? null,
            'percentual_previsto_acumulado' => $pontoDaSemana['percentual'] ?? null,
            'percentual_realizado_acumulado' => $ultimoRealizado['percentual'] ?? null,
            'tem_previsto' => ! empty($pontosPrevisto),
            'tem_realizado' => ! empty($pontosRealizado),
        ];
    }

    /**
     * Toggle de item de prontidão — mirror exato de
     * `⚡lookahead.blade.php::marcarItemNaDetalhe()` (mesma ausência de
     * `authorize()` explícito: a mesma superfície de segurança já aceita
     * no Lookahead pra esta ação, nunca uma restrição nova inventada aqui).
     */
    public function marcarItemNaDetalhe(string $atividadeId, string $itemId, bool $valor): void
    {
        $this->transacaoSegura(function () use ($atividadeId, $itemId, $valor) {
            AtividadeItemProntidao::updateOrCreate(
                ['atividade_id' => $atividadeId, 'item_prontidao_id' => $itemId],
                ['concluido' => $valor, 'concluido_por' => $valor ? Auth::id() : null, 'concluido_em' => $valor ? now() : null]
            );
        });

        $this->invalidarComputeds();
    }

    // =========================================================================
    // MELHORIA "POSTO OPERACIONAL" — DOCUMENTOS DE ENGENHARIA (Seção 12)
    // Reaproveita 100% a mesma relação N:N e o mesmo padrão inline
    // (busca + botão, sem modal) já usado em
    // ⚡documentos-engenharia.blade.php::atividadesParaVincular()/
    // vincularAtividade()/desvincularAtividade() — só invertendo o lado
    // (aqui parte-se da Atividade, lá parte-se do Documento). MESMA
    // Policy (`engenharia.pacotes`), nunca uma nova/mais permissiva
    // inventada só pra este popup.
    // =========================================================================

    private function garantirPermissaoEngenharia(string $acao): void
    {
        abort_unless(Auth::user()?->temPermissaoNaObra($this->obra->id, 'engenharia.pacotes', $acao), 403);
    }

    #[Computed]
    public function documentosDisponiveisParaVincular(): Collection
    {
        $detalhe = $this->atividadeDetalhePlano;
        if (! $detalhe) {
            return collect();
        }

        $jaVinculadosIds = $detalhe['documentosVinculadosIds'];
        $busca = $this->buscaDocumentoVincular;

        return DocumentoEngenharia::where('obra_id', $this->obra->id)
            ->whereNotIn('id', $jaVinculadosIds->isEmpty() ? ['__nenhum__'] : $jaVinculadosIds)
            ->when($busca !== '', fn ($q) => $q->where(function ($sub) use ($busca) {
                $sub->where('codigo', 'like', "%{$busca}%")
                    ->orWhere('descricao', 'like', "%{$busca}%");
            }))
            ->orderBy('codigo')
            ->limit(20)
            ->get(['id', 'codigo', 'descricao']);
    }

    public function updatedBuscaDocumentoVincular(): void
    {
        unset($this->documentosDisponiveisParaVincular);
    }

    public function vincularDocumento(string $documentoId): void
    {
        $this->garantirPermissaoEngenharia('editar');

        $atividade = Atividade::where('obra_id', $this->obra->id)->findOrFail($this->atividadeDetalheId);
        $documento = DocumentoEngenharia::where('obra_id', $this->obra->id)->findOrFail($documentoId);

        $jaVinculado = $atividade->documentosEngenharia()->where('documento_engenharia_id', $documento->id)->exists();
        if (! $jaVinculado) {
            abort_if($documento->trashed(), 403, 'Este Documento está arquivado e não pode ser vinculado.');
        }

        $this->transacaoSegura(function () use ($atividade, $documento) {
            // syncWithoutDetaching é idempotente (mesmo idioma de
            // ⚡documentos-engenharia.blade.php) — nunca duplica linha
            // no pivô num duplo-clique.
            $atividade->documentosEngenharia()->syncWithoutDetaching([$documento->id]);
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->buscaDocumentoVincular = '';
        $this->invalidarComputeds();
        $this->dispatch('show-toast', message: 'Documento vinculado.');
    }

    /**
     * Confirmação explícita (via confirmarAcao() no Blade, mesmo
     * componente genérico já usado em toda a árvore GED) SEMPRE exigida
     * antes de chamar este método — mesmo padrão do botão irmão em
     * ⚡documentos-engenharia.blade.php, nunca condicional a "só quando
     * isso libera a atividade" (o estado sempre é recalculado e exibido
     * imediatamente pelo re-render do popup, então a confirmação
     * uniforme já cobre o item 12 do pedido sem lógica nova).
     */
    public function desvincularDocumento(string $documentoId): void
    {
        $this->garantirPermissaoEngenharia('editar');

        $atividade = Atividade::where('obra_id', $this->obra->id)->findOrFail($this->atividadeDetalheId);
        $documento = DocumentoEngenharia::where('obra_id', $this->obra->id)->findOrFail($documentoId);

        abort_unless(
            $atividade->documentosEngenharia()->where('documento_engenharia_id', $documento->id)->exists(),
            404
        );

        $this->transacaoSegura(function () use ($atividade, $documento) {
            $atividade->documentosEngenharia()->detach($documento->id);
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->invalidarComputeds();
        $this->dispatch('show-toast', message: 'Documento desvinculado.');
    }

    // =========================================================================
    // MELHORIA "POSTO OPERACIONAL" — MATERIAIS PARA EXECUÇÃO (Seções 6/7/9/13/14)
    // AtividadeNecessidadeMaterial é a fonte autoritativa desta pergunta
    // (arquitetura B híbrida aprovada) — NUNCA participa da cadeia
    // TakeOff→RP→Pacote→RC/Pedido→Recebimento, que continua intocada.
    // Único ponto de escrita: App\Actions\Estoque\
    // AtualizarNecessidadeMaterialAtividade — nunca create()/update()
    // direto no model aqui.
    // =========================================================================

    /**
     * Cobertura por linha de necessidade — classe única fora do Blade
     * (Seção 9), nunca uma segunda fórmula aqui. Sob demanda (só quando
     * o popup está aberto), nunca em lote pra todas as atividades da
     * árvore (Seção 15 — performance).
     */
    #[Computed]
    public function coberturaMateriaisPopup(): Collection
    {
        $detalhe = $this->atividadeDetalhePlano;
        if (! $detalhe) {
            return collect();
        }

        return CoberturaNecessidadeAtividadeQuery::porAtividade($detalhe['atividade']);
    }

    /** Pacotes de Compra já vinculados a esta Atividade (item_suprimento_atividades) — únicos elegíveis pra "Reservar agora" (CriarReservaEstoque exige um Pacote). */
    #[Computed]
    public function pacotesDaAtividadePopup(): Collection
    {
        $detalhe = $this->atividadeDetalhePlano;
        if (! $detalhe) {
            return collect();
        }

        return $detalhe['atividade']->itensSuprimento()->orderBy('nome')->get(['itens_suprimento.id', 'itens_suprimento.nome', 'itens_suprimento.codigo']);
    }

    #[Computed]
    public function locaisParaReserva(): Collection
    {
        return LocalEstoque::where('obra_id', $this->obra->id)
            ->where('ativo', true)
            ->where('tipo', '!=', TipoLocalEstoque::Terceiro->value)
            ->orderBy('nome')
            ->get(['id', 'nome']);
    }

    #[Computed]
    public function itensTakeOffParaNecessidade(): Collection
    {
        $busca = $this->buscaItemTakeOffNecessidade;
        if ($busca === '' || strlen($busca) < 2) {
            return collect();
        }

        return ItemTakeOff::whereHas('lista.revisao.documento', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->where(function ($q) use ($busca) {
                $q->where('codigo', 'like', "%{$busca}%")
                    ->orWhere('descricao', 'like', "%{$busca}%");
            })
            ->orderBy('codigo')
            ->limit(20)
            ->get(['id', 'codigo', 'descricao', 'quantidade']);
    }

    public function updatedBuscaItemTakeOffNecessidade(): void
    {
        unset($this->itensTakeOffParaNecessidade);
    }

    /** Saldo/já-distribuído do ItemTakeOff selecionado — mostrado ANTES de salvar (Seção 14). */
    #[Computed]
    public function saldoItemTakeOffSelecionado(): ?array
    {
        if (! $this->itemTakeOffIdNovaNecessidade) {
            return null;
        }

        $item = ItemTakeOff::find($this->itemTakeOffIdNovaNecessidade);
        if (! $item) {
            return null;
        }

        return [
            'quantidade_take_off' => (float) $item->quantidade,
            'distribuido' => ConciliacaoNecessidadeAtividade::quantidadeDistribuida($item->id, $this->necessidadeEditandoId),
            'saldo' => ConciliacaoNecessidadeAtividade::saldoADistribuir($item, $this->necessidadeEditandoId),
        ];
    }

    #[Computed]
    public function materiaisParaNecessidade(): Collection
    {
        $busca = $this->buscaMaterialNecessidade;
        if ($busca === '' || strlen($busca) < 2) {
            return collect();
        }

        return Material::where('ativo', true)
            ->where(function ($q) use ($busca) {
                $q->where('codigo', 'like', "%{$busca}%")
                    ->orWhere('descricao', 'like', "%{$busca}%");
            })
            ->orderBy('codigo')
            ->limit(20)
            ->get(['id', 'codigo', 'descricao']);
    }

    public function updatedBuscaMaterialNecessidade(): void
    {
        unset($this->materiaisParaNecessidade);
    }

    // =========================================================================
    // MELHORIA "POSTO OPERACIONAL" — CRIAÇÃO INLINE DE MATERIAL MESTRE
    // (fechamento técnico, rodada de proveniência). Só disponível pra
    // origem=operacional (Seção 4 do pedido: origem=take_off nunca
    // oferece isso — o Material dessa origem é sempre derivado do
    // ItemTakeOff via App\Actions\Estoque\AssociarMaterialAoItemTakeOff,
    // um fluxo/domínio inteiramente separado, com suas próprias regras
    // de imutabilidade). Reaproveita a MESMA Action
    // (App\Actions\Estoque\CriarMaterial) e as MESMAS regras de
    // validação já usadas por ⚡estoque.blade.php::salvarMaterial() —
    // nunca uma segunda versão simplificada do cadastro.
    // =========================================================================

    #[Computed]
    public function unidadesMedidaParaNovoMaterial(): Collection
    {
        return UnidadeMedida::where('ativo', true)->orderBy('codigo')->get();
    }

    #[Computed]
    public function familiasMaterialParaNovoMaterial(): Collection
    {
        return FamiliaMaterial::where('ativo', true)->orderBy('nome')->get();
    }

    /**
     * Mesma permissão que já autoriza criar Material na tela de Estoque
     * (`estoque.movimentacao|criar`) — nunca uma permissão nova/mais
     * permissiva inventada só pra esta entrada inline. Um usuário com
     * `restricoes.plano_semanal|editar` (que já autoriza abrir o modal
     * de necessidade) mas SEM esta outra permissão nunca vê a opção de
     * criar Material — as duas concessões são independentes por design
     * (Perfil/PerfilPermissao são customizáveis por tenant).
     */
    private function podeCriarMaterialInline(): bool
    {
        return (bool) Auth::user()?->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', 'criar');
    }

    public function abrirModalNovoMaterial(): void
    {
        $this->garantirPermissaoNecessidade();
        abort_unless($this->podeCriarMaterialInline(), 403);

        $this->novoMaterialCodigo = '';
        // Pré-preenche a descrição com o texto já digitado na busca —
        // o usuário normalmente só chega aqui depois de procurar e não
        // encontrar, nunca deveria precisar redigitar o que já procurou.
        $this->novoMaterialDescricao = $this->buscaMaterialNecessidade;
        $this->novoMaterialUnidadeMedidaId = null;
        $this->novoMaterialFamiliaId = null;
        $this->novoMaterialModoRastreabilidade = ModoRastreabilidadeMaterial::Quantitativo->value;
        $this->resetErrorBag();
        $this->modalNovoMaterialAberto = true;
    }

    public function fecharModalNovoMaterial(): void
    {
        $this->modalNovoMaterialAberto = false;
    }

    /**
     * Cria SÓ o Material Mestre — nunca a necessidade da atividade
     * (Seção 8/9 do pedido: são duas operações distintas; cancelar a
     * necessidade depois nunca apaga o Material já confirmado aqui).
     * Material recém-criado fica automaticamente selecionado no modal
     * de necessidade, que permanece aberto — sem reload, sem navegação.
     */
    public function salvarNovoMaterialInline(): void
    {
        $this->garantirPermissaoNecessidade();
        abort_unless($this->podeCriarMaterialInline(), 403);

        $this->validate([
            'novoMaterialCodigo' => 'required|string|max:100',
            'novoMaterialDescricao' => 'required|string|max:255',
            'novoMaterialUnidadeMedidaId' => 'required|exists:unidades_medida,id',
            'novoMaterialFamiliaId' => 'nullable|exists:familias_material,id',
            'novoMaterialModoRastreabilidade' => 'required|in:' . implode(',', array_map(fn ($c) => $c->value, ModoRastreabilidadeMaterial::cases())),
        ], [], [
            'novoMaterialCodigo' => 'código',
            'novoMaterialDescricao' => 'descrição',
            'novoMaterialUnidadeMedidaId' => 'unidade de medida',
            'novoMaterialFamiliaId' => 'família',
        ]);

        try {
            $material = app(CriarMaterial::class)->execute(
                $this->novoMaterialCodigo,
                $this->novoMaterialDescricao,
                $this->novoMaterialUnidadeMedidaId,
                $this->novoMaterialFamiliaId,
                $this->novoMaterialModoRastreabilidade,
                OrigemCadastroMaterial::PlanoSemanal,
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                $this->addError('novoMaterialCodigo', 'Já existe um Material com este código no catálogo.');

                return;
            }

            throw $e;
        } catch (\App\Exceptions\MaterialInvalidoException $e) {
            // Hardening (fechamento pós-relatório) — payload manipulado
            // com unidade_medida_id/familia_material_id de outro tenant:
            // a Action já rejeitou ANTES de qualquer escrita, nunca
            // confiado só ao exists:.. do validate() acima.
            $this->addError('novoMaterialUnidadeMedidaId', $e->getMessage());

            return;
        }

        $this->materialIdNovaNecessidade = $material->id;
        $this->buscaMaterialNecessidade = $material->codigo;
        unset($this->materiaisParaNecessidade);

        $this->modalNovoMaterialAberto = false;
        $this->dispatch('show-toast', message: 'Material cadastrado e selecionado.');
    }

    private function resetModalNecessidade(): void
    {
        $this->necessidadeEditandoId = null;
        $this->origemNovaNecessidade = 'take_off';
        $this->itemTakeOffIdNovaNecessidade = null;
        $this->materialIdNovaNecessidade = null;
        $this->quantidadeNovaNecessidade = null;
        $this->observacaoNovaNecessidade = '';
        $this->buscaItemTakeOffNecessidade = '';
        $this->buscaMaterialNecessidade = '';
        $this->modalNovoMaterialAberto = false;
        $this->resetErrorBag();
    }

    public function abrirModalNecessidade(): void
    {
        $this->garantirPermissaoNecessidade();
        $this->resetModalNecessidade();
        $this->modalNecessidadeAberto = true;
    }

    /**
     * Nunca confia só em `AtividadeNecessidadeMaterial.atividade_id` cru
     * (poderia apontar pra uma atividade de OUTRA obra do mesmo tenant,
     * já que `$this->atividadeDetalheId` é propriedade pública Livewire e
     * pode chegar manipulada) — sempre resolve a Atividade NA OBRA ATUAL
     * primeiro, e só então a necessidade dentro dela.
     */
    private function resolverNecessidadeDaAtividadeAtual(string $necessidadeId): AtividadeNecessidadeMaterial
    {
        $atividade = Atividade::where('obra_id', $this->obra->id)->findOrFail($this->atividadeDetalheId);

        return AtividadeNecessidadeMaterial::where('atividade_id', $atividade->id)->findOrFail($necessidadeId);
    }

    /** Pré-carrega o formulário com a linha existente — identidade (origem/item/material) nunca é editável, só quantidade/observação. */
    public function abrirEdicaoNecessidade(string $necessidadeId): void
    {
        $this->garantirPermissaoNecessidade();

        $necessidade = $this->resolverNecessidadeDaAtividadeAtual($necessidadeId);

        $this->resetModalNecessidade();
        $this->necessidadeEditandoId = $necessidade->id;
        $this->origemNovaNecessidade = $necessidade->origem->value;
        $this->itemTakeOffIdNovaNecessidade = $necessidade->item_take_off_id;
        $this->materialIdNovaNecessidade = $necessidade->material_id;
        $this->quantidadeNovaNecessidade = (float) $necessidade->quantidade_necessaria;
        $this->observacaoNovaNecessidade = $necessidade->observacao ?? '';
        $this->modalNecessidadeAberto = true;
    }

    /**
     * Melhoria "Posto Operacional" — reaproveita `restricoes.plano_semanal`
     * (já `editar => Encarregado`, mesmo perfil que já comprometer/marca
     * concluída nesta própria página) — nenhum slug novo criado só pra
     * esta ação, decisão consciente de não inventar permissão sem
     * necessidade concreta.
     */
    private function garantirPermissaoNecessidade(): void
    {
        abort_unless(Auth::user()?->temPermissaoNaObra($this->obra->id, 'restricoes.plano_semanal', 'editar'), 403);
    }

    public function salvarNecessidade(): void
    {
        $this->garantirPermissaoNecessidade();

        $this->validate([
            'quantidadeNovaNecessidade' => 'required|numeric|gt:0',
        ], [], ['quantidadeNovaNecessidade' => 'quantidade']);

        $atividade = Atividade::where('obra_id', $this->obra->id)->findOrFail($this->atividadeDetalheId);
        $action = app(AtualizarNecessidadeMaterialAtividade::class);

        // Ação chamada DIRETO (nunca via transacaoSegura()) — mesmo padrão
        // já estabelecido em ⚡estoque.blade.php::confirmarReserva() pra
        // Actions que já fazem sua PRÓPRIA DB::transaction() internamente
        // e lançam exceções de domínio específicas: transacaoSegura()
        // captura QUALQUER \Throwable não-Authorization/Validation e o
        // converteria num toast genérico, escondendo a mensagem
        // específica (ex.: "saldo insuficiente") que o usuário precisa
        // ver no campo certo do formulário.
        try {
            if ($this->necessidadeEditandoId) {
                $necessidade = AtividadeNecessidadeMaterial::where('atividade_id', $atividade->id)->findOrFail($this->necessidadeEditandoId);
                $action->alterar($necessidade, (float) $this->quantidadeNovaNecessidade, $this->observacaoNovaNecessidade ?: null);
            } elseif ($this->origemNovaNecessidade === OrigemNecessidadeMaterialAtividade::TakeOff->value) {
                $this->validate(['itemTakeOffIdNovaNecessidade' => 'required|exists:itens_take_off,id']);
                $item = ItemTakeOff::findOrFail($this->itemTakeOffIdNovaNecessidade);
                $action->criarTakeOff($atividade, $item, (float) $this->quantidadeNovaNecessidade, Auth::user(), $this->observacaoNovaNecessidade ?: null);
            } else {
                $this->validate([
                    'materialIdNovaNecessidade' => 'required|exists:materiais,id',
                    'observacaoNovaNecessidade' => 'required|string|min:5',
                ], [], ['observacaoNovaNecessidade' => 'justificativa']);
                $material = Material::findOrFail($this->materialIdNovaNecessidade);
                $action->criarOperacional($atividade, $material, (float) $this->quantidadeNovaNecessidade, $this->observacaoNovaNecessidade, Auth::user());
            }
        } catch (NecessidadeMaterialAtividadeInvalidaException|SaldoNecessidadeInsuficienteException $e) {
            $this->addError('quantidadeNovaNecessidade', $e->getMessage());

            return;
        }

        $this->modalNecessidadeAberto = false;
        $this->resetModalNecessidade();
        $this->invalidarComputeds();
        $this->dispatch('show-toast', message: 'Necessidade de material salva.');
    }

    public function removerNecessidade(string $necessidadeId): void
    {
        $this->garantirPermissaoNecessidade();

        $necessidade = $this->resolverNecessidadeDaAtividadeAtual($necessidadeId);

        try {
            app(AtualizarNecessidadeMaterialAtividade::class)->remover($necessidade);
        } catch (NecessidadeMaterialAtividadeInvalidaException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->invalidarComputeds();
        $this->dispatch('show-toast', message: 'Necessidade removida.');
    }

    // ---- "Reservar agora" (Seção 7) — nunca automático, sempre 1 clique humano explícito ----

    public function abrirModalReservar(string $necessidadeId): void
    {
        $this->garantirPermissaoNecessidade();

        $necessidade = $this->resolverNecessidadeDaAtividadeAtual($necessidadeId);
        $cobertura = $this->coberturaMateriaisPopup->firstWhere('necessidade.id', $necessidade->id);

        $this->necessidadeReservandoId = $necessidade->id;
        $this->pacoteIdReserva = $this->pacotesDaAtividadePopup->first()?->id;
        $this->localIdReserva = null;
        $this->quantidadeReserva = $cobertura['faltante_para_reservar'] ?? null;
        $this->resetErrorBag();
    }

    private function resetModalReservar(): void
    {
        $this->necessidadeReservandoId = null;
        $this->pacoteIdReserva = null;
        $this->localIdReserva = null;
        $this->quantidadeReserva = null;
        $this->resetErrorBag();
    }

    public function confirmarReserva(): void
    {
        $this->garantirPermissaoNecessidade();

        $this->validate([
            'pacoteIdReserva' => 'required|exists:itens_suprimento,id',
            'localIdReserva' => 'required|exists:locais_estoque,id',
            'quantidadeReserva' => 'required|numeric|gt:0',
        ], [], [
            'pacoteIdReserva' => 'Pacote de Compra',
            'localIdReserva' => 'Local de Estoque',
            'quantidadeReserva' => 'quantidade',
        ]);

        $atividade = Atividade::where('obra_id', $this->obra->id)->findOrFail($this->atividadeDetalheId);
        $necessidade = AtividadeNecessidadeMaterial::where('atividade_id', $atividade->id)->findOrFail($this->necessidadeReservandoId);
        $pacote = ItemSuprimento::findOrFail($this->pacoteIdReserva);

        // Nunca confia só na FK: o Pacote precisa ser um dos REALMENTE
        // vinculados a esta atividade (item_suprimento_atividades) —
        // CriarReservaEstoque já valida obra do Pacote x obra do Local,
        // mas nunca sabe de "atividade", então essa checagem é só nossa.
        abort_unless($atividade->itensSuprimento()->where('itens_suprimento.id', $pacote->id)->exists(), 403);

        $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->localIdReserva);
        $material = $necessidade->material();
        abort_if(! $material, 404);

        // Mesma disciplina de salvarNecessidade()/removerNecessidade():
        // Action chamada direto (nunca via transacaoSegura()), que já faz
        // sua própria DB::transaction() e lança exceções de domínio
        // específicas que precisam chegar ao campo certo do formulário.
        try {
            app(CriarReservaEstoque::class)->execute(
                $pacote,
                $material,
                $local,
                (float) $this->quantidadeReserva,
                usuario: Auth::user(),
                necessidade: $necessidade,
            );
        } catch (ReservaEstoqueInvalidaException|SaldoFisicoInsuficienteException $e) {
            $this->addError('quantidadeReserva', $e->getMessage());

            return;
        }

        $this->resetModalReservar();
        $this->invalidarComputeds();
        $this->dispatch('show-toast', message: 'Material reservado para esta atividade.');
    }

    // =========================================================================
    // MODAL: CRIAR RESTRIÇÃO (mesmos campos/fluxo/Policy de
    // ⚡lookahead.blade.php — nunca uma segunda regra de criação)
    // =========================================================================

    public function abrirModalRestricao(string $atividadeId): void
    {
        $this->authorize('create', [Restricao::class, $this->obra->id]);
        $this->resetModalRestricao();
        $this->atividadeIdRestricao = $atividadeId;
        $this->modalRestricaoAberto = true;
    }

    private function resetModalRestricao(): void
    {
        $this->atividadeIdRestricao = null;
        $this->descricaoNova = '';
        $this->blocanteNova = true;
        $this->prazolimiteNova = null;
        $this->probabilidadeNova = null;
        $this->impactoNova = null;
        $this->categoriaIdNova = null;
        $this->responsavelIdNova = null;
        $this->responsavelExternoNova = '';
        $this->responsavelExterno = false;
        $this->resetErrorBag();
    }

    public function salvarRestricao(): void
    {
        $this->authorize('create', [Restricao::class, $this->obra->id]);

        $this->validate(
            [
                'atividadeIdRestricao' => 'required|string|exists:atividades,id',
                'descricaoNova' => 'required|string|min:5',
                'probabilidadeNova' => 'nullable|integer|min:0|max:10',
                'impactoNova' => 'nullable|integer|min:0|max:10',
                'prazolimiteNova' => 'nullable|date',
                'categoriaIdNova' => 'nullable|exists:categorias_restricao,id',
                'responsavelIdNova' => 'nullable|exists:users,id',
            ],
            [],
            ['descricaoNova' => 'descrição']
        );

        $restricao = $this->transacaoSegura(fn () => Restricao::create([
            'atividade_id' => $this->atividadeIdRestricao,
            'descricao' => $this->descricaoNova,
            'bloqueante' => $this->blocanteNova,
            'probabilidade' => $this->probabilidadeNova,
            'impacto' => $this->impactoNova,
            'prazo_limite' => $this->prazolimiteNova,
            'categoria_id' => $this->categoriaIdNova ?: null,
            'responsavel_id' => ! $this->responsavelExterno ? ($this->responsavelIdNova ?: null) : null,
            'responsavel_externo' => $this->responsavelExterno ? ($this->responsavelExternoNova ?: null) : null,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]));

        if (! $restricao) {
            return;
        }

        $this->modalRestricaoAberto = false;
        $this->resetModalRestricao();
        $this->dispatch('show-toast', message: 'Restrição registrada.');
        $this->invalidarComputeds();
    }

    // =========================================================================
    // MODAL: DAR BAIXA NA RESTRIÇÃO (mesmo fluxo/Policy de
    // ⚡lookahead.blade.php — nunca uma segunda regra de resolução)
    // =========================================================================

    public function abrirModalBaixa(string $restricaoId): void
    {
        $restricao = Restricao::findOrFail($restricaoId);
        $this->authorize('resolver', $restricao);

        $this->baixandoRestricaoId = $restricaoId;
        $this->dataBaixaNova = now()->toDateString();
        $this->textoBaixaNova = '';
        $this->resetErrorBag();
    }

    private function resetModalBaixa(): void
    {
        $this->baixandoRestricaoId = null;
        $this->dataBaixaNova = null;
        $this->textoBaixaNova = '';
        $this->resetErrorBag();
    }

    public function darBaixaRestricao(): void
    {
        $restricao = Restricao::findOrFail($this->baixandoRestricaoId);
        $this->authorize('resolver', $restricao);

        $this->validate(
            [
                'dataBaixaNova' => 'required|date|before_or_equal:today',
                'textoBaixaNova' => 'nullable|string',
            ],
            [],
            ['dataBaixaNova' => 'data da baixa']
        );

        $this->transacaoSegura(function () use ($restricao) {
            if ($this->textoBaixaNova !== '') {
                $restricao->acoes()->create(['autor_id' => Auth::id(), 'descricao' => $this->textoBaixaNova]);
            }

            $restricao->update([
                'status' => StatusRestricao::Resolvida->value,
                'resolvida_em' => Carbon::parse($this->dataBaixaNova),
            ]);
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->resetModalBaixa();
        $this->dispatch('show-toast', message: 'Restrição resolvida.');
        $this->invalidarComputeds();
    }

    /**
     * Só pode entrar na seleção pra "Programar selecionadas": ainda
     * Planejado, liberada E não concluída no cronograma importado
     * (`percentual_concluido` — o "Percent Complete" do MSPDI, valor
     * independente do `status` do app; ver `App\Support\
     * ConclusaoAutomaticaAtividades`, que já reconhece 100% como sinal
     * de conclusão real vindo da importação). Não confundir com "Ocultar
     * concluídas" (preferência de exibição da árvore, baseada em
     * `status`) — aqui é regra de ELEGIBILIDADE pra nova programação.
     */
    #[Computed]
    public function idsSelecionaveis(): array
    {
        return $this->atividades
            ->filter(fn ($at) => $at->status === StatusAtividade::Planejado
                && $this->idsProntas->contains($at->id)
                && (float) ($at->percentual_concluido ?? 0) < 100)
            ->pluck('id')
            ->all();
    }

    /**
     * IDs das atividades (do conjunto atualmente filtrado) que já
     * estiveram em QUALQUER programação semanal salva antes — histórico
     * real via `programacao_semanal_itens`, não só a semana/edição
     * atual. Uma linha nessa tabela só existe depois de um
     * comprometimento de verdade (`RegistrarComprometimentoSemanal`),
     * então qualquer atividade com pelo menos uma linha aqui já foi
     * "programada" alguma vez — inclusive a própria semana vigente,
     * se já tiver sido comprometida. Consulta única agregada (mesmo
     * cuidado de idsProntas), nunca N+1 por linha.
     */
    #[Computed]
    public function idsJaProgramadas(): \Illuminate\Support\Collection
    {
        $ids = $this->atividades->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return ProgramacaoSemanalItem::whereIn('atividade_id', $ids)->distinct()->pluck('atividade_id');
    }

    /**
     * % Previsto do período filtrado = HH da Linha de Base (série
     * Previsto/Baseline Work) das atividades visíveis (via
     * `hhPrevistoSemanaPorAtividade`, mesma coleção reaproveitada pelos
     * outros cards de HH) ÷
     * HH total do projeto (`totalHhProjeto`, já respeita `linhaBaseId`).
     * Reaproveita as duas fontes existentes — não recalcula HH por conta
     * própria.
     */
    #[Computed]
    public function percentualAvancoPrevistoPeriodo(): ?float
    {
        if ($this->totalHhProjeto <= 0) {
            return null;
        }

        $hhPeriodo = $this->atividades->sum(
            fn ($at) => (float) ($this->hhPrevistoSemanaPorAtividade->get($at->id) ?? 0)
        );

        return round(($hhPeriodo / $this->totalHhProjeto) * 100, 2);
    }

    /**
     * HH da Tendência (série Work atual, não a Linha de Base) desta
     * semana por atividade — mesmo formato/fonte de
     * `hhPrevistoSemanaPorAtividade`, só trocando a série. Sem
     * equivalente congelado: `ProgramacaoSemanalItem` só guarda o HH
     * Previsto no momento do commit (`horas_previstas_congeladas`), não
     * um snapshot de Tendência — numa semana congelada, o percentual
     * correspondente vira `null` (mesmo idioma de "não reavaliado" já
     * usado por `idsProntas`/liberação nesse cenário).
     */
    #[Computed]
    public function hhTendenciaSemanaPorAtividade(): \Illuminate\Support\Collection
    {
        if ($this->semanaEstaCongelada) {
            return collect();
        }

        $ids = $this->atividades->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return AvancoPeriodo::where('serie', SerieAvanco::Tendencia->value)
            ->where('granularidade', GranularidadePeriodo::Semanal->value)
            ->where('periodo_inicio', $this->semanaInicio)
            ->whereIn('atividade_id', $ids)
            ->pluck('horas', 'atividade_id');
    }

    /** HH total do projeto (Tendência/Mensal) — mesma resolução de importação mais recente elegível já usada no Lookahead. */
    #[Computed]
    public function totalHhTendenciaProjeto(): float
    {
        return app(CurvaAvanco::class)->totalCalculado(
            $this->obra, SerieAvanco::Tendencia, GranularidadePeriodo::Mensal
        );
    }

    /** % da Tendência do período filtrado = HH de Tendência das atividades visíveis ÷ HH total de Tendência do projeto. */
    #[Computed]
    public function percentualTendenciaPeriodo(): ?float
    {
        if ($this->semanaEstaCongelada || $this->totalHhTendenciaProjeto <= 0) {
            return null;
        }

        $hhPeriodo = $this->atividades->sum(
            fn ($at) => (float) ($this->hhTendenciaSemanaPorAtividade->get($at->id) ?? 0)
        );

        return round(($hhPeriodo / $this->totalHhTendenciaProjeto) * 100, 2);
    }

    #[Computed]
    public function totalAtividadesPeriodo(): int
    {
        return $this->atividades->count();
    }

    #[Computed]
    public function totalSemRestricao(): int
    {
        return $this->idsProntas->count();
    }

    #[Computed]
    public function totalComRestricao(): int
    {
        return $this->totalAtividadesPeriodo - $this->totalSemRestricao;
    }

    /**
     * % de avanço das atividades LIBERADAS do período = HH previsto
     * (Linha de Base) só das atividades sem restrição/prontidão pendente
     * (`idsProntas`) ÷ HH total do projeto (`totalHhProjeto`) — mesmo
     * estilo do card "% Previsto", só que restrito ao subconjunto
     * liberado. Reaproveita `hhPrevistoSemanaPorAtividade`, nunca
     * recalcula HH por conta própria. `null` numa semana congelada
     * (liberação não é reavaliada nesse cenário — mesmo idioma de
     * `idsProntas`/`percentualTendenciaPeriodo`).
     */
    #[Computed]
    public function percentualAvancoLiberadasPeriodo(): ?float
    {
        if ($this->semanaEstaCongelada || $this->totalHhProjeto <= 0) {
            return null;
        }

        $hhLiberadas = $this->atividades
            ->filter(fn ($at) => $this->idsProntas->contains($at->id))
            ->sum(fn ($at) => (float) ($this->hhPrevistoSemanaPorAtividade->get($at->id) ?? 0));

        return round(($hhLiberadas / $this->totalHhProjeto) * 100, 2);
    }

    /**
     * Compara dois códigos de EAP (ex: "5.1.10" vs "5.1.3") segmento a
     * segmento como números — mesmo helper usado no Lookahead e em
     * Linhas de Base, pra manter a mesma ordenação em todo o app.
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
     * Achata a EAP (pacotes + atividades da semana) numa árvore
     * expansível/recolhível, mesmo padrão de Lookahead/Linhas de Base —
     * só entram pacotes que levam a pelo menos uma atividade desta
     * semana (idsRelevantes), não a EAP inteira da obra.
     */
    #[Computed]
    public function arvoreAtividades(): array
    {
        $atividades = $this->atividades;
        if ($this->ocultarConcluidas) {
            $atividades = $atividades->where('status', '!=', StatusAtividade::Concluido);
        }
        // Filtro de exibição por liberação (mesma fonte que o KPI/coluna
        // Liberação, idsProntas) — numa semana congelada a liberação não é
        // reavaliada (idsProntas() já retorna vazio nesse caso), então o
        // filtro fica inerte de propósito, nunca escondendo tudo por engano.
        if (! $this->semanaEstaCongelada && $this->liberacaoFiltro !== 'todas') {
            $idsProntasArray = $this->idsProntas;
            $atividades = $this->liberacaoFiltro === 'liberadas'
                ? $atividades->filter(fn ($at) => $idsProntasArray->contains($at->id))
                : $atividades->reject(fn ($at) => $idsProntasArray->contains($at->id));
        }
        if ($atividades->isEmpty()) {
            return [];
        }

        $pacoteIdsComAtividade = $atividades->pluck('pacote_trabalho_id')->filter()->unique();

        $todosPacotes = PacoteTrabalho::where('obra_id', $this->obra->id)
            ->get(['id', 'nome', 'codigo', 'parent_id'])
            ->keyBy('id');

        $idsRelevantes = collect();
        foreach ($pacoteIdsComAtividade as $pid) {
            $atual = $pid;
            while ($atual && $todosPacotes->has($atual)) {
                $idsRelevantes->push($atual);
                $atual = $todosPacotes->get($atual)->parent_id;
            }
        }
        $idsRelevantes = $idsRelevantes->unique();

        $atividadesPorPacote = $atividades->groupBy(fn ($at) => $at->pacote_trabalho_id ?? 'sem_pacote');

        $ordenarGrupo = fn ($grupo) => $grupo->sort(fn ($a, $b) => $this->compararOrdemAtividade($a, $b))->values();

        $resultado = [];

        $percorrer = function (string $pacoteId, array $ancestrais) use (
            &$percorrer,
            &$resultado,
            $todosPacotes,
            $idsRelevantes,
            $atividadesPorPacote,
            $ordenarGrupo
        ) {
            $pacote = $todosPacotes->get($pacoteId);

            $resultado[] = [
                'tipo' => 'pacote',
                'id' => $pacote->id,
                'nivel' => count($ancestrais),
                'ancestrais' => $ancestrais,
                'pacote' => $pacote,
            ];

            $novosAncestrais = [...$ancestrais, $pacote->id];

            $filhos = $todosPacotes
                ->filter(fn ($p) => $p->parent_id === $pacoteId && $idsRelevantes->contains($p->id))
                ->sort(fn ($a, $b) => $this->compararCodigos($a->codigo, $b->codigo));

            foreach ($filhos as $filho) {
                $percorrer($filho->id, $novosAncestrais);
            }

            $grupo = $ordenarGrupo($atividadesPorPacote->get($pacoteId, collect()));
            foreach ($grupo as $at) {
                $resultado[] = [
                    'tipo' => 'atividade',
                    'id' => $at->id,
                    'nivel' => count($novosAncestrais),
                    'ancestrais' => $novosAncestrais,
                    'atividade' => $at,
                ];
            }
        };

        // Nível raiz: intercala pacotes raiz e atividades sem pacote com
        // código do cronograma (posição real no MS Project), na mesma
        // sequência — igual ao padrão de Lookahead/Linhas de Base.
        $raizes = $todosPacotes->filter(fn ($p) => $p->parent_id === null && $idsRelevantes->contains($p->id));

        $orfas = $atividadesPorPacote->get('sem_pacote', collect());
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
                $percorrer($entrada['payload']->id, []);
                continue;
            }

            $resultado[] = [
                'tipo' => 'atividade',
                'id' => $entrada['payload']->id,
                'nivel' => 0,
                'ancestrais' => [],
                'atividade' => $entrada['payload'],
            ];
        }

        // Órfãs sem código do cronograma (dado legado ou atividade
        // manual sem pacote): no final, ordenadas por data/nome.
        foreach ($ordenarGrupo($orfasSemCodigo) as $at) {
            $resultado[] = [
                'tipo' => 'atividade',
                'id' => $at->id,
                'nivel' => 0,
                'ancestrais' => [],
                'atividade' => $at,
            ];
        }

        return $resultado;
    }

    /**
     * Ciclo 24 — mesmo corte temporal do PPC canônico
     * (`⚡relatorios-restricoes.blade.php::ppcQuery()`) e de
     * `ProgramacaoSemanal::aderencia()`: "concluída" aqui também exige
     * `concluido_em <= semana_fim`, nunca só `status === Concluido` ao
     * vivo. Sem essa correção, navegar até uma semana passada já fechada
     * (via semanAnterior()) podia mostrar este card como "cumprida"
     * mesmo quando o PPC/Minhas Programações da MESMA semana continuavam
     * corretamente "não cumprida" — três lugares diferentes descrevendo
     * o mesmo compromisso de formas contraditórias.
     */
    #[Computed]
    public function ppc(): array
    {
        $total = $this->atividadesComprometidas->count();

        if ($total === 0) {
            return ['percentual' => null, 'concluidas' => 0, 'total' => 0];
        }

        $semanaFim = $this->semanaFim;

        $concluidas = $this->atividadesComprometidas
            ->where('status', StatusAtividade::Concluido)
            ->filter(fn ($atividade) => $atividade->concluido_em !== null && $atividade->concluido_em->toDateString() <= $semanaFim)
            ->count();

        return [
            'percentual' => round(($concluidas / $total) * 100),
            'concluidas' => $concluidas,
            'total'      => $total,
        ];
    }

    /**
     * Invalida todos os computeds derivados de atividades() — chamado
     * sempre que a semana ou os dados mudam. Os cards e a tabela reagem
     * sozinhos (Blade re-renderiza a cada ação), sem nenhum gráfico
     * imperativo pra redesenhar via evento.
     */
    private function invalidarComputeds(): void
    {
        unset(
            $this->atividades,
            $this->atividadesComprometidas,
            $this->idsProntas,
            $this->idsSelecionaveis,
            $this->idsJaProgramadas,
            $this->percentualAvancoPrevistoPeriodo,
            $this->hhTendenciaSemanaPorAtividade,
            $this->totalHhTendenciaProjeto,
            $this->percentualTendenciaPeriodo,
            $this->totalAtividadesPeriodo,
            $this->totalSemRestricao,
            $this->totalComRestricao,
            $this->percentualAvancoLiberadasPeriodo,
            $this->ppc,
            $this->arvoreAtividades,
            $this->programacaoDaSemana,
            $this->estaVisualizandoSemanaPassada,
            $this->semanaEstaCongelada,
            $this->semanaEstaFechada,
            $this->hhPrevistoSemanaPorAtividade,
            $this->totalHhProjeto,
            $this->linhaBaseSelecionada,
            $this->atividadeDetalhePlano,
            $this->percentualPrevistoPeriodoPopup,
            $this->documentosDisponiveisParaVincular,
            $this->coberturaMateriaisPopup,
            $this->pacotesDaAtividadePopup,
        );
    }

    /** Hooks do Livewire (updated{Propriedade}) — disparam a cada troca de filtro via wire:model.live. */
    public function updatedSearch(): void { $this->invalidarComputeds(); }
    public function updatedEtapaIdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedFrenteTrabalhoIdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedFaturamentoDiretoFiltro(): void { $this->invalidarComputeds(); }
    public function updatedEntregavelIdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedEquipeResponsavelIdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedPersonalizado1IdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedPersonalizado2IdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedPersonalizado3IdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedPersonalizado4IdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedPersonalizado5IdFiltro(): void { $this->invalidarComputeds(); }
    public function updatedOcultarConcluidas(): void { $this->invalidarComputeds(); }
    public function updatedFonteDatasPeriodo(): void { $this->invalidarComputeds(); }

    /**
     * Só o resultado exibido (arvoreAtividades) depende deste filtro —
     * mesmo tratamento de ocultarConcluidas: nunca invalida idsProntas/
     * PPC/HH/cards de período, que precisam continuar refletindo o
     * período INTEIRO independente do que está sendo exibido agora.
     */
    public function updatedLiberacaoFiltro(): void { unset($this->arvoreAtividades); }

    /** Some sozinho assim que o usuário mexe na seleção de novo, em vez de ficar preso na tela. */
    public function updatedSelecionadas(): void { $this->erroSelecao = null; }

    public function semanAnterior(): void
    {
        $this->semanaInicio = Carbon::parse($this->semanaInicio)->subWeek()->toDateString();
        $this->selecionadas = [];
        $this->erroSelecao = null;
        $this->invalidarComputeds();
    }

    public function semanaSeguinte(): void
    {
        $this->semanaInicio = Carbon::parse($this->semanaInicio)->addWeek()->toDateString();
        $this->selecionadas = [];
        $this->erroSelecao = null;
        $this->invalidarComputeds();
    }

    public function marcarConcluida(string $id): void
    {
        if ($this->semanaEstaCongelada && ! $this->semanaEstaFechada) {
            $this->dispatch('show-toast', message: 'Esta semana está congelada; conclua atividades pela semana atual.', type: 'error');
            return;
        }

        $atividade = Atividade::findOrFail($id);
        $this->authorize('update', $atividade);

        $this->transacaoSegura(fn () => $atividade->update(['status' => StatusAtividade::Concluido]));

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->dispatch('show-toast', message: 'Atividade concluída!');
        $this->invalidarComputeds();
    }

    public function abrirModalNaoConcluido(string $id): void
    {
        $this->naoConcluindoId = $id;
        $this->descricaoCausa  = '';
    }

    public function confirmarNaoConcluido(): void
    {
        if ($this->semanaEstaCongelada && ! $this->semanaEstaFechada) {
            $this->dispatch('show-toast', message: 'Esta semana está congelada; conclua atividades pela semana atual.', type: 'error');
            return;
        }

        $this->validate([
            'descricaoCausa' => 'required|string|min:5',
        ], [], ['descricaoCausa' => 'causa do não cumprimento']);

        $atividade = Atividade::findOrFail($this->naoConcluindoId);
        $this->authorize('update', $atividade);

        $this->transacaoSegura(fn () => (new MarcarNaoConcluido)->execute($atividade, $this->descricaoCausa));

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->naoConcluindoId = null;
        $this->descricaoCausa  = '';
        $this->dispatch('show-toast', message: 'Causa registrada.');
        $this->invalidarComputeds();
    }

    /** Marca/desmarca de uma vez todas as elegíveis (Planejado + liberada) no filtro atual. */
    public function toggleSelecionarTodas(): void
    {
        $elegiveis = $this->idsSelecionaveis;
        $todasSelecionadas = count($elegiveis) > 0 && count(array_intersect($elegiveis, $this->selecionadas)) === count($elegiveis);

        $this->selecionadas = $todasSelecionadas ? [] : $elegiveis;
    }

    /**
     * Reforça no servidor a mesma regra do Lookahead (App\Enums\Papel/
     * CLAUDE.md: só vai pro plano quem está pronta) — a UI já desabilita
     * o checkbox das bloqueadas/concluídas no cronograma, mas nunca
     * confia só nisso; filtra de novo contra idsSelecionaveis (Planejado
     * + liberada + não concluída no cronograma). Usada tanto pra abrir o
     * modal de confirmação quanto (de novo, defesa em profundidade) no
     * commit de verdade — nunca confia que "se o modal abriu, a seleção
     * ainda é válida agora".
     *
     * @return \Illuminate\Support\Collection|null null = inválida (erroSelecao já setado)
     */
    private function idsValidosSelecionados(): ?\Illuminate\Support\Collection
    {
        if (empty($this->selecionadas)) {
            $this->erroSelecao = 'Selecione pelo menos uma atividade para programar.';
            return null;
        }

        $idsValidos = collect($this->selecionadas)
            ->intersect($this->idsSelecionaveis)
            ->values();

        if ($idsValidos->isEmpty()) {
            $this->erroSelecao = 'Nenhuma atividade liberada selecionada — atividades bloqueadas ou já concluídas no cronograma não podem ser programadas.';
            return null;
        }

        $this->erroSelecao = null;

        return $idsValidos;
    }

    /**
     * Passo 1 do fluxo "Programar selecionadas": valida a seleção NO
     * SERVIDOR (round-trip completo, garante que `selecionadas` já
     * sincronizou de verdade antes de decidir se abre o modal) e só
     * então dispara o evento que abre o modal de confirmação via JS.
     * Corrige um bug real: abrir o modal direto no clique (Bootstrap
     * puro, sem passar pelo servidor) podia mostrar/usar uma contagem de
     * `selecionadas` desatualizada se o wire:model.live do checkbox
     * ainda não tivesse terminado de sincronizar.
     */
    public function abrirConfirmacaoProgramar(): void
    {
        if ($this->semanaEstaCongelada || $this->semanaEstaFechada) {
            $this->erroSelecao = 'Esta semana já está congelada; não é possível alterá-la.';
            return;
        }

        if ($this->idsValidosSelecionados() === null) {
            return;
        }

        $this->dispatch('abrir-modal-confirmar-programacao');
    }

    /**
     * "Programar selecionadas": comprometer as atividades selecionadas
     * manualmente. Chamado pelo botão "Confirmar" dentro do modal.
     *
     * IMPORTANTE — fechamento do modal NUNCA usa data-bs-dismiss no mesmo
     * botão que dispara wire:click: o layout base deste projeto já
     * documenta (ver resources/views/layouts/sections/scripts.blade.php,
     * comentário "Rede de segurança contra backdrop de modal Bootstrap
     * travado") um bug real de outros modais em que o dismiss do
     * Bootstrap corre em paralelo com o morph do Livewire e deixa um
     * .modal-backdrop órfão em <body>, cobrindo a tela inteira e
     * bloqueando QUALQUER clique — inclusive em elementos sem nenhuma
     * relação com o modal. Em vez disso, o fechamento é feito só depois
     * que o servidor confirma que a ação rodou (evento dedicado, tratado
     * no @script), nunca em paralelo com o próprio clique.
     */
    public function comprometerSelecionadas(): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.lookahead', 'editar'), 403);

        $this->dispatch('fechar-modal-confirmar-programacao');

        if ($this->semanaEstaCongelada || $this->semanaEstaFechada) {
            $this->dispatch('show-toast', message: 'Esta semana já está congelada; não é possível alterá-la.', type: 'error');
            return;
        }

        $idsValidos = $this->idsValidosSelecionados();
        if ($idsValidos === null) {
            return;
        }

        $this->transacaoSegura(function () use ($idsValidos) {
            $atividadesAlvo = Atividade::whereIn('id', $idsValidos)
                ->where('status', StatusAtividade::Planejado->value)
                ->get();

            Atividade::whereIn('id', $atividadesAlvo->pluck('id'))
                ->update(['status' => StatusAtividade::Comprometido->value]);

            (new RegistrarComprometimentoSemanal)->execute(
                $this->obra, $this->semanaInicio, $atividadesAlvo, OrigemProgramacaoSemanalItem::Manual
            );
        });

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->selecionadas = [];
        $this->invalidarComputeds();
        $this->dispatch('show-toast', message: "{$idsValidos->count()} atividade(s) inserida(s) na programação.");
    }

    /**
     * Botão "Gerar Programação" — fecha formalmente a programação vigente
     * desta semana. A partir daqui só resta marcar concluída/não
     * concluído (ver semanaEstaFechada); pra comprometer atividades
     * novas é preciso criar uma revisão (feito em Minhas Programações).
     */
    public function fecharProgramacao(): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.plano_semanal', 'editar'), 403);

        $programacao = $this->programacaoDaSemana;

        if (! $programacao) {
            $this->dispatch('show-toast', message: 'Não há nenhuma atividade comprometida nesta semana ainda.', type: 'error');
            return;
        }

        $this->transacaoSegura(fn () => (new FecharProgramacaoSemanal)->execute($programacao));

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->invalidarComputeds();
        $this->dispatch('show-toast', message: 'Programação da semana fechada.');
    }

    public function exportarPdf()
    {
        $pdf = Pdf::loadView('exports.plano-semanal-pdf', [
            'obra'         => $this->obra,
            'atividades'   => $this->atividades,
            'semanaInicio' => Carbon::parse($this->semanaInicio)->format('d/m/Y'),
            'semanaFim'    => Carbon::parse($this->semanaFim)->format('d/m/Y'),
        ]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            "plano-semanal-{$this->semanaInicio}.pdf"
        );
    }

    public function exportarExcel()
    {
        return Excel::download(
            new PlanoSemanalExport($this->atividades),
            "plano-semanal-{$this->semanaInicio}.xlsx"
        );
    }
};
?>

<div>
    {{-- Navegação de semana + Datas do Período + Exportação + PPC, tudo na mesma linha --}}
    <div class="d-flex align-items-center gap-3 flex-wrap mb-4">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm" wire:click="semanAnterior">
                <i class="bx bx-chevron-left"></i>
            </button>
            <div class="text-center px-3">
                <div class="fw-bold">
                    {{ \Carbon\Carbon::parse($semanaInicio)->format('d/m/Y') }}
                    até
                    {{ \Carbon\Carbon::parse($this->semanaFim)->format('d/m/Y') }}
                </div>
                <small class="text-muted">Semana {{ \Carbon\Carbon::parse($semanaInicio)->weekOfYear }}</small>
            </div>
            <button class="btn btn-outline-secondary btn-sm" wire:click="semanaSeguinte">
                <i class="bx bx-chevron-right"></i>
            </button>
        </div>

        @unless ($this->semanaEstaCongelada)
        <div class="d-flex align-items-center gap-2">
            <small class="text-muted">Datas do período:</small>
            <select class="form-select form-select-sm w-auto" wire:model.live="fonteDatasPeriodo"
                    title="Define quais atividades caem dentro da semana visualizada">
                <option value="tendencia">Tendência (cronograma atual)</option>
                <option value="baseline">Linha de Base</option>
            </select>
        </div>
        @else
        <small class="text-muted" title="Semana congelada: a lista vem do que foi comprometido, não das datas de tendência/baseline">
            <i class="bx bx-lock-alt me-1"></i>Datas do período: fixadas pela programação congelada
        </small>
        @endunless

        @if ($this->atividades->count() > 0)
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-danger btn-sm" wire:click="exportarPdf">
                <i class="bx bxs-file-pdf me-1"></i>PDF
            </button>
            <button class="btn btn-outline-success btn-sm" wire:click="exportarExcel">
                <i class="bx bxs-file-export me-1"></i>Excel
            </button>
        </div>
        @endif

        {{-- PPC --}}
        <div class="flex-grow-1" style="min-width: 260px;">
            @if ($this->ppc['total'] > 0)
                @php
                    $ppc = $this->ppc['percentual'];
                    $cor = $ppc >= 80 ? 'success' : ($ppc >= 60 ? 'warning' : 'danger');
                @endphp
                <div class="card border-{{ $cor }} mb-0">
                    <div class="card-body py-2 d-flex align-items-center gap-3">
                        <div>
                            <div class="text-muted small">PPC da semana</div>
                            <div class="display-6 fw-bold text-{{ $cor }}">{{ $ppc }}%</div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="progress" style="height: 12px;">
                                <div class="progress-bar bg-{{ $cor }}"
                                     style="width: {{ $ppc }}%"></div>
                            </div>
                            <small class="text-muted">{{ $this->ppc['concluidas'] }} de {{ $this->ppc['total'] }} atividades concluídas</small>
                        </div>
                    </div>
                </div>
            @else
                <div class="alert alert-secondary mb-0 py-2">
                    <i class="bx bx-calendar me-2"></i>Nenhuma atividade comprometida para esta semana.
                </div>
            @endif
        </div>
    </div>

    @if ($this->programacaoDaSemana)
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span class="badge bg-label-{{ $this->programacaoDaSemana->status->corBadge() }}">
            {{ $this->programacaoDaSemana->status->label() }}
        </span>
        @if ($this->programacaoDaSemana->versao > 1)
        <span class="badge bg-label-dark" title="Revisão de v{{ $this->programacaoDaSemana->versao - 1 }}">
            v{{ $this->programacaoDaSemana->versao }}
        </span>
        @endif
        @if (! $this->semanaEstaFechada)
        <button type="button" class="btn btn-outline-primary btn-sm"
                onclick="confirmarAcao(this, {
                    mensagem: 'Fechar a programação desta semana? Depois disso só será possível marcar concluída/não concluído — pra comprometer mais atividades será preciso criar uma revisão.',
                    metodo: 'fecharProgramacao',
                    corBotao: 'primary',
                    icone: 'bx-lock-alt',
                })">
            <i class="bx bx-lock-alt me-1"></i>Gerar Programação
        </button>
        @endif
    </div>
    @endif

    @if ($this->semanaEstaCongelada)
    <div class="alert alert-info py-2 mb-3 small">
        <i class="bx bx-lock-alt me-1"></i>
        Programação da semana {{ \Carbon\Carbon::parse($semanaInicio)->weekOfYear }}
        (congelada em {{ $this->programacaoDaSemana->congelada_em->format('d/m/Y H:i') }})
        — exibindo o conjunto comprometido naquela época; o cronograma atual pode já ter mudado.
    </div>
    @endif

    {{-- Filtros: ver canva lateral (.canva-filtros-plano) perto do fim do
         arquivo, antes do fechamento da div raiz. --}}

    {{-- Cards de totais — reativos ao filtro de semana --}}
    <div class="row g-3 mb-4 row-cols-2 row-cols-md-4">
        <div>
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    @if ($this->percentualAvancoPrevistoPeriodo !== null)
                        <div class="display-6 fw-bold">{{ number_format($this->percentualAvancoPrevistoPeriodo, 2, ',', '.') }}%</div>
                    @else
                        <div class="display-6 fw-bold text-muted">—</div>
                    @endif
                    <small class="text-muted" title="HH da Linha de Base (Previsto) das atividades do período ÷ HH total do projeto">% Previsto</small>
                </div>
            </div>
        </div>
        <div>
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    @if ($this->percentualTendenciaPeriodo !== null)
                        <div class="display-6 fw-bold">{{ number_format($this->percentualTendenciaPeriodo, 2, ',', '.') }}%</div>
                    @else
                        <div class="display-6 fw-bold text-muted" title="{{ $this->semanaEstaCongelada ? 'Semana congelada: tendência não é reavaliada' : 'Sem HH de tendência cadastrado' }}">—</div>
                    @endif
                    <small class="text-muted" title="HH da Tendência (Work atual) das atividades do período ÷ HH total do projeto">% da Tendência</small>
                </div>
            </div>
        </div>
        <div>
            <div class="card h-100 {{ ! $this->semanaEstaCongelada && $this->totalComRestricao > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    @if ($this->semanaEstaCongelada)
                        <div class="display-6 fw-bold text-muted" title="Semana congelada: liberação não é reavaliada">{{ $this->totalAtividadesPeriodo }}/—</div>
                    @else
                        <div class="display-6 fw-bold {{ $this->totalComRestricao > 0 ? 'text-danger' : '' }}">
                            {{ $this->totalAtividadesPeriodo }}/{{ $this->totalComRestricao }}
                        </div>
                    @endif
                    <small class="text-muted">Atividades do Período / Bloqueadas</small>
                </div>
            </div>
        </div>
        <div>
            <div class="card h-100 border-success">
                <div class="card-body text-center py-3">
                    @if ($this->semanaEstaCongelada)
                        <div class="display-6 fw-bold text-muted" title="Semana congelada: liberação não é reavaliada">—</div>
                    @else
                        <div class="display-6 fw-bold text-success">
                            {{ $this->totalSemRestricao }}
                            <span class="fs-6">({{ number_format($this->percentualAvancoLiberadasPeriodo ?? 0, 2, ',', '.') }}%)</span>
                        </div>
                    @endif
                    <small class="text-muted" title="Quantidade de atividades liberadas do período; entre parênteses, o HH previsto dessas atividades ÷ HH total do projeto">Atividades Liberadas (% Avanço)</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabela de atividades (hierarquia EAP — mesma estrutura do cronograma) --}}
    @if ($this->atividades->count() > 0)
        @unless ($this->semanaEstaCongelada || $this->semanaEstaFechada)
        @if (count($this->idsSelecionaveis) > 0)
        @if ($erroSelecao)
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-2">
            <i class="bx bx-error-circle fs-5"></i>
            <strong>{{ $erroSelecao }}</strong>
        </div>
        @endif
        <div class="d-flex align-items-center gap-2 mb-2">
            <button type="button" class="btn btn-success btn-sm"
                    wire:click="abrirConfirmacaoProgramar" wire:loading.attr="disabled">
                <i class="bx bx-list-check me-1"></i>
                Programar selecionadas ({{ count($selecionadas) }})
            </button>
            <small class="text-muted">Só atividades liberadas, ainda Planejadas e não concluídas no cronograma podem ser selecionadas.</small>
        </div>
        @endif
        @endunless
        @php
            $niveisExistentes = collect($this->arvoreAtividades)
                ->where('tipo', 'pacote')
                ->pluck('nivel')
                ->unique()
                ->sort()
                ->values();
        @endphp
        <div class="card table-responsive"
             wire:key="arvore-{{ md5(collect($this->arvoreAtividades)->pluck('id')->implode(',')) }}"
             x-data="{
                recolhidos: [],
                niveisIds: {{ json_encode(
                    collect($this->arvoreAtividades)
                        ->where('tipo', 'pacote')
                        ->groupBy('nivel')
                        ->map(fn ($g) => $g->pluck('id')->values())
                ) }},
                colapsarAteNivel(nivel) {
                    this.recolhidos = [];
                    Object.entries(this.niveisIds).forEach(([n, ids]) => {
                        if (parseInt(n) >= nivel) {
                            ids.forEach(id => {
                                if (!this.recolhidos.includes(id)) this.recolhidos.push(id);
                            });
                        }
                    });
                },
                expandirTudo() { this.recolhidos = []; }
             }">
            @if ($niveisExistentes->isNotEmpty())
            <div class="card-header py-2 d-flex align-items-center gap-2 flex-wrap border-bottom">
                <small class="text-muted me-1">Colapsar por nível:</small>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" @click="expandirTudo()">
                    <i class="bx bx-expand-alt me-1"></i>Expandir tudo
                </button>
                @foreach ($niveisExistentes as $nv)
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" @click="colapsarAteNivel({{ $nv }})">
                    Nível {{ $nv + 1 }}+
                </button>
                @endforeach
            </div>
            @endif
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Atividade</th>
                        <th>Disciplina</th>
                        <th class="text-center">Início da Linha de Base</th>
                        <th class="text-center">Término da Linha de Base</th>
                        <th class="text-center">Início Tendência</th>
                        <th class="text-center">Término Tendência</th>
                        <th class="text-center">% de Avanço</th>
                        <th class="text-center">Liberação</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Histórico</th>
                        <th class="text-center" style="width: 90px">
                            Programar
                            @unless ($this->semanaEstaCongelada || $this->semanaEstaFechada)
                            @if (count($this->idsSelecionaveis) > 0)
                            <br>
                            <input type="checkbox" class="form-check-input" wire:click="toggleSelecionarTodas"
                                   @checked(count($selecionadas) > 0 && count(array_intersect($this->idsSelecionaveis, $selecionadas)) === count($this->idsSelecionaveis))
                                   title="Selecionar todas as liberadas">
                            @endif
                            @endunless
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @php $idsProntasArray = $this->idsProntas->all(); @endphp
                    @foreach ($this->arvoreAtividades as $linha)
                        @php $ancestraisJson = json_encode($linha['ancestrais']); @endphp

                        @if ($linha['tipo'] === 'pacote')
                        <tr wire:key="pacote-{{ $linha['id'] }}"
                            x-show="!({{ $ancestraisJson }}).some(id => recolhidos.includes(id))"
                            class="table-light">
                            <td colspan="11" style="padding-left: {{ $linha['nivel'] * 24 }}px">
                                <button type="button" class="btn btn-sm btn-link p-0 me-1 text-dark"
                                        @click="recolhidos.includes('{{ $linha['id'] }}') ? recolhidos.splice(recolhidos.indexOf('{{ $linha['id'] }}'), 1) : recolhidos.push('{{ $linha['id'] }}')">
                                    <i class="bx" :class="recolhidos.includes('{{ $linha['id'] }}') ? 'bx-chevron-right' : 'bx-chevron-down'"></i>
                                </button>
                                <span class="fw-semibold">{{ $linha['pacote']->codigo }} — {{ $linha['pacote']->nome }}</span>
                            </td>
                        </tr>
                        @continue
                        @endif

                        @php
                            $at = $linha['atividade'];
                            $statusVal = $at->status instanceof \App\Enums\StatusAtividade
                                ? $at->status->value
                                : $at->status;
                            $badgeClass = match($statusVal) {
                                'planejado'     => 'bg-secondary',
                                'comprometido'  => 'bg-primary',
                                'em_execucao'   => 'bg-info',
                                'concluido'     => 'bg-success',
                                'nao_concluido' => 'bg-danger',
                                default         => 'bg-secondary',
                            };
                            $statusLabel = match($statusVal) {
                                'planejado'     => 'Planejado',
                                'comprometido'  => 'Comprometido',
                                'em_execucao'   => 'Em Execução',
                                'concluido'     => 'Concluído',
                                'nao_concluido' => 'Não Concluído',
                                default         => $statusVal,
                            };
                            $pronta = in_array($at->id, $idsProntasArray, true);
                            $concluidaNoCronograma = (float) ($at->percentual_concluido ?? 0) >= 100;
                            $selecionavel = $statusVal === 'planejado' && $pronta && ! $concluidaNoCronograma;
                        @endphp
                        <tr wire:key="atividade-{{ $at->id }}"
                            x-show="!({{ $ancestraisJson }}).some(id => recolhidos.includes(id))">
                            <td style="padding-left: 24px">
                                <span class="fw-semibold" style="cursor:pointer" title="Ver por que posso ou não posso programar esta atividade"
                                      wire:click="verAtividadeDetalhe('{{ $at->id }}')">{{ $at->nome }}</span>
                                @if ($at->caminho_critico)
                                    <span class="badge bg-label-danger ms-1 align-middle" title="Caminho crítico">CC</span>
                                @endif
                            </td>
                            <td>{{ $at->disciplina?->nome ?? '—' }}</td>
                            <td class="text-center">
                                <small>{{ $at->baseline_inicio?->format('d/m') ?? '—' }}</small>
                            </td>
                            <td class="text-center">
                                <small>{{ $at->baseline_termino?->format('d/m') ?? '—' }}</small>
                            </td>
                            <td class="text-center">
                                <small>{{ $at->inicio_planejado?->format('d/m') ?? '—' }}</small>
                            </td>
                            <td class="text-center">
                                @php $atrasada = $at->data_termino && $at->data_termino->isPast() && $statusVal !== 'concluido'; @endphp
                                <small class="{{ $atrasada ? 'text-danger fw-bold' : '' }}">
                                    {{ $at->data_termino?->format('d/m') ?? '—' }}
                                </small>
                            </td>
                            <td class="text-center">
                                @php
                                    $hhSemana = $this->hhPrevistoSemanaPorAtividade->get($at->id);
                                    $percentualAvancoLinha = ($hhSemana !== null && $this->totalHhProjeto > 0)
                                        ? round(((float) $hhSemana / $this->totalHhProjeto) * 100, 2)
                                        : null;
                                @endphp
                                @if ($percentualAvancoLinha !== null)
                                    <small>{{ number_format($percentualAvancoLinha, 2, ',', '.') }}%</small>
                                @else
                                    <small class="text-muted" title="Sem HH previsto cadastrado para esta atividade nesta semana">—</small>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($this->semanaEstaCongelada)
                                    <span class="badge bg-label-secondary" title="Semana congelada: liberação não é reavaliada">—</span>
                                @elseif ($pronta)
                                    <span class="badge bg-label-success"><i class="bx bx-check-circle me-1"></i>Liberada</span>
                                @else
                                    <span class="badge bg-label-danger" title="Possui restrições bloqueantes"><i class="bx bx-lock-alt me-1"></i>Bloqueada</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                            </td>
                            <td class="text-center">
                                @if ($this->idsJaProgramadas->contains($at->id))
                                    <span class="badge bg-label-info" title="Já esteve em pelo menos uma programação semanal salva">JÁ PROGRAMADA</span>
                                @else
                                    <span class="badge bg-label-secondary" title="Nunca esteve em nenhuma programação semanal salva">NUNCA PROGRAMADA</span>
                                @endif
                            </td>
                            <td class="text-center">
                                {{-- "Programar" (ex-Ações): checkbox de seleção pra nova
                                     programação e os botões de marcar concluída/não
                                     concluído nunca aparecem juntos na mesma linha — o
                                     status Planejado (selecionável) e Comprometido/Em
                                     Execução (com botões) são mutuamente exclusivos —
                                     por isso convivem na mesma coluna sem se atrapalhar. --}}
                                @unless ($this->semanaEstaCongelada || $this->semanaEstaFechada)
                                @if ($selecionavel)
                                    <input type="checkbox" class="form-check-input" wire:model.live="selecionadas" value="{{ $at->id }}" title="Selecionar para programar">
                                @elseif ($statusVal === 'planejado')
                                    {{-- Linha "Planejada" mas não selecionável — mostra o motivo em
                                         vez de deixar a célula vazia (achado de UX: usuário achava
                                         que o botão "Programar selecionadas" não fazia nada, quando
                                         na real não havia checkbox nenhum pra marcar nessa linha). --}}
                                    <i class="bx bx-lock-alt text-danger"
                                       title="{{ $concluidaNoCronograma ? 'Concluída no cronograma importado — não pode ser reprogramada' : 'Bloqueada: restrição aberta ou checklist de prontidão pendente' }}"></i>
                                @endif
                                @endunless
                                @unless ($this->semanaEstaCongelada && ! $this->semanaEstaFechada)
                                @if (in_array($statusVal, ['comprometido', 'em_execucao']))
                                    <div class="d-flex gap-1 justify-content-center">
                                        <button class="btn btn-sm btn-outline-success py-0 px-1"
                                                wire:click="marcarConcluida('{{ $at->id }}')"
                                                title="Marcar como concluída"
                                                wire:loading.attr="disabled">
                                            <i class="bx bx-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger py-0 px-1"
                                                wire:click="abrirModalNaoConcluido('{{ $at->id }}')"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalNaoConcluido"
                                                title="Registrar não cumprimento">
                                            <i class="bx bx-x"></i>
                                        </button>
                                    </div>
                                @endif
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center py-5">
            <i class="bx bx-calendar-check display-3 text-muted"></i>
            <h5 class="fw-bold mt-3">Nenhuma atividade para esta semana</h5>
            <p class="text-muted">Atividades que iniciam, terminam ou estão em execução nesta semana aparecerão aqui.</p>
        </div>
    @endif

    {{-- Modal Confirmar Programação — visual próprio do sistema em vez do
         confirm() nativo do navegador (wire:confirm), mesma estrutura do
         Modal Não Concluído logo abaixo. --}}
    <div class="modal fade" id="modalConfirmarProgramacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-bottom border-success">
                    <h5 class="modal-title text-success">
                        <i class="bx bx-list-check me-2"></i>Confirmar Programação
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">
                        Programar <strong>{{ count($selecionadas) }}</strong> atividade(s) selecionada(s)?
                    </p>
                    <p class="text-muted small mb-0 mt-2">
                        Isso cria (ou atualiza) a Programação Semanal desta semana com status
                        <span class="badge bg-label-primary">Aberta</span> — ela passa a aparecer em
                        <strong>Minhas Programações</strong>.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success"
                            wire:click="comprometerSelecionadas"
                            wire:loading.attr="disabled">
                        <i class="bx bx-check me-1"></i>Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal Não Concluído --}}
    <div class="modal fade" id="modalNaoConcluido" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-bottom border-danger">
                    <h5 class="modal-title text-danger">
                        <i class="bx bx-x-circle me-2"></i>Registrar Não Cumprimento
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Informe a causa do não cumprimento. Esta informação alimenta o Dashboard de Causas (Pareto).
                    </p>
                    <label class="form-label">Causa do não cumprimento <span class="text-danger">*</span></label>
                    <textarea class="form-control" rows="4" wire:model="descricaoCausa"
                              placeholder="Ex: Falta de material, chuva, aguardando aprovação..."></textarea>
                    <x-input-error for="descricaoCausa" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger"
                            wire:click="confirmarNaoConcluido"
                            data-bs-dismiss="modal"
                            wire:loading.attr="disabled">
                        <i class="bx bx-save me-1"></i>Registrar Causa
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         MODAL: "LIBERAÇÃO PARA PROGRAMAÇÃO" — diagnóstico da atividade,
         aberto ao clicar no nome na tabela. Reaproveita 100% a mesma regra
         autoritativa de liberado/bloqueado (Atividade::scopeProntas()/
         estaPronta()) e as mesmas 3 dimensões que ela avalia (restrições
         bloqueantes, checklist de prontidão, Documentos de Engenharia não
         liberados) — nunca uma segunda definição. Popup deliberadamente
         mais enxuto que o do Lookahead (sem Curva S/lições/comentários/
         anexos): o objetivo aqui é só responder "por que posso ou não
         posso programar esta atividade?", pra uso na reunião semanal de
         produção.
         ========================================================================= --}}
    @if ($atividadeDetalheId)
    @php $detalhePlano = $this->atividadeDetalhePlano; @endphp
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                @if (! $detalhePlano)
                <div class="modal-body text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
                @else
                @php
                    $atPlano = $detalhePlano['atividade'];
                    $checklistPlano = $detalhePlano['checklist'];
                    $okChkPlano = collect($checklistPlano)->where('concluido', true)->count();
                    $totalChkPlano = collect($checklistPlano)->count();
                    $documentosBloqueantesPlano = $detalhePlano['documentosBloqueantes'];
                    $statusValPlano = $atPlano->status instanceof \App\Enums\StatusAtividade
                        ? $atPlano->status->value
                        : $atPlano->status;
                    $statusLabelPlano = match ($statusValPlano) {
                        'planejado' => 'Planejado', 'comprometido' => 'Comprometido',
                        'em_execucao' => 'Em Execução', 'concluido' => 'Concluído',
                        'nao_concluido' => 'Não Concluído', default => $statusValPlano,
                    };
                    // Mesma fonte canônica única de liberado/bloqueado do
                    // projeto inteiro — 1 query extra aqui é aceitável (o
                    // popup nunca renderiza em loop, ver docblock de
                    // Atividade::estaPronta()).
                    $atividadePlanoPronta = $atPlano->estaPronta();
                    $restricoesBloqueantesPlano = $atPlano->restricoes->filter(fn ($r) =>
                        $r->bloqueante && in_array($r->status->value ?? $r->status, ['aberta', 'em_tratamento', 'aguardando_terceiros'])
                    );
                    $restricoesNaoBloqueantesPlano = $atPlano->restricoes->filter(fn ($r) =>
                        ! $r->bloqueante && in_array($r->status->value ?? $r->status, ['aberta', 'em_tratamento', 'aguardando_terceiros'])
                    );
                @endphp
                <div class="modal-header bg-dark text-white">
                    <div class="flex-grow-1 mb-2">
                        <h5 class="modal-title mb-2 text-white">
                            {{ $atPlano->nome }}
                            @if ($atPlano->codigo_cronograma)
                            <span class="opacity-75">({{ $atPlano->codigo_cronograma }})</span>
                            @endif
                        </h5>
                        <small class="opacity-75">
                            {{ $atPlano->disciplina?->nome ?? 'Sem disciplina' }}
                            @if ($atPlano->frenteTrabalho)
                            · <i class="bx bx-folder me-1"></i>{{ $atPlano->frenteTrabalho->nome }}
                            @endif
                            @if ($atPlano->caminho_critico)
                            <span class="badge bg-danger ms-2">Caminho Crítico</span>
                            @endif
                            @if ($statusValPlano === 'concluido')
                            <span class="badge bg-primary ms-2">Concluída</span>
                            @elseif ($atividadePlanoPronta)
                            <span class="badge bg-success ms-2"><i class="bx bx-check-circle me-1"></i>Liberada</span>
                            @else
                            <span class="badge bg-danger ms-2"><i class="bx bx-lock-alt me-1"></i>Bloqueada</span>
                            @endif
                        </small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" wire:click="fecharAtividadeDetalhe"></button>
                </div>

                <div class="px-4 py-3 bg-light border-bottom">
                    <div class="row g-3 text-center">
                        <div class="col-6 col-md-3 col-lg">
                            <div class="small text-muted">Início (Linha de Base)</div>
                            <h6 class="fw-semibold mb-0">{{ $atPlano->baseline_inicio?->format('d/m/Y') ?? '—' }}</h6>
                        </div>
                        <div class="col-6 col-md-3 col-lg">
                            <div class="small text-muted">Término (Linha de Base)</div>
                            <h6 class="fw-semibold mb-0">{{ $atPlano->baseline_termino?->format('d/m/Y') ?? '—' }}</h6>
                        </div>
                        <div class="col-6 col-md-3 col-lg">
                            <div class="small text-muted">Início (Tendência)</div>
                            <h6 class="fw-semibold mb-0">{{ $atPlano->inicio_planejado?->format('d/m/Y') ?? '—' }}</h6>
                        </div>
                        <div class="col-6 col-md-3 col-lg">
                            <div class="small text-muted">Término (Tendência)</div>
                            <h6 class="fw-semibold mb-0">{{ $atPlano->data_termino?->format('d/m/Y') ?? '—' }}</h6>
                        </div>
                        <div class="col-6 col-md-3 col-lg">
                            <div class="small text-muted">% Avanço</div>
                            <h6 class="fw-semibold mb-0">{{ number_format((float) ($atPlano->percentual_concluido ?? 0), 0) }}%</h6>
                        </div>
                        <div class="col-6 col-md-3 col-lg">
                            <div class="small text-muted">Status</div>
                            <h6 class="fw-semibold mb-0">{{ $statusLabelPlano }}</h6>
                        </div>
                    </div>
                </div>

                {{-- Melhoria "Posto Operacional" (Seção 11) — % previsto
                     INCREMENTAL da semana selecionada, nunca o acumulado até
                     o fim dela (fórmula já autoritativa de CurvaAvanco::
                     calcular(), ver percentualPrevistoPeriodoPopup()). Mostra
                     também acumulado (previsto/realizado) quando existir
                     dado, sem inventar nada quando não existir. --}}
                @php $previstoPeriodo = $this->percentualPrevistoPeriodoPopup; @endphp
                @if ($previstoPeriodo)
                <div class="px-4 py-2 bg-white border-bottom">
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="small text-muted" title="HH previsto desta semana ÷ HH previsto total da atividade">% Previsto no Período</div>
                            <h6 class="fw-semibold mb-0">
                                {{ $previstoPeriodo['percentual_previsto_periodo'] !== null ? number_format($previstoPeriodo['percentual_previsto_periodo'], 1, ',', '.') . '%' : '—' }}
                            </h6>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted" title="Previsto acumulado até o fim desta semana">% Previsto Acumulado</div>
                            <h6 class="fw-semibold mb-0">
                                {{ $previstoPeriodo['percentual_previsto_acumulado'] !== null ? number_format($previstoPeriodo['percentual_previsto_acumulado'], 1, ',', '.') . '%' : '—' }}
                            </h6>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted" title="Realizado acumulado na última importação de avanço">% Realizado Acumulado</div>
                            <h6 class="fw-semibold mb-0">
                                {{ $previstoPeriodo['percentual_realizado_acumulado'] !== null ? number_format($previstoPeriodo['percentual_realizado_acumulado'], 1, ',', '.') . '%' : '—' }}
                            </h6>
                        </div>
                    </div>
                    @unless ($previstoPeriodo['tem_previsto'])
                    <p class="small text-muted mb-0 mt-1"><i class="bx bx-info-circle me-1"></i>Sem dados de HH previsto (phased) para esta atividade.</p>
                    @endunless
                </div>
                @endif

                {{-- Seção "Liberação para Programação" — resumo objetivo antes
                     do detalhe, pra responder de cara "posso ou não posso
                     programar", sem precisar ler as 3 seções abaixo. --}}
                <div class="px-4 py-3 border-bottom">
                    <h6 class="fw-bold mb-2"><i class="bx bx-key me-2"></i>Liberação para Programação</h6>
                    @if ($statusValPlano === 'concluido')
                    <div class="alert alert-primary py-2 mb-0">
                        <i class="bx bx-info-circle me-1"></i>Atividade já concluída no cronograma importado — liberação não se aplica.
                    </div>
                    @elseif ($atividadePlanoPronta)
                    <div class="alert alert-success py-2 mb-0">
                        <i class="bx bx-check-circle me-1"></i>Liberada — sem restrição bloqueante em aberto, checklist de prontidão completo e nenhum Documento de Engenharia pendente vinculado.
                    </div>
                    @else
                    <div class="alert alert-danger py-2 mb-2">
                        <i class="bx bx-lock-alt me-1"></i>Bloqueada — veja abaixo exatamente o(s) motivo(s).
                    </div>
                    <ul class="mb-0 small">
                        <li>
                            @if ($restricoesBloqueantesPlano->isNotEmpty())
                            <i class="bx bx-x-circle text-danger me-1"></i>{{ $restricoesBloqueantesPlano->count() }} restrição(ões) bloqueante(s) em aberto
                            @else
                            <i class="bx bx-check text-success me-1"></i>Sem restrição bloqueante em aberto
                            @endif
                        </li>
                        <li>
                            @if ($totalChkPlano > 0 && $okChkPlano < $totalChkPlano)
                            <i class="bx bx-x-circle text-danger me-1"></i>Checklist de prontidão incompleto ({{ $okChkPlano }}/{{ $totalChkPlano }})
                            @else
                            <i class="bx bx-check text-success me-1"></i>Checklist de prontidão completo
                            @endif
                        </li>
                        <li>
                            @if ($documentosBloqueantesPlano->isNotEmpty())
                            <i class="bx bx-x-circle text-danger me-1"></i>{{ $documentosBloqueantesPlano->count() }} Documento(s) de Engenharia pendente(s)
                            @else
                            <i class="bx bx-check text-success me-1"></i>Sem Documento de Engenharia pendente vinculado
                            @endif
                        </li>
                    </ul>
                    @endif
                </div>

                <div class="modal-body p-0">
                    <div class="row g-0">
                        <div class="{{ $checklistPlano->isNotEmpty() ? 'col-md-8 border-end' : 'col-12' }}">
                            <div class="p-4">
                                <h6 class="fw-bold mb-3 d-flex align-items-center justify-content-between">
                                    <span><i class="bx bx-block me-2 text-danger"></i>Restrições</span>
                                    <div class="d-flex gap-2">
                                        <span class="badge bg-secondary">{{ $atPlano->restricoes->count() }}</span>
                                        @can('create', [Restricao::class, $obra->id])
                                        <button class="btn btn-xs btn-outline-warning py-0 px-2" wire:click="abrirModalRestricao('{{ $atPlano->id }}')">
                                            <i class="bx bx-plus me-1"></i>Nova Restrição
                                        </button>
                                        @endcan
                                    </div>
                                </h6>

                                @if ($atPlano->restricoes->isEmpty())
                                <div class="text-center text-muted py-4">
                                    <i class="bx bx-check-circle fs-2 text-success d-block mb-2"></i>
                                    Nenhuma restrição nesta atividade.
                                </div>
                                @else
                                @foreach ($atPlano->restricoes as $rPlano)
                                @php
                                    $rsvPlano = $rPlano->status instanceof \App\Enums\StatusRestricao ? $rPlano->status->value : $rPlano->status;
                                    $rabertaPlano = in_array($rsvPlano, ['aberta', 'em_tratamento', 'aguardando_terceiros']);
                                    $rlabelPlano = match ($rsvPlano) {
                                        'aberta' => 'Aberta', 'em_tratamento' => 'Em Tratamento',
                                        'aguardando_terceiros' => 'Ag. Terceiros', 'resolvida' => 'Resolvida',
                                        default => $rsvPlano,
                                    };
                                    $rcorPlano = match ($rsvPlano) {
                                        'aberta' => 'danger', 'em_tratamento' => 'warning',
                                        'aguardando_terceiros' => 'info', 'resolvida' => 'success',
                                        default => 'secondary',
                                    };
                                    $rvencidaPlano = $rPlano->prazo_limite && $rabertaPlano && $rPlano->prazo_limite->isPast();
                                @endphp
                                <div class="card {{ $rPlano->bloqueante && $rabertaPlano ? 'border-danger' : 'border-light' }} mb-3 shadow-none" wire:key="restricao-plano-{{ $rPlano->id }}">
                                    <div class="card-body py-2 px-3">
                                        <div class="d-flex align-items-start gap-2 mb-1">
                                            <div class="flex-grow-1 me-2">
                                                <span style="font-size:.875rem">{{ $rPlano->descricao }}</span>
                                                @if ($rPlano->categoria)
                                                <span class="badge bg-label-secondary ms-1" style="font-size:.7rem">{{ $rPlano->categoria->nome }}</span>
                                                @endif
                                                @if ($rPlano->bloqueante)
                                                <span class="badge bg-label-danger ms-1" style="font-size:.7rem">Bloqueante</span>
                                                @endif
                                            </div>
                                            <span class="badge bg-{{ $rcorPlano }} flex-shrink-0">{{ $rlabelPlano }}</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <div>
                                                @if ($rPlano->responsavel)
                                                <small class="text-muted d-block"><i class="bx bx-user me-1"></i>{{ $rPlano->responsavel->first_name }} {{ $rPlano->responsavel->last_name }}</small>
                                                @elseif ($rPlano->responsavel_externo)
                                                <small class="text-muted d-block"><i class="bx bx-user me-1"></i>{{ $rPlano->responsavel_externo }}</small>
                                                @endif
                                                @if ($rPlano->prazo_limite)
                                                <small class="{{ $rvencidaPlano ? 'text-danger fw-semibold' : 'text-muted' }}">
                                                    <i class="bx bx-calendar-exclamation me-1"></i>Prazo: {{ $rPlano->prazo_limite->format('d/m/Y') }}
                                                    @if ($rvencidaPlano) (vencido) @endif
                                                </small>
                                                @endif
                                            </div>
                                            @if ($rabertaPlano)
                                            @can('resolver', $rPlano)
                                            <button class="btn btn-xs btn-outline-success py-0 px-2" wire:click="abrirModalBaixa('{{ $rPlano->id }}')">
                                                <i class="bx bx-check me-1"></i>Dar baixa
                                            </button>
                                            @endcan
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                                @endif
                            </div>
                        </div>

                        @if ($checklistPlano->isNotEmpty())
                        <div class="col-md-4">
                            <div class="p-4">
                                <h6 class="fw-bold mb-3">
                                    <i class="bx bx-check-square me-2 text-success"></i>Prontidão
                                    <span class="badge {{ $okChkPlano === $totalChkPlano ? 'bg-success' : 'bg-warning text-dark' }} ms-1">{{ $okChkPlano }}/{{ $totalChkPlano }}</span>
                                </h6>
                                <div class="progress mb-3" style="height:6px">
                                    <div class="progress-bar {{ $okChkPlano === $totalChkPlano ? 'bg-success' : 'bg-warning' }}" style="width:{{ $totalChkPlano > 0 ? round($okChkPlano / $totalChkPlano * 100) : 0 }}%"></div>
                                </div>
                                @foreach ($checklistPlano as $itemChkPlano)
                                <div class="form-check mb-3" wire:key="chk-plano-{{ $atPlano->id }}-{{ $itemChkPlano['id'] }}">
                                    <input class="form-check-input" type="checkbox"
                                           id="planochk_{{ $atPlano->id }}_{{ $itemChkPlano['id'] }}"
                                           @checked($itemChkPlano['concluido'])
                                           wire:click="marcarItemNaDetalhe('{{ $atPlano->id }}','{{ $itemChkPlano['id'] }}',{{ $itemChkPlano['concluido'] ? 'false' : 'true' }})">
                                    <label class="form-check-label {{ $itemChkPlano['concluido'] ? 'text-decoration-line-through text-muted' : '' }}"
                                           for="planochk_{{ $atPlano->id }}_{{ $itemChkPlano['id'] }}">
                                        {{ $itemChkPlano['nome'] }}
                                    </label>
                                    @if ($itemChkPlano['concluido'] && $itemChkPlano['concluido_por'])
                                    <small class="text-muted d-block">
                                        {{ $itemChkPlano['concluido_por']->first_name }} — {{ $itemChkPlano['concluido_em']?->format('d/m/Y H:i') }}
                                    </small>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </div>

                    {{-- Informativo, nunca contado como bloqueio (item 3.D do
                         pedido) — restrição não bloqueante aberta não impede
                         Programar por si só, mas continua visível/tratável. --}}
                    @if ($restricoesNaoBloqueantesPlano->isNotEmpty())
                    <div class="border-top p-4">
                        <h6 class="fw-bold mb-2 text-muted">
                            <i class="bx bx-info-circle me-2"></i>Outras restrições em aberto (não bloqueantes)
                            <span class="badge bg-label-secondary ms-1">{{ $restricoesNaoBloqueantesPlano->count() }}</span>
                        </h6>
                        <p class="small text-muted mb-0">Não impedem Programar, mas seguem em aberto — já listadas na seção Restrições acima.</p>
                    </div>
                    @endif

                    {{-- =====================================================
                         MELHORIA "POSTO OPERACIONAL" — ENGENHARIA (Seção 12).
                         Vincular/desvincular reaproveita a MESMA relação N:N
                         e a MESMA Policy (engenharia.pacotes) já usadas em
                         ⚡documentos-engenharia.blade.php — liberar/emitir
                         revisão continua exclusivamente naquela tela.
                         ===================================================== --}}
                    <div class="border-top p-4">
                        <h6 class="fw-bold mb-3 d-flex align-items-center justify-content-between">
                            <span><i class="bx bx-file-blank me-2 text-primary"></i>Documentos de Engenharia</span>
                            <span class="badge bg-secondary">{{ $detalhePlano['documentos']->count() }}</span>
                        </h6>

                        @if ($detalhePlano['documentos']->isEmpty())
                        <p class="text-muted small mb-3">Nenhum documento vinculado a esta atividade.</p>
                        @else
                        <ul class="list-group list-group-flush mb-3">
                            @foreach ($detalhePlano['documentos'] as $docPlano)
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2" wire:key="doc-plano-{{ $docPlano['id'] }}">
                                <div>
                                    <strong>{{ $docPlano['codigo'] }}</strong> — {{ $docPlano['descricao'] }}
                                    @if ($docPlano['revisaoVigente'])
                                    <span class="text-muted">(Rev. {{ $docPlano['revisaoVigente'] }})</span>
                                    @endif
                                    <br>
                                    @if ($docPlano['liberado'])
                                    <span class="badge bg-label-success"><i class="bx bx-check-circle me-1"></i>Liberado para construção</span>
                                    @else
                                    <span class="badge bg-label-danger">
                                        <i class="bx bx-lock-alt me-1"></i>
                                        {{ $docPlano['motivo'] === 'sem_revisao' ? 'Ainda não emitido' : 'Revisão vigente não liberada' }}
                                    </span>
                                    @endif
                                </div>
                                @if (Auth::user()?->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'editar'))
                                <button type="button" class="btn btn-xs btn-outline-danger py-0 px-2 flex-shrink-0"
                                        onclick="confirmarAcao(this, {
                                            mensagem: 'Desvincular este documento da atividade?',
                                            metodo: 'desvincularDocumento',
                                            args: ['{{ $docPlano['id'] }}'],
                                            corBotao: 'danger',
                                            icone: 'bx-unlink',
                                        })">
                                    <i class="bx bx-unlink"></i>
                                </button>
                                @endif
                            </li>
                            @endforeach
                        </ul>
                        @endif

                        @if (Auth::user()?->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'editar'))
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" placeholder="Buscar documento por código ou descrição..."
                                   wire:model.live.debounce.300ms="buscaDocumentoVincular">
                        </div>
                        @if ($buscaDocumentoVincular !== '')
                        <div class="list-group list-group-flush mt-2" style="max-height: 180px; overflow-y: auto">
                            @forelse ($this->documentosDisponiveisParaVincular as $docDisp)
                            <button type="button" class="list-group-item list-group-item-action py-2" wire:key="doc-disp-{{ $docDisp->id }}"
                                    wire:click="vincularDocumento('{{ $docDisp->id }}')">
                                <strong>{{ $docDisp->codigo }}</strong> — {{ $docDisp->descricao }}
                            </button>
                            @empty
                            <p class="text-muted small mb-0 py-2">Nenhum documento encontrado.</p>
                            @endforelse
                        </div>
                        @endif
                        @endif
                    </div>

                    {{-- =====================================================
                         MELHORIA "POSTO OPERACIONAL" — MATERIAIS (Seções 6/7/9/13).
                         Fonte autoritativa: AtividadeNecessidadeMaterial —
                         nunca a demanda agregada do(s) Pacote(s), nunca
                         participa da cadeia TakeOff→RP→Pacote→RC/Pedido.
                         ===================================================== --}}
                    <div class="border-top p-4">
                        <h6 class="fw-bold mb-3 d-flex align-items-center justify-content-between">
                            <span><i class="bx bx-cube me-2 text-warning"></i>Materiais para Execução</span>
                            @if (Auth::user()?->temPermissaoNaObra($obra->id, 'restricoes.plano_semanal', 'editar'))
                            <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" wire:click="abrirModalNecessidade">
                                <i class="bx bx-plus me-1"></i>Adicionar Material
                            </button>
                            @endif
                        </h6>

                        @if ($this->coberturaMateriaisPopup->isEmpty())
                        <p class="text-muted small mb-0">Nenhuma necessidade de material cadastrada para esta atividade.</p>
                        @else
                        @foreach ($this->coberturaMateriaisPopup as $linhaMat)
                        @php
                            $necMat = $linhaMat['necessidade'];
                            $matEfetivo = $linhaMat['material'];
                        @endphp
                        <div class="card mb-2 shadow-none border" wire:key="necessidade-{{ $necMat->id }}">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <strong>{{ $matEfetivo?->descricao ?? '—' }}</strong>
                                        <span class="badge bg-label-secondary ms-1" style="font-size:.7rem">
                                            {{ $necMat->origem === \App\Enums\OrigemNecessidadeMaterialAtividade::TakeOff ? 'Origem: Conforme Engenharia / TakeOff' : 'Origem: Necessidade operacional — Plano Semanal' }}
                                        </span>
                                    </div>
                                    <span class="badge bg-{{ $linhaMat['estado']->cor() }}">{{ $linhaMat['estado']->label() }}</span>
                                </div>
                                @if ($necMat->observacao)
                                <p class="small text-muted mb-1">{{ $necMat->observacao }}</p>
                                @endif
                                <p class="small text-muted mb-1" style="font-size:.7rem">
                                    Registrado por {{ $necMat->autor ? "{$necMat->autor->first_name} {$necMat->autor->last_name}" : 'Usuário removido' }}
                                    em {{ $necMat->created_at->format('d/m/Y H:i') }}
                                </p>
                                <div class="row g-2 small text-center mb-2">
                                    <div class="col">
                                        <div class="text-muted">Necessário</div>
                                        <strong>{{ number_format($linhaMat['necessario'], 2, ',', '.') }} {{ $linhaMat['unidade_necessidade']?->codigo }}</strong>
                                    </div>
                                    @if ($linhaMat['unidade_compativel'])
                                    <div class="col">
                                        <div class="text-muted">Reservado (atividade)</div>
                                        <strong>{{ number_format($linhaMat['reservado_atividade'], 2, ',', '.') }}</strong>
                                    </div>
                                    <div class="col">
                                        <div class="text-muted">Físico (obra)</div>
                                        <strong>{{ number_format($linhaMat['fisico_obra'], 2, ',', '.') }}</strong>
                                    </div>
                                    <div class="col">
                                        <div class="text-muted">Livre (obra)</div>
                                        <strong>{{ number_format($linhaMat['livre_obra'], 2, ',', '.') }}</strong>
                                    </div>
                                    <div class="col">
                                        <div class="text-muted">Déficit</div>
                                        <strong class="{{ $linhaMat['deficit'] > 0 ? 'text-danger' : '' }}">{{ number_format($linhaMat['deficit'], 2, ',', '.') }}</strong>
                                    </div>
                                    @else
                                    <div class="col-9">
                                        <div class="text-danger small">
                                            <i class="bx bx-error-circle me-1"></i>Unidade da necessidade ({{ $linhaMat['unidade_necessidade']?->codigo }}) diverge da unidade do Material ({{ $linhaMat['unidade_material']?->codigo }}) — cobertura não pode ser calculada.
                                        </div>
                                    </div>
                                    @endif
                                </div>
                                @if (Auth::user()?->temPermissaoNaObra($obra->id, 'restricoes.plano_semanal', 'editar'))
                                <div class="d-flex gap-2">
                                    @if ($linhaMat['estado'] === \App\Enums\EstadoNecessidadeMaterialAtividade::DisponivelParaReserva || $linhaMat['estado'] === \App\Enums\EstadoNecessidadeMaterialAtividade::Parcial)
                                    @if ($this->pacotesDaAtividadePopup->isNotEmpty())
                                    <button type="button" class="btn btn-xs btn-outline-success py-0 px-2" wire:click="abrirModalReservar('{{ $necMat->id }}')">
                                        <i class="bx bx-lock-alt me-1"></i>Reservar agora
                                    </button>
                                    @else
                                    <span class="small text-muted" title="Vincule esta atividade a um Pacote de Compra (aba Suprimentos) para reservar diretamente pelo popup">
                                        <i class="bx bx-info-circle me-1"></i>Sem Pacote vinculado pra reservar
                                    </span>
                                    @endif
                                    @endif
                                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" wire:click="abrirEdicaoNecessidade('{{ $necMat->id }}')">
                                        <i class="bx bx-edit-alt"></i>
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-danger py-0 px-2"
                                            onclick="confirmarAcao(this, {
                                                mensagem: 'Remover esta necessidade de material?',
                                                metodo: 'removerNecessidade',
                                                args: ['{{ $necMat->id }}'],
                                                corBotao: 'danger',
                                                icone: 'bx-trash',
                                            })">
                                        <i class="bx bx-trash"></i>
                                    </button>
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- =========================================================================
         MODAL: CRIAR RESTRIÇÃO (mesmos campos de ⚡lookahead.blade.php)
         ========================================================================= --}}
    @if ($modalRestricaoAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-shield-alt-2 me-2"></i>Nova Restrição</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalRestricaoAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Descrição <span class="text-danger">*</span></label>
                        <textarea class="form-control @error('descricaoNova') is-invalid @enderror"
                                  rows="3" wire:model="descricaoNova"
                                  placeholder="Descreva o impedimento com clareza..."></textarea>
                        @error('descricaoNova')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Tipo de Restrição</label>
                            <select class="form-select" wire:model="categoriaIdNova">
                                <option value="">— Sem tipo —</option>
                                @foreach ($this->categorias as $catPlano)
                                <option value="{{ $catPlano->id }}">{{ $catPlano->nome }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prazo limite para resolução</label>
                            <input type="date" class="form-control" wire:model="prazolimiteNova">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Responsável por resolver</label>
                        <div class="mb-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" id="planoRespInterno" wire:model.live="responsavelExterno" value="0">
                                <label class="form-check-label" for="planoRespInterno">Usuário interno</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" id="planoRespExterno" wire:model.live="responsavelExterno" value="1">
                                <label class="form-check-label" for="planoRespExterno">Externo</label>
                            </div>
                        </div>
                        @if (! $responsavelExterno)
                        <select class="form-select" wire:model="responsavelIdNova">
                            <option value="">— Sem responsável —</option>
                            @foreach ($this->usuariosDaObra as $uPlano)
                            <option value="{{ $uPlano->id }}">{{ $uPlano->first_name }} {{ $uPlano->last_name }}</option>
                            @endforeach
                        </select>
                        @else
                        <input type="text" class="form-control" wire:model="responsavelExternoNova" placeholder="Nome da empresa ou pessoa externa...">
                        @endif
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Probabilidade (0–10)</label>
                            <input type="number" class="form-control" min="0" max="10" wire:model="probabilidadeNova">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Impacto (0–10)</label>
                            <input type="number" class="form-control" min="0" max="10" wire:model="impactoNova">
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="planoBloqueante" wire:model="blocanteNova">
                        <label class="form-check-label" for="planoBloqueante">
                            <strong>Restrição bloqueante</strong>
                            <small class="text-muted d-block">Impede que a atividade seja comprometida no Plano Semanal</small>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="$set('modalRestricaoAberto', false)">Cancelar</button>
                    <button type="button" class="btn btn-primary" wire:click="salvarRestricao" wire:loading.attr="disabled">
                        Registrar Restrição
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- =========================================================================
         MODAL: DAR BAIXA NA RESTRIÇÃO (mesmo fluxo de ⚡lookahead.blade.php)
         ========================================================================= --}}
    @if ($baixandoRestricaoId)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-check-circle me-2 text-success"></i>Dar Baixa na Restrição</h5>
                    <button type="button" class="btn-close" wire:click="$set('baixandoRestricaoId', null)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Data da baixa <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('dataBaixaNova') is-invalid @enderror"
                               wire:model="dataBaixaNova">
                        @error('dataBaixaNova')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observação (opcional)</label>
                        <textarea class="form-control" rows="3" wire:model="textoBaixaNova"
                                  placeholder="Explique como a restrição foi resolvida, se relevante..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="$set('baixandoRestricaoId', null)">Cancelar</button>
                    <button type="button" class="btn btn-success" wire:click="darBaixaRestricao" wire:loading.attr="disabled">
                        Confirmar Baixa
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- =========================================================================
         MELHORIA "POSTO OPERACIONAL" — MODAL: ADICIONAR/EDITAR NECESSIDADE
         DE MATERIAL (Seção 13). Identidade (origem/item/material) só é
         escolhida na CRIAÇÃO — ao editar, só quantidade/observação mudam
         (mesma imutabilidade de identidade já documentada no Action).
         ========================================================================= --}}
    @if ($modalNecessidadeAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-cube me-2"></i>{{ $necessidadeEditandoId ? 'Editar Necessidade de Material' : 'Adicionar Material' }}</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalNecessidadeAberto', false)"></button>
                </div>
                <div class="modal-body">
                    @if (! $necessidadeEditandoId)
                    <div class="mb-3">
                        <label class="form-label d-block">Origem</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="origemTakeOff" wire:model.live="origemNovaNecessidade" value="take_off">
                            <label class="form-check-label" for="origemTakeOff">Conforme Engenharia / TakeOff</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="origemOperacional" wire:model.live="origemNovaNecessidade" value="operacional">
                            <label class="form-check-label" for="origemOperacional">Necessidade adicional da atividade</label>
                        </div>
                        @if ($origemNovaNecessidade === 'operacional')
                        <p class="small text-muted mb-0 mt-1">Use para materiais necessários à execução que não vieram do levantamento da Engenharia.</p>
                        @endif
                    </div>

                    @if ($origemNovaNecessidade === 'take_off')
                    <div class="mb-3">
                        <label class="form-label">Item de TakeOff <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('itemTakeOffIdNovaNecessidade') is-invalid @enderror"
                               placeholder="Buscar por código ou descrição..." wire:model.live.debounce.300ms="buscaItemTakeOffNecessidade">
                        @error('itemTakeOffIdNovaNecessidade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if ($buscaItemTakeOffNecessidade !== '')
                        <div class="list-group list-group-flush mt-2" style="max-height: 180px; overflow-y: auto">
                            @forelse ($this->itensTakeOffParaNecessidade as $itemDisp)
                            <button type="button" class="list-group-item list-group-item-action py-2 {{ $itemTakeOffIdNovaNecessidade === $itemDisp->id ? 'active' : '' }}"
                                    wire:key="item-tko-{{ $itemDisp->id }}" wire:click="$set('itemTakeOffIdNovaNecessidade', '{{ $itemDisp->id }}')">
                                <strong>{{ $itemDisp->codigo }}</strong> — {{ $itemDisp->descricao }} ({{ number_format((float) $itemDisp->quantidade, 2, ',', '.') }})
                            </button>
                            @empty
                            <p class="text-muted small mb-0 py-2">Nenhum item de TakeOff encontrado nesta obra.</p>
                            @endforelse
                        </div>
                        @endif
                        @if ($itemTakeOffIdNovaNecessidade && $this->saldoItemTakeOffSelecionado)
                        @php $saldoTko = $this->saldoItemTakeOffSelecionado; @endphp
                        <div class="alert alert-secondary py-2 mt-2 mb-0 small">
                            Quantidade TakeOff: <strong>{{ number_format($saldoTko['quantidade_take_off'], 2, ',', '.') }}</strong> ·
                            Já distribuído: <strong>{{ number_format($saldoTko['distribuido'], 2, ',', '.') }}</strong> ·
                            Saldo disponível: <strong class="{{ $saldoTko['saldo'] < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($saldoTko['saldo'], 2, ',', '.') }}</strong>
                        </div>
                        @endif
                    </div>
                    @else
                    <div class="mb-3">
                        <label class="form-label">Material <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('materialIdNovaNecessidade') is-invalid @enderror"
                               placeholder="Buscar por código ou descrição..." wire:model.live.debounce.300ms="buscaMaterialNecessidade">
                        @error('materialIdNovaNecessidade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if ($buscaMaterialNecessidade !== '')
                        <div class="list-group list-group-flush mt-2" style="max-height: 180px; overflow-y: auto">
                            @forelse ($this->materiaisParaNecessidade as $matDisp)
                            <button type="button" class="list-group-item list-group-item-action py-2 {{ $materialIdNovaNecessidade === $matDisp->id ? 'active' : '' }}"
                                    wire:key="mat-disp-{{ $matDisp->id }}" wire:click="$set('materialIdNovaNecessidade', '{{ $matDisp->id }}')">
                                <strong>{{ $matDisp->codigo }}</strong> — {{ $matDisp->descricao }}
                            </button>
                            @empty
                            <p class="text-muted small mb-0 py-2">Nenhum Material encontrado no catálogo.</p>
                            @endforelse
                        </div>
                        @endif
                        @if ($this->podeCriarMaterialInline())
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" wire:click="abrirModalNovoMaterial">
                            <i class="bx bx-plus me-1"></i>Criar novo Material
                        </button>
                        @endif
                    </div>
                    @endif
                    @endif

                    <div class="mb-3">
                        <label class="form-label">Quantidade necessária <span class="text-danger">*</span></label>
                        <input type="number" step="0.001" min="0.001" class="form-control @error('quantidadeNovaNecessidade') is-invalid @enderror"
                               wire:model="quantidadeNovaNecessidade">
                        @error('quantidadeNovaNecessidade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            {{ $origemNovaNecessidade === 'operacional' || ($necessidadeEditandoId && $itemTakeOffIdNovaNecessidade === null) ? 'Justificativa' : 'Observação (opcional)' }}
                            @if ($origemNovaNecessidade === 'operacional' && ! $necessidadeEditandoId)
                            <span class="text-danger">*</span>
                            @endif
                        </label>
                        <textarea class="form-control @error('observacaoNovaNecessidade') is-invalid @enderror" rows="2"
                                  wire:model="observacaoNovaNecessidade"
                                  placeholder="Por que este material é necessário..."></textarea>
                        @error('observacaoNovaNecessidade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="$set('modalNecessidadeAberto', false)">Cancelar</button>
                    <button type="button" class="btn btn-primary" wire:click="salvarNecessidade" wire:loading.attr="disabled">
                        Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- =========================================================================
         MELHORIA "POSTO OPERACIONAL" — MODAL: NOVO MATERIAL MESTRE (inline,
         sem sair do popup). Reaproveita EXATAMENTE os mesmos campos/regras
         do cadastro oficial (⚡estoque.blade.php::salvarMaterial()), via a
         mesma Action (App\Actions\Estoque\CriarMaterial) — nunca uma versão
         simplificada. Nunca oferecido pra origem=take_off (ver
         AtualizarNecessidadeMaterialAtividade::criarTakeOff() — o Material
         dessa origem é sempre derivado do ItemTakeOff, jamais criado aqui).
         Fica em CIMA do modal de necessidade (z-index maior) — o popup da
         atividade e o modal de necessidade permanecem montados por trás,
         nunca perdem contexto/estado.
         ========================================================================= --}}
    @if ($modalNovoMaterialAberto)
    {{-- z-index explícito e ALTO (nunca só "+10" arbitrário) — achado real
         de correção (reprodução em navegador real): os modais já abertos
         nesta mesma página (popup de detalhe da atividade + "Adicionar
         Material") computam `z-index:1090` no CSS do tema (Vuexy/Bootstrap,
         que sobrescreve o default do Bootstrap puro) — um valor MENOR aqui
         (1060, usado antes desta correção) faz este modal renderizar
         literalmente ATRÁS dos outros dois, invisível e inclicável (o
         backdrop do modal de cima intercepta 100% dos cliques na tela
         inteira). 1100 garante margem segura acima de QUALQUER modal já
         empilhado nesta página. --}}
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5); z-index:1100">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-cube-alt me-2"></i>Novo Material</h5>
                    <button type="button" class="btn-close" wire:click="fecharModalNovoMaterial"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Código <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('novoMaterialCodigo') is-invalid @enderror" wire:model="novoMaterialCodigo">
                        @error('novoMaterialCodigo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('novoMaterialDescricao') is-invalid @enderror" wire:model="novoMaterialDescricao">
                        @error('novoMaterialDescricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Unidade de Medida <span class="text-danger">*</span></label>
                        <select class="form-select @error('novoMaterialUnidadeMedidaId') is-invalid @enderror" wire:model="novoMaterialUnidadeMedidaId">
                            <option value="">Selecione...</option>
                            @foreach ($this->unidadesMedidaParaNovoMaterial as $u)
                            <option value="{{ $u->id }}">{{ $u->codigo }} — {{ $u->nome }}</option>
                            @endforeach
                        </select>
                        @error('novoMaterialUnidadeMedidaId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Família (opcional)</label>
                        <select class="form-select" wire:model="novoMaterialFamiliaId">
                            <option value="">—</option>
                            @foreach ($this->familiasMaterialParaNovoMaterial as $f)
                            <option value="{{ $f->id }}">{{ $f->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Modo de Rastreabilidade <span class="text-danger">*</span></label>
                        <select class="form-select" wire:model="novoMaterialModoRastreabilidade">
                            @foreach (\App\Enums\ModoRastreabilidadeMaterial::cases() as $modo)
                            <option value="{{ $modo->value }}">{{ $modo->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="small text-muted mb-0"><i class="bx bx-info-circle me-1"></i>Este cadastro fica registrado como criado a partir do Plano Semanal (reunião de programação) — nunca como se tivesse vindo da Engenharia.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalNovoMaterial">Cancelar</button>
                    <button type="button" class="btn btn-primary" wire:click="salvarNovoMaterialInline" wire:loading.attr="disabled">
                        Salvar e usar
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- =========================================================================
         MELHORIA "POSTO OPERACIONAL" — MODAL: RESERVAR AGORA (Seção 7).
         Reserva NUNCA automática — só este clique explícito, reaproveitando
         App\Actions\Estoque\CriarReservaEstoque, a mesma Action já usada
         em ⚡estoque.blade.php.
         ========================================================================= --}}
    @if ($necessidadeReservandoId)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-lock-alt me-2 text-success"></i>Reservar Material para esta Atividade</h5>
                    <button type="button" class="btn-close" wire:click="$set('necessidadeReservandoId', null)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Pacote de Compra <span class="text-danger">*</span></label>
                        <select class="form-select @error('pacoteIdReserva') is-invalid @enderror" wire:model="pacoteIdReserva">
                            <option value="">— Selecione —</option>
                            @foreach ($this->pacotesDaAtividadePopup as $pacotePop)
                            <option value="{{ $pacotePop->id }}">{{ $pacotePop->codigo }} — {{ $pacotePop->nome }}</option>
                            @endforeach
                        </select>
                        @error('pacoteIdReserva')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Local de Estoque <span class="text-danger">*</span></label>
                        <select class="form-select @error('localIdReserva') is-invalid @enderror" wire:model="localIdReserva">
                            <option value="">— Selecione —</option>
                            @foreach ($this->locaisParaReserva as $localPop)
                            <option value="{{ $localPop->id }}">{{ $localPop->nome }}</option>
                            @endforeach
                        </select>
                        @error('localIdReserva')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantidade a reservar <span class="text-danger">*</span></label>
                        <input type="number" step="0.001" min="0.001" class="form-control @error('quantidadeReserva') is-invalid @enderror"
                               wire:model="quantidadeReserva">
                        @error('quantidadeReserva')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="$set('necessidadeReservandoId', null)">Cancelar</button>
                    <button type="button" class="btn btn-success" wire:click="confirmarReserva" wire:loading.attr="disabled">
                        Confirmar Reserva
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- =========================================================================
         CANVA LATERAL DE FILTROS — mesmo mecanismo do Quadro de Restrições e
         do Lookahead (resources/views/pages/radar/⚡restricoes.blade.php e
         ⚡lookahead.blade.php): painel fixo na borda direita, escondido via
         right:-360px, aba presa na borda esquerda do próprio painel pra
         abrir. Fechado por padrão: é uma sobreposição, não divide espaço
         com a tabela.
         ========================================================================= --}}
    <div class="canva-filtros-plano" :class="filtrosAbertos ? 'canva-filtros-plano-aberto' : ''" x-data="{ filtrosAbertos: false }">
        <button type="button" class="canva-filtros-plano-aba" @click="filtrosAbertos = true" title="Filtros">
            <i class="bx bx-filter-alt"></i>
        </button>

        <div class="canva-filtros-plano-header d-flex align-items-center justify-content-between border-bottom px-4 py-3">
            <h6 class="mb-0 fw-semibold"><i class="bx bx-filter-alt me-1"></i>Filtros</h6>
            <a href="javascript:void(0)" class="text-body" @click="filtrosAbertos = false">
                <i class="bx bx-x fs-4"></i>
            </a>
        </div>

        <div class="canva-filtros-plano-body px-4 py-3">
            <div class="row g-2">
                <div class="col-12">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                        <input type="text" class="form-control" placeholder="Buscar tarefa..."
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="etapaIdFiltro">
                        <option value="">Todas as etapas</option>
                        @foreach ($this->etapas as $et)
                        <option value="{{ $et->id }}">{{ $et->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="frenteTrabalhoIdFiltro">
                        <option value="">Todas as frentes de trabalho</option>
                        @foreach ($this->frentesTrabalho as $f)
                        <option value="{{ $f->id }}">{{ $f->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="faturamentoDiretoFiltro">
                        <option value="">Faturamento Direto: Todos</option>
                        <option value="1">Faturamento Direto: Sim</option>
                        <option value="0">Faturamento Direto: Não</option>
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="entregavelIdFiltro">
                        <option value="">Todos os entregáveis</option>
                        @foreach ($this->entregaveis as $en)
                        <option value="{{ $en->id }}">{{ $en->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="equipeResponsavelIdFiltro">
                        <option value="">Todas as equipes/responsáveis</option>
                        @foreach ($this->equipesResponsaveis as $eq)
                        <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado1IdFiltro">
                        <option value="">Personalizado 1: Todos</option>
                        @foreach ($this->personalizados1 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado2IdFiltro">
                        <option value="">Personalizado 2: Todos</option>
                        @foreach ($this->personalizados2 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado3IdFiltro">
                        <option value="">Personalizado 3: Todos</option>
                        @foreach ($this->personalizados3 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado4IdFiltro">
                        <option value="">Personalizado 4: Todos</option>
                        @foreach ($this->personalizados4 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <select class="form-select form-select-sm" wire:model.live="personalizado5IdFiltro">
                        <option value="">Personalizado 5: Todos</option>
                        @foreach ($this->personalizados5 as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">Liberação</label>
                    <select class="form-select form-select-sm" wire:model.live="liberacaoFiltro">
                        <option value="todas">Todas</option>
                        <option value="liberadas">Liberadas</option>
                        <option value="bloqueadas">Bloqueadas</option>
                    </select>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="togOcultarConcluidasPlano"
                               wire:model.live="ocultarConcluidas">
                        <label class="form-check-label small" for="togOcultarConcluidasPlano">Ocultar concluídas</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">Referência de HH total (cards % Previsto / % Avanço)</label>
                    <select class="form-select form-select-sm" wire:model.live="linhaBaseId">
                        <option value="">Linha de Base: importação mais recente</option>
                        @foreach ($this->linhasBase as $lb)
                        <option value="{{ $lb->id }}">Linha de Base: {{ $lb->nome }} ({{ $lb->importacao?->importado_em?->format('d/m/Y') }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- z-index:1080 fica ACIMA da navbar fixa do template (.layout-navbar,
             z-index:1075) e ABAIXO dos modais do Bootstrap (z-index:1090) — ver
             explicação completa no mesmo bloco em ⚡restricoes.blade.php.
             Bug de teste manual, 2026-09-02 — `top: 0` fazia o canva cobrir a
             faixa da navbar fixa (0 a 3.875rem, = $navbar-height do tema);
             como o z-index do canva é maior, um clique no sino/perfil/
             app-grid nessa faixa era engolido pelo canva aberto (mesmo bug
             nos 8 arquivos que usam este padrão). Corrigido começando o
             canva abaixo da navbar. --}}
        <style>
        .canva-filtros-plano {
            position: fixed;
            top: 3.875rem;
            right: -360px;
            height: calc(100% - 3.875rem);
            z-index: 1080;
            display: flex;
            flex-direction: column;
            width: 360px;
            max-width: 90vw;
            background: var(--bs-body-bg, #fff);
            box-shadow: 0 0 20px 0 rgba(0, 0, 0, .2);
            transition: right .25s ease-in-out;
        }

        .canva-filtros-plano.canva-filtros-plano-aberto {
            right: 0;
        }

        .canva-filtros-plano-body {
            flex: 1 1 auto;
            overflow-y: auto;
        }

        .canva-filtros-plano-aba {
            position: absolute;
            top: 140px;
            left: -42px;
            width: 42px;
            height: 42px;
            border: 0;
            border-top-left-radius: .375rem;
            border-bottom-left-radius: .375rem;
            background: var(--bs-primary);
            color: #fff;
            font-size: 1.1rem;
            box-shadow: -2px 0 8px rgba(0, 0, 0, .15);
            transition: opacity .15s linear;
        }

        .canva-filtros-plano.canva-filtros-plano-aberto .canva-filtros-plano-aba {
            opacity: 0;
            pointer-events: none;
        }

        @media (max-width: 575.98px) {
            .canva-filtros-plano {
                width: 300px;
                right: -300px;
            }

            .canva-filtros-plano.canva-filtros-plano-aberto {
                right: 0;
            }
        }
        </style>
    </div>
</div>

@script
<script>
    $wire.on('show-toast', ({ message, type = 'success' }) => {
        toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
        (toastr[type] || toastr.success)(message);
    });

    // Modal só abre DEPOIS que o servidor confirma que a seleção é
    // válida (abrirConfirmacaoProgramar) — nunca no clique puro do
    // botão, senão a contagem exibida podia ficar desatualizada
    // enquanto o wire:model.live do checkbox ainda estava sincronizando.
    $wire.on('abrir-modal-confirmar-programacao', () => {
        const modalEl = document.getElementById('modalConfirmarProgramacao');
        if (modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });

    // Fecha o modal só quando o PRÓPRIO servidor manda (dispatch dedicado
    // no início de comprometerSelecionadas) — nunca via data-bs-dismiss
    // no mesmo botão que dispara wire:click. Essa combinação é o mesmo
    // padrão de bug já documentado no layout base do projeto (ver
    // resources/views/layouts/sections/scripts.blade.php): o dismiss do
    // Bootstrap corre em paralelo com o morph do Livewire e pode deixar
    // um .modal-backdrop órfão em <body>, bloqueando clique na tela
    // inteira. hideModalConfirmarProgramacao() sempre limpa o backdrop
    // manualmente depois, como rede de segurança extra — nunca confia só
    // no evento 'hidden.bs.modal' do Bootstrap pra isso.
    function hideModalConfirmarProgramacao() {
        const modalEl = document.getElementById('modalConfirmarProgramacao');
        if (modalEl) {
            bootstrap.Modal.getInstance(modalEl)?.hide();
        }
        document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    $wire.on('fechar-modal-confirmar-programacao', hideModalConfirmarProgramacao);
</script>
@endscript
