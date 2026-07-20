<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Actions\Atividade\MarcarNaoConcluido;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Exports\PlanoSemanalExport;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Work;
use App\Services\CurvaAvanco;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {
    use ExecutaComTransacaoSegura;

    public Work $obra;

    // Semana visualizada (início = segunda-feira)
    public string $semanaInicio;

    // Modal "Não Concluído"
    public ?string $naoConcluindoId   = null;
    public string  $descricaoCausa    = '';

    // Seleção manual pra "Inserir na Programação" (só Planejado + liberada)
    public array $selecionadas = [];

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

    // Referência de HH total do projeto pra coluna "% do Projeto".
    // null = importação Baseline/Ambos mais recente (não "ao vivo" —
    // ver App\Services\CurvaAvanco::resolverImportacaoId()).
    public ?string $linhaBaseId = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
        $this->semanaInicio = Carbon::now()->startOfWeek()->toDateString();
    }

    #[Computed]
    public function semanaFim(): string
    {
        return Carbon::parse($this->semanaInicio)->endOfWeek()->toDateString();
    }

    /** Programação (header) já congelada pra semana visualizada, se existir. */
    #[Computed]
    public function programacaoDaSemana(): ?ProgramacaoSemanal
    {
        return ProgramacaoSemanal::where('obra_id', $this->obra->id)
            ->where('semana_inicio', $this->semanaInicio)
            ->with('itens')
            ->first();
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

    /** HH total do projeto (Previsto/Mensal) — denominador da coluna "% do Projeto". */
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
     * "HH cadastrado como zero" na célula da tabela.
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
     * da semana, segundo as datas de tendência (inicio_planejado/
     * data_termino — o cronograma atual, já refletindo a última
     * reimportação; ver App\Models\Atividade e a filosofia "tendência =
     * Work atual" no CLAUDE.md). "Compreendida" é sobreposição de
     * intervalo, não só início OU término dentro da semana — senão uma
     * atividade de 3 semanas que começou antes e termina depois da
     * semana em questão nunca apareceria, mesmo estando em execução bem
     * no meio dela. QUALQUER status entra aqui (inclusive Planejado) —
     * é o próprio Lookahead da semana, não só o que já foi comprometido;
     * reaproveitada por atividades()/idsProntas() pra não duplicar as
     * condições de data.
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
        $query = Atividade::where('obra_id', $this->obra->id)
            ->where('fora_do_cronograma', false)
            ->whereNotNull('inicio_planejado')
            ->whereNotNull('data_termino')
            ->where('inicio_planejado', '<=', $this->semanaFim)
            ->where('data_termino', '>=', $this->semanaInicio);

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

    /** Só pode entrar na seleção pra "Inserir na Programação": ainda Planejado E liberada. */
    #[Computed]
    public function idsSelecionaveis(): array
    {
        return $this->atividades
            ->filter(fn ($at) => $at->status === StatusAtividade::Planejado && $this->idsProntas->contains($at->id))
            ->pluck('id')
            ->all();
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

    /** Distribuição por disciplina das tarefas do período — alimenta o gráfico de pizza. */
    #[Computed]
    public function distribuicaoPorDisciplina(): array
    {
        return $this->atividades
            ->groupBy(fn ($at) => $at->disciplina?->nome ?? 'Sem disciplina')
            ->map->count()
            ->sortDesc()
            ->all();
    }

    public function dadosGraficoDisciplinaParaJs(): array
    {
        return $this->distribuicaoPorDisciplina;
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

    #[Computed]
    public function ppc(): array
    {
        $total = $this->atividadesComprometidas->count();

        if ($total === 0) {
            return ['percentual' => null, 'concluidas' => 0, 'total' => 0];
        }

        $concluidas = $this->atividadesComprometidas
            ->where('status', StatusAtividade::Concluido)
            ->count();

        return [
            'percentual' => round(($concluidas / $total) * 100),
            'concluidas' => $concluidas,
            'total'      => $total,
        ];
    }

    /**
     * Invalida todos os computeds derivados de atividades() — chamado
     * sempre que a semana ou os dados mudam. Os cards de número/tabela
     * reagem sozinhos (Blade re-renderiza a cada ação); o gráfico de
     * disciplina é Chart.js imperativo, então precisa do dispatch pro
     * @script (que só roda uma vez no mount) redesenhar via $wire.on.
     */
    private function invalidarComputeds(): void
    {
        unset(
            $this->atividades,
            $this->atividadesComprometidas,
            $this->idsProntas,
            $this->idsSelecionaveis,
            $this->totalAtividadesPeriodo,
            $this->totalSemRestricao,
            $this->totalComRestricao,
            $this->distribuicaoPorDisciplina,
            $this->ppc,
            $this->arvoreAtividades,
            $this->programacaoDaSemana,
            $this->estaVisualizandoSemanaPassada,
            $this->semanaEstaCongelada,
            $this->hhPrevistoSemanaPorAtividade,
            $this->totalHhProjeto,
            $this->linhaBaseSelecionada,
        );

        $this->dispatch('disciplina-periodo-atualizada', dados: $this->dadosGraficoDisciplinaParaJs());
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

    public function semanAnterior(): void
    {
        $this->semanaInicio = Carbon::parse($this->semanaInicio)->subWeek()->toDateString();
        $this->selecionadas = [];
        $this->invalidarComputeds();
    }

    public function semanaSeguinte(): void
    {
        $this->semanaInicio = Carbon::parse($this->semanaInicio)->addWeek()->toDateString();
        $this->selecionadas = [];
        $this->invalidarComputeds();
    }

    public function marcarConcluida(string $id): void
    {
        if ($this->semanaEstaCongelada) {
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
        if ($this->semanaEstaCongelada) {
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
     * "Inserir na Programação": comprometer as atividades selecionadas
     * manualmente. Reforça no servidor a mesma regra do Lookahead
     * (App\Enums\Papel/CLAUDE.md: só vai pro plano quem está pronta) —
     * a UI já desabilita o checkbox das bloqueadas, mas nunca confia só
     * nisso; filtra de novo contra idsProntas antes de gravar.
     */
    public function comprometerSelecionadas(): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.lookahead', 'editar'), 403);

        if ($this->semanaEstaCongelada) {
            $this->dispatch('show-toast', message: 'Esta semana já está congelada; não é possível alterá-la.', type: 'error');
            return;
        }

        $idsValidos = collect($this->selecionadas)
            ->intersect($this->idsProntas)
            ->values();

        if ($idsValidos->isEmpty()) {
            $this->dispatch('show-toast', message: 'Nenhuma atividade liberada selecionada.');
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
    {{-- Navegação de semana + PPC --}}
    <div class="row g-3 mb-4 align-items-center">
        <div class="col-md-6">
            <div class="d-flex align-items-center gap-2 flex-wrap">
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
                @if ($this->atividades->count() > 0)
                <button class="btn btn-outline-danger btn-sm ms-2" wire:click="exportarPdf">
                    <i class="bx bxs-file-pdf me-1"></i>PDF
                </button>
                <button class="btn btn-outline-success btn-sm" wire:click="exportarExcel">
                    <i class="bx bxs-file-export me-1"></i>Excel
                </button>
                @endif
            </div>
        </div>

        {{-- PPC --}}
        <div class="col-md-6">
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

    {{-- Cards de totais + gráfico por disciplina — reativos ao filtro de semana --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold">{{ $this->totalAtividadesPeriodo }}</div>
                    <small class="text-muted">Atividades do Período</small>
                </div>
            </div>
        </div>
        @if ($this->semanaEstaCongelada)
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-muted">—</div>
                    <small class="text-muted" title="Semana congelada: liberação não é reavaliada">Restrição (não reavaliado)</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-muted">—</div>
                    <small class="text-muted" title="Semana congelada: liberação não é reavaliada">Liberação (não reavaliado)</small>
                </div>
            </div>
        </div>
        @else
        <div class="col-6 col-md-3">
            <div class="card h-100 {{ $this->totalComRestricao > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold {{ $this->totalComRestricao > 0 ? 'text-danger' : '' }}">
                        {{ $this->totalComRestricao }}
                    </div>
                    <small class="text-muted">Com Restrição (Bloqueadas)</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 border-success">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-success">{{ $this->totalSemRestricao }}</div>
                    <small class="text-muted">Sem Restrição (Liberadas)</small>
                </div>
            </div>
        </div>
        @endif
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-header py-2"><small class="text-muted mb-0">Por Disciplina</small></div>
                <div class="card-body py-2">
                    @if (count($this->distribuicaoPorDisciplina) > 0)
                    <div style="height: 110px;">
                        <canvas id="graficoDisciplinaPeriodo"></canvas>
                    </div>
                    @else
                    <p class="text-muted text-center small mb-0 py-4">Sem dados.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Tabela de atividades (hierarquia EAP — mesma estrutura do cronograma) --}}
    @if ($this->atividades->count() > 0)
        @unless ($this->semanaEstaCongelada)
        @if (count($this->idsSelecionaveis) > 0)
        <div class="d-flex align-items-center gap-2 mb-2">
            <button class="btn btn-success btn-sm" wire:click="comprometerSelecionadas"
                    wire:loading.attr="disabled" @disabled(count($selecionadas) === 0)>
                <i class="bx bx-list-check me-1"></i>
                Inserir na Programação ({{ count($selecionadas) }})
            </button>
            <small class="text-muted">Só atividades liberadas e ainda Planejadas podem ser selecionadas.</small>
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
                        <th class="text-center" style="width: 36px">
                            @unless ($this->semanaEstaCongelada)
                            @if (count($this->idsSelecionaveis) > 0)
                            <input type="checkbox" class="form-check-input" wire:click="toggleSelecionarTodas"
                                   @checked(count($selecionadas) > 0 && count(array_intersect($this->idsSelecionaveis, $selecionadas)) === count($this->idsSelecionaveis))
                                   title="Selecionar todas as liberadas">
                            @endif
                            @endunless
                        </th>
                        <th>Atividade</th>
                        <th>Disciplina</th>
                        <th>Responsável</th>
                        <th class="text-center">Início Plan.</th>
                        <th class="text-center">Fim Plan.</th>
                        <th class="text-center">% do Projeto</th>
                        <th class="text-center">Liberação</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Ações</th>
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
                            <td colspan="10" style="padding-left: {{ $linha['nivel'] * 24 }}px">
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
                            $selecionavel = $statusVal === 'planejado' && $pronta;
                        @endphp
                        <tr wire:key="atividade-{{ $at->id }}"
                            x-show="!({{ $ancestraisJson }}).some(id => recolhidos.includes(id))">
                            <td class="text-center">
                                @unless ($this->semanaEstaCongelada)
                                @if ($selecionavel)
                                <input type="checkbox" class="form-check-input" wire:model.live="selecionadas" value="{{ $at->id }}">
                                @endif
                                @endunless
                            </td>
                            <td style="padding-left: 24px">
                                <span class="fw-semibold">{{ $at->nome }}</span>
                                @if ($at->caminho_critico)
                                    <span class="badge bg-label-danger ms-1 align-middle" title="Caminho crítico">CC</span>
                                @endif
                            </td>
                            <td>{{ $at->disciplina?->nome ?? '—' }}</td>
                            <td>{{ $at->responsavel?->name ?? '—' }}</td>
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
                                    $percentualProjeto = ($hhSemana !== null && $this->totalHhProjeto > 0)
                                        ? round(((float) $hhSemana / $this->totalHhProjeto) * 100, 2)
                                        : null;
                                @endphp
                                @if ($percentualProjeto !== null)
                                    <small>{{ number_format($percentualProjeto, 2) }}%</small>
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
                                @unless ($this->semanaEstaCongelada)
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
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="togOcultarConcluidasPlano"
                               wire:model.live="ocultarConcluidas">
                        <label class="form-check-label small" for="togOcultarConcluidasPlano">Ocultar concluídas</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">Referência de HH total (coluna % do Projeto)</label>
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
             explicação completa no mesmo bloco em ⚡restricoes.blade.php. --}}
        <style>
        .canva-filtros-plano {
            position: fixed;
            top: 0;
            right: -360px;
            height: 100%;
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
    let graficoDisciplinaPeriodo = null;

    function desenharDisciplinaPeriodo(dados) {
        const canvas = document.getElementById('graficoDisciplinaPeriodo');
        if (!canvas) {
            graficoDisciplinaPeriodo = null;
            return;
        }

        const cores = ['#696cff', '#03c3ec', '#ffab00', '#71dd37', '#ff3e1d', '#8592a3', '#e83e8c', '#20c997'];
        const labels = Object.keys(dados);
        const valores = Object.values(dados);
        const backgroundColor = labels.map((_, i) => cores[i % cores.length]);

        if (graficoDisciplinaPeriodo && graficoDisciplinaPeriodo.canvas === canvas) {
            graficoDisciplinaPeriodo.data.labels = labels;
            graficoDisciplinaPeriodo.data.datasets[0].data = valores;
            graficoDisciplinaPeriodo.data.datasets[0].backgroundColor = backgroundColor;
            graficoDisciplinaPeriodo.update();
            return;
        }

        graficoDisciplinaPeriodo = new Chart(canvas, {
            type: 'pie',
            data: { labels, datasets: [{ data: valores, backgroundColor }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } },
            },
        });
    }

    desenharDisciplinaPeriodo(@json($this->dadosGraficoDisciplinaParaJs()));

    $wire.on('disciplina-periodo-atualizada', ({ dados }) => desenharDisciplinaPeriodo(dados));

    $wire.on('show-toast', ({ message, type = 'success' }) => {
        toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
        (toastr[type] || toastr.success)(message);
    });
</script>
@endscript
