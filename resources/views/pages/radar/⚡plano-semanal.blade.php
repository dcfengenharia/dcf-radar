<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use App\Actions\Atividade\MarcarNaoConcluido;
use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
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
                                <span class="fw-semibold">{{ $at->nome }}</span>
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
