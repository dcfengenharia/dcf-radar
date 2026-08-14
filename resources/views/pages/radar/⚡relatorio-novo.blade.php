<?php

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Work;
use App\Services\CurvaAvanco;
use App\Services\ImpactoRestricoesGerador;
use App\Services\ReportGerador;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use App\Support\Report\DiagnosticoReport;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Assistente de criação do Report semanal — 5 passos:
 * 1) período + linha de base; 2) escolha das curvas (pacotes da EAP ou
 * "obra inteira"); 3) pontos de atenção por curva; 4) fotos do período;
 * 5) revisão e salvar rascunho.
 *
 * Quem efetivamente CALCULA os números (curva S, quadro de desvios) é o
 * App\Services\ReportGerador — este componente só monta as OPÇÕES
 * (quais curvas, pontos de atenção, fotos) e delega a ele.
 */
new class extends Component {
    use WithFileUploads;
    use ExecutaComTransacaoSegura;

    /**
     * Limiares do velocímetro de aderência (zonas vermelha/amarela/verde) —
     * espelham as mesmas constantes em ⚡relatorio-detalhe.blade.php (mudar
     * um lado exige mudar o outro, convenção já usada no projeto pra não
     * compartilhar esse tipo de helper pequeno via trait).
     */
    private const ADERENCIA_LIMIAR_OTIMO = 95.0;
    private const ADERENCIA_LIMIAR_ATENCAO = 80.0;

    public Work $obra;

    public string $etapa = '1';

    // Passo 1
    public string  $periodoReferencia = '';
    public ?string $linhaBaseId       = null;
    public ?string $avancoImportacaoId = null;
    public string  $titulo            = '';

    // Passo 2 — seleção de curvas
    public bool  $obraInteiraMarcada = false;
    public array $pacotesMarcados    = []; // [pacoteId => true]
    public array $ordemCurvas        = []; // ['obra', pacoteId, ...] na ordem de exibição

    // Passo 3 — pontos de atenção por curva
    public array $pontosPorCurva = []; // [curveKey => [['categoria'=>.., 'texto'=>..], ...]]

    // Passo 4 — fotos
    public array $novasFotos    = [];
    public array $legendasFotos = [];

    /**
     * Fase 1 do wizard de Diagnóstico Colaborativo — o Report passa a ser
     * criado ao SAIR do passo 2 (ver avancar()/prepararRascunhoDoDiagnostico()),
     * não mais só no passo 5 (salvar()). $reportAssinatura guarda a
     * "assinatura" das opções que geraram $report — usada pra decidir se um
     * novo avanço do passo 2 pode reaproveitar o Report já existente ou se
     * precisa descartá-lo (forceDelete()) e recriar. Ambos precisam ser
     * propriedades PÚBLICAS (não privadas) pra sobreviver à
     * serialização/hidratação do Livewire entre requests — é exatamente
     * esse ciclo que exige o hydrate() novo logo abaixo.
     */
    public ?Report $report = null;
    public array $reportAssinatura = [];

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
        $this->authorize('create', [Report::class, $obra->id]);
        $this->periodoReferencia = now()->startOfWeek()->toDateString();
    }

    /**
     * Proteção contra a MESMA classe de bug de rehidratação do Livewire já
     * corrigida em ⚡relatorio-detalhe.blade.php (Collection::
     * getQueueableRelations() faz array_intersect() entre relações
     * aninhadas de itens de uma coleção — quando 2+ curvas de um Report têm
     * sub-relações assimétricas, ex.: uma com desvios e outra sem nenhum,
     * o caminho presente só em algumas é descartado na rehidratação entre
     * requests). Antes desta fase o wizard nunca tinha um Report como
     * propriedade Livewire — agora tem, então herda o mesmo risco.
     *
     * Lista IDÊNTICA à que App\Support\Report\DiagnosticoReport::calcular()
     * usa internamente — duplicada de propósito (mesma convenção já usada
     * entre este arquivo e o detalhe pra outras constantes/helpers
     * pequenos), não fatorada via relacoesReportCompletas() do detalhe,
     * que carrega relações extras (fotos/comentários/criador/emissor) que
     * o diagnóstico não usa e que este wizard ainda não expõe (Fotos e
     * Revisão continuam com sua própria lógica, intocada nesta fase).
     */
    public function hydrate(): void
    {
        if ($this->report) {
            $this->report->loadMissing([
                'cronogramaImportacao.healthCheck',
                'curvas.datapoints',
                'curvas.desvios.pacoteTrabalho',
                'curvas.desvios.restricaoImpacto',
                'curvas.pacoteTrabalho',
            ]);
        }
    }

    /**
     * Diagnósticos reais do Report recém-criado (Fase 1 do wizard de
     * Diagnóstico Colaborativo) — única fonte de cálculo é
     * App\Support\Report\DiagnosticoReport::calcular(), a MESMA classe já
     * usada por ⚡relatorio-detalhe.blade.php (Ciclo 2), nunca duplicada
     * aqui. Sem Report ainda (usuário não passou do passo 2), devolve um
     * array vazio — nunca inventa diagnóstico. A UI do Passo 3 que
     * consome isso fica pro próximo ciclo.
     */
    #[Computed]
    public function diagnostico(): array
    {
        return $this->report ? app(DiagnosticoReport::class)->calcular($this->report) : [];
    }

    #[Computed]
    public function linhasBase(): \Illuminate\Support\Collection
    {
        return LinhaBase::where('obra_id', $this->obra->id)
            ->with('importacao:id,data_status,importado_em')
            ->latest()
            ->get(['id', 'nome', 'cronograma_importacao_id']);
    }

    /** Importações elegíveis como fonte de Realizado/Tendência — nunca uma importação puramente Baseline. */
    #[Computed]
    public function avancosDisponiveis(): \Illuminate\Support\Collection
    {
        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->whereIn('tipo', [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value])
            ->orderByDesc('importado_em')
            ->get(['id', 'importado_em', 'data_status']);
    }

    /** Compara códigos de EAP segmento a segmento — mesmo helper usado no Lookahead/Linhas de Base. */
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

    #[Computed]
    public function arvorePacotes(): array
    {
        $todos = PacoteTrabalho::where('obra_id', $this->obra->id)->get(['id', 'nome', 'codigo', 'parent_id']);
        $porPai = $todos->groupBy('parent_id');

        $resultado = [];
        $percorrer = function ($paiId, $nivel) use (&$percorrer, &$resultado, $porPai) {
            $filhos = ($porPai->get($paiId) ?? collect())
                ->sort(fn ($a, $b) => $this->compararCodigos($a->codigo, $b->codigo));

            foreach ($filhos as $p) {
                $resultado[] = ['pacote' => $p, 'nivel' => $nivel];
                $percorrer($p->id, $nivel + 1);
            }
        };
        $percorrer(null, 0);

        return $resultado;
    }

    #[Computed]
    public function pacotesById(): \Illuminate\Support\Collection
    {
        return PacoteTrabalho::where('obra_id', $this->obra->id)->get()->keyBy('id');
    }

    public function tituloCurva(string $curveKey): string
    {
        if ($curveKey === 'obra') {
            return $this->obra->name . ' (obra inteira)';
        }

        $pacote = $this->pacotesById->get($curveKey);

        return $pacote ? "{$pacote->codigo} - {$pacote->nome}" : $curveKey;
    }

    private const MESES_PT = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

    /**
     * Prévia do gráfico de cada curva selecionada, pro passo 3 (Pontos de
     * Atenção) — ajuda o usuário a escrever notas melhores vendo a curva
     * antes de salvar. Como o Report ainda não existe nesse ponto, consulta
     * App\Services\CurvaAvanco AO VIVO (não fere a filosofia de fotografia:
     * nada foi gravado ainda). Espelha a mesma correção de %realizado do
     * App\Services\ReportGerador::gerarCurva() via CurvaAvanco::
     * rebasearPercentual() — senão a prévia mostraria um número diferente
     * do que será salvo. Inclui mensal, semanal (últimas 4 semanas) e a
     * aderência da última semana atualizada — mesmo conteúdo que
     * ⚡relatorio-detalhe.blade.php mostra depois de gerado, só que ao vivo.
     */
    #[Computed]
    public function dadosGraficosPreview(): array
    {
        $curvaAvanco = app(CurvaAvanco::class);

        return collect($this->ordemCurvas)->map(function (string $chave) use ($curvaAvanco) {
            $pacoteId = $chave === 'obra' ? null : $chave;

            $totalHhPrevisto = $pacoteId
                ? (PacoteTrabalho::find($pacoteId)?->totalHhBaseline($this->cronogramaIdPrevisto()) ?? 0.0)
                : $curvaAvanco->totalCalculado($this->obra, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, null, $this->linhaBaseId);

            $mensal = $this->serieParaGraficoPreview($curvaAvanco, $pacoteId, $totalHhPrevisto, GranularidadePeriodo::Mensal);
            $semanal = $this->serieParaGraficoPreview($curvaAvanco, $pacoteId, $totalHhPrevisto, GranularidadePeriodo::Semanal);

            return [
                'chave' => $chave,
                'mensal' => ['labels' => $mensal['labels'], 'barras' => $mensal['barras'], 'linhas' => $mensal['linhas']],
                'semanal' => ['labels' => $semanal['labels'], 'barras' => $semanal['barras'], 'linhas' => $semanal['linhas']],
                // Aderência da última semana ATUALIZADA (com previsto E
                // realizado), não necessariamente a última semana da
                // janela — mesma regra de ⚡relatorio-detalhe.blade.php::
                // aderenciaDaUltimaSemanaAtualizada().
                'aderencia_atual' => $this->ultimaAderenciaNaoNula($semanal['aderencias']),
            ];
        })->all();
    }

    /**
     * Monta labels/barras (% do período)/linhas (% acumulado) de UMA
     * granularidade, ao vivo via CurvaAvanco — usado tanto pra mensal
     * quanto semanal na prévia do assistente. Semanal já recorta pras
     * últimas 4 semanas antes do período de referência, mesma janela que
     * App\Services\ReportGerador::recortarUltimasQuatroSemanas() usa na
     * geração de verdade.
     */
    private function serieParaGraficoPreview(CurvaAvanco $curvaAvanco, ?string $pacoteId, float $totalHhPrevisto, GranularidadePeriodo $gran): array
    {
        $pontosPorSerie = [];
        foreach (SerieAvanco::cases() as $serie) {
            $pontos = $curvaAvanco->calcular($this->obra, $serie, $gran, $pacoteId, $this->linhaBaseId, $this->avancoImportacaoId);
            $pontosPorSerie[$serie->value] = collect($curvaAvanco->rebasearPercentual($pontos, $totalHhPrevisto))
                ->keyBy('periodo_inicio');
        }

        $periodos = collect($pontosPorSerie)->flatMap(fn ($c) => $c->keys())->unique()->sort()->values();

        if ($gran === GranularidadePeriodo::Semanal) {
            $fim = Carbon::parse($this->periodoReferencia ?: now())->startOfWeek();
            $inicio = $fim->copy()->subWeeks(3)->toDateString();
            $periodos = $periodos->filter(fn ($p) => $p >= $inicio && $p <= $fim->toDateString())->values();
        }

        $labels = $periodos->map(fn ($p) => $gran === GranularidadePeriodo::Mensal
            ? self::MESES_PT[Carbon::parse($p)->month - 1] . '/' . Carbon::parse($p)->format('y')
            : sprintf('SEM %02d/%d', Carbon::parse($p)->weekOfYear, Carbon::parse($p)->year))->all();

        // Barras = % que o HH daquele período representa do total de
        // linha de base — mesmo eixo 0-100% das linhas de % acumulado
        // (nunca misturar HH com % no mesmo gráfico).
        $barras = [];
        $linhas = [];
        foreach (['previsto' => 'Previsto', 'tendencia' => 'Tendência', 'realizado' => 'Realizado'] as $serieValue => $label) {
            $porPeriodo = $pontosPorSerie[$serieValue];
            $barras[$serieValue] = [
                'label' => $label,
                'data' => $periodos->map(function ($p) use ($porPeriodo, $totalHhPrevisto) {
                    if (! $porPeriodo->has($p)) {
                        return null;
                    }

                    return $totalHhPrevisto > 0 ? round((float) $porPeriodo[$p]['horas_exibir'] / $totalHhPrevisto * 100, 2) : 0.0;
                })->all(),
            ];
            $linhas[$serieValue] = [
                'label' => $label,
                'data' => $periodos->map(fn ($p) => $porPeriodo->has($p) ? (float) $porPeriodo[$p]['percentual'] : null)->all(),
            ];
        }

        // Aderência DO PERÍODO (%realizado do período ÷ %previsto do
        // período) — só faz sentido pra granularidade semanal, mas
        // calculada aqui pras duas pra reaproveitar o mesmo helper.
        $aderencias = $periodos->map(function ($p, $i) use ($barras) {
            $previstoPct = $barras['previsto']['data'][$i];
            $realizadoPct = $barras['realizado']['data'][$i];

            return ($previstoPct !== null && $previstoPct > 0 && $realizadoPct !== null)
                ? round($realizadoPct / $previstoPct * 100, 2)
                : null;
        })->all();

        return ['labels' => $labels, 'barras' => $barras, 'linhas' => $linhas, 'aderencias' => $aderencias];
    }

    /** Varre de trás pra frente e devolve o primeiro valor não-nulo — última semana com previsto E realizado. */
    private function ultimaAderenciaNaoNula(array $aderencias): ?float
    {
        for ($i = count($aderencias) - 1; $i >= 0; $i--) {
            if ($aderencias[$i] !== null) {
                return $aderencias[$i];
            }
        }

        return null;
    }

    /** Limiares do velocímetro de aderência, expostos pro Blade repassar ao JS (evita duplicar os números). */
    public function limiaresAderencia(): array
    {
        return [self::ADERENCIA_LIMIAR_ATENCAO, self::ADERENCIA_LIMIAR_OTIMO];
    }

    /**
     * Wrapper fino, chamável como ação Livewire pelo JS — métodos
     * decorados com #[Computed] não podem ser invocados diretamente via
     * $wire.metodo() (Livewire lança CannotCallComputedDirectlyException),
     * só acessados como propriedade dentro do próprio componente/Blade.
     * Ver o bloco de script no fim do arquivo pra entender por que a
     * prévia precisa ser buscada ao vivo (não embutida via JSON estático)
     * a cada vez que o passo 3 é alcançado.
     */
    public function dadosGraficosPreviewParaJs(): array
    {
        return $this->dadosGraficosPreview;
    }

    /**
     * Mesma resolução de importação usada pelo ReportGerador/CurvaAvanco —
     * linha de base só vale pra Previsto; sem pin, cai na última importação
     * ELEGÍVEL (baseline/ambos — nunca escolhe uma importação puramente de
     * avanço como fonte de linha de base).
     */
    private function cronogramaIdPrevisto(): ?string
    {
        if ($this->linhaBaseId) {
            return LinhaBase::find($this->linhaBaseId)?->cronograma_importacao_id;
        }

        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->whereIn('tipo', [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value])
            ->orderByDesc('importado_em')
            ->orderByDesc('id')
            ->value('id');
    }

    public function toggleObraInteira(): void
    {
        $this->obraInteiraMarcada = ! $this->obraInteiraMarcada;
        $this->sincronizarOrdemCurvas();
    }

    public function togglePacote(string $pacoteId): void
    {
        if (isset($this->pacotesMarcados[$pacoteId])) {
            unset($this->pacotesMarcados[$pacoteId]);
        } else {
            $this->pacotesMarcados[$pacoteId] = true;
        }
        $this->sincronizarOrdemCurvas();
    }

    private function sincronizarOrdemCurvas(): void
    {
        $desejado = [];
        if ($this->obraInteiraMarcada) {
            $desejado[] = 'obra';
        }
        foreach (array_keys($this->pacotesMarcados) as $pid) {
            $desejado[] = $pid;
        }

        // Preserva a ordem já definida (via ▲/▼) pras chaves que continuam
        // marcadas; só acrescenta as novas no final.
        $preservados = array_values(array_intersect($this->ordemCurvas, $desejado));
        $novos = array_values(array_diff($desejado, $preservados));
        $this->ordemCurvas = [...$preservados, ...$novos];

        foreach ($this->ordemCurvas as $chave) {
            $this->pontosPorCurva[$chave] ??= [];
        }
        foreach (array_keys($this->pontosPorCurva) as $chave) {
            if (! in_array($chave, $this->ordemCurvas, true)) {
                unset($this->pontosPorCurva[$chave]);
            }
        }
    }

    public function moverCurva(string $chave, int $direcao): void
    {
        $idx = array_search($chave, $this->ordemCurvas, true);
        if ($idx === false) {
            return;
        }

        $novoIdx = $idx + $direcao;
        if ($novoIdx < 0 || $novoIdx >= count($this->ordemCurvas)) {
            return;
        }

        [$this->ordemCurvas[$idx], $this->ordemCurvas[$novoIdx]] = [$this->ordemCurvas[$novoIdx], $this->ordemCurvas[$idx]];
    }

    public function adicionarPontoAtencao(string $chave): void
    {
        $this->pontosPorCurva[$chave][] = ['categoria' => '', 'texto' => ''];
    }

    public function removerPontoAtencao(string $chave, int $indice): void
    {
        unset($this->pontosPorCurva[$chave][$indice]);
        $this->pontosPorCurva[$chave] = array_values($this->pontosPorCurva[$chave]);
    }

    public function removerFoto(int $indice): void
    {
        unset($this->novasFotos[$indice], $this->legendasFotos[$indice]);
        $this->novasFotos = array_values($this->novasFotos);
        $this->legendasFotos = array_values($this->legendasFotos);
    }

    public function avancar(): void
    {
        if ($this->etapa === '1') {
            $this->validate([
                'periodoReferencia' => 'required|date',
            ], [], ['periodoReferencia' => 'período de referência']);
        }

        if ($this->etapa === '2' && $this->ordemCurvas === []) {
            $this->addError('ordemCurvas', 'Selecione ao menos uma curva (um pacote da EAP ou "Obra inteira").');
            return;
        }

        if ($this->etapa === '2') {
            $this->prepararRascunhoDoDiagnostico();
        }

        $this->resetErrorBag();
        $this->etapa = (string) (((int) $this->etapa) + 1);
    }

    /** Assinatura das opções que determinam o CONTEÚDO do Report — mudar qualquer uma invalida o rascunho já criado. */
    private function assinaturaAtual(): array
    {
        return [
            $this->periodoReferencia,
            $this->linhaBaseId,
            $this->avancoImportacaoId,
            $this->titulo,
            $this->ordemCurvas,
        ];
    }

    /**
     * Cria (ou reaproveita, ou descarta e recria) o rascunho do Report ao
     * sair do passo 2 — coração da Fase 1 do wizard de Diagnóstico
     * Colaborativo. Reaproveita EXATAMENTE a mesma chamada de
     * ReportGerador::gerarRascunho() que salvar() já fazia (só que aqui
     * SEM pontos de atenção, que continuam só em memória em
     * $pontosPorCurva até o passo final — ver salvar(), não alterado
     * nesta fase) + a mesma chamada degradável de
     * ImpactoRestricoesGerador::gerar() (necessária pro Passo 3 mostrar
     * Top Riscos/Decisões Prioritárias reais).
     */
    private function prepararRascunhoDoDiagnostico(): void
    {
        $assinaturaAtual = $this->assinaturaAtual();

        if ($this->report !== null && $this->reportAssinatura === $assinaturaAtual) {
            // Nada mudou desde a última vez que passamos pelo passo 2 —
            // reaproveita o mesmo Report, zero escrita nova no banco.
            return;
        }

        if ($this->report !== null) {
            // Nunca ->delete(): Report usa SoftDeletes, e cascadeOnDelete
            // das tabelas filhas (report_curvas/report_desvios/
            // report_curva_datapoints/report_pontos_atencao/
            // report_indicadores_semana/report_desvio_restricoes) só
            // dispara em DELETE real — mesmo achado já documentado no
            // projeto pra Tenant. Nada foi persistido em cima deste
            // rascunho ainda (pontos de atenção/fotos só entram no banco
            // em salvar()), então forceDelete() aqui nunca destrói dado
            // que o usuário já digitou.
            $this->report->forceDelete();
            $this->report = null;
        }

        $curvas = [];
        foreach ($this->ordemCurvas as $i => $chave) {
            $curvas[] = [
                'pacote_trabalho_id' => $chave === 'obra' ? null : $chave,
                'ordem' => $i,
                'pontos_atencao' => [],
            ];
        }

        $report = app(ReportGerador::class)->gerarRascunho($this->obra, auth()->user(), [
            'periodo_referencia' => $this->periodoReferencia,
            'linha_base_id' => $this->linhaBaseId,
            'avanco_importacao_id' => $this->avancoImportacaoId,
            'titulo' => $this->titulo ?: null,
            'curvas' => $curvas,
        ]);

        // Mesma degradação graciosa já usada em salvar() — falha aqui
        // nunca derruba o Report já criado.
        try {
            app(ImpactoRestricoesGerador::class)->gerar($report);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->report = $report;
        $this->reportAssinatura = $assinaturaAtual;
    }

    public function voltar(): void
    {
        $this->resetErrorBag();
        $this->etapa = (string) max(1, ((int) $this->etapa) - 1);
    }

    public function salvar(): void
    {
        $this->authorize('create', [Report::class, $this->obra->id]);

        // Achado do bug "Salvar rascunho não funciona": $this->validate()
        // pode falhar tanto em 'periodoReferencia' (UI visível só no Passo
        // 1) quanto em 'novasFotos.*' (UI visível só no Passo 4) — mas o
        // usuário está sempre no Passo 5 quando chama salvar(). Sem este
        // catch, a ValidationException interrompe o método e o Livewire
        // simplesmente re-renderiza o Passo 5 sem NENHUM indício visual do
        // erro (nenhum dos dois blocos @error existe nessa etapa) — clicar
        // em "Salvar rascunho" parecia "não fazer nada". Corrigido levando
        // o usuário de volta pro passo onde o erro já tem UI própria, antes
        // de deixar a exceção subir normalmente (Livewire preenche $errors
        // do jeito de sempre a partir dela).
        try {
            $this->validate([
                'periodoReferencia' => 'required|date',
                'novasFotos.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
            ]);
        } catch (ValidationException $e) {
            if ($e->validator->errors()->has('periodoReferencia')) {
                $this->etapa = '1';
            } elseif ($e->validator->errors()->has('novasFotos.*')) {
                $this->etapa = '4';
            }

            throw $e;
        }

        if ($this->ordemCurvas === []) {
            $this->addError('ordemCurvas', 'Selecione ao menos uma curva antes de salvar.');
            $this->etapa = '2';
            return;
        }

        // Mesma rede de segurança já usada nas 5 páginas centrais do Radar
        // (⚡restricoes/⚡lookahead/⚡linhas-base/⚡plano-semanal/⚡curvas) —
        // até agora este assistente não tinha: qualquer falha inesperada
        // aqui dentro (ex.: erro de storage ao salvar uma foto) desfazia
        // silenciosamente, sem toast e sem indicação nenhuma pro usuário.
        // Autorização/validação continuam subindo normalmente (tratadas
        // acima); só falhas de verdade caem aqui.
        $report = $this->transacaoSegura(function () {
            if ($this->report !== null) {
                // CAMINHO 1 (fluxo normal, Fase 1 do wizard de Diagnóstico
                // Colaborativo) — o rascunho já foi criado ao sair do passo 2
                // (ver prepararRascunhoDoDiagnostico()). Nunca chama
                // ReportGerador::gerarRascunho() de novo aqui — só falta
                // persistir os Pontos de Atenção, que até este ponto só
                // existiam em memória em $pontosPorCurva, sobre as curvas JÁ
                // existentes.
                $report = $this->report;
                $this->persistirPontosAtencaoNasCurvasExistentes($report);
            } else {
                // CAMINHO 2 (fallback, preservado tal como sempre existiu) —
                // quem chega em salvar() sem ter passado pelo passo 2 do
                // wizard guiado (ex.: chamada direta em teste) continua
                // criando Report+curvas+pontos de atenção numa única chamada.
                $curvas = [];
                foreach ($this->ordemCurvas as $i => $chave) {
                    $curvas[] = [
                        'pacote_trabalho_id' => $chave === 'obra' ? null : $chave,
                        'ordem' => $i,
                        'pontos_atencao' => array_values(array_filter(
                            $this->pontosPorCurva[$chave] ?? [],
                            fn ($p) => trim($p['texto'] ?? '') !== ''
                        )),
                    ];
                }

                $report = app(ReportGerador::class)->gerarRascunho($this->obra, auth()->user(), [
                    'periodo_referencia' => $this->periodoReferencia,
                    'linha_base_id' => $this->linhaBaseId,
                    'avanco_importacao_id' => $this->avancoImportacaoId,
                    'titulo' => $this->titulo ?: null,
                    'curvas' => $curvas,
                ]);

                // Fase 5, Etapa C2 — Impacto de Restrições: snapshot gerado
                // FORA de ReportGerador (Opção B aprovada, ReportGerador
                // permanece intocado). Falha aqui nunca derruba o Report já
                // criado — degradação graciosa, mesma filosofia de Health
                // Check/Score ausente em reports antigos.
                try {
                    app(ImpactoRestricoesGerador::class)->gerar($report);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            foreach ($this->novasFotos as $i => $arquivo) {
                $caminho = $arquivo->store("report-fotos/{$this->obra->id}/{$report->id}", 'public');
                $report->fotos()->create([
                    'caminho_arquivo' => $caminho,
                    'legenda' => $this->legendasFotos[$i] ?? null,
                    'ordem' => $i,
                    'enviado_por' => auth()->id(),
                ]);
            }

            return $report;
        }, 'Não foi possível salvar o rascunho do report. Tente novamente em instantes.');

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->dispatch('show-toast', message: 'Rascunho do report salvo com sucesso.');
        $this->redirect(route('radar.relatorios.show', $report));
    }

    /**
     * Caminho 1 de salvar() — persiste os Pontos de Atenção digitados
     * (ainda só em memória em $pontosPorCurva neste ponto, exatamente
     * como o Report criado no passo 2 sempre nasce com
     * 'pontos_atencao' => [] em cada curva) sobre as curvas do Report JÁ
     * existente. Mesmo padrão de ⚡relatorio-detalhe.blade.php::
     * salvarPontosAtencao() (curva->pontosAtencao()->create()) — nunca
     * ReportGerador::gerarRascunho() de novo, nunca recria curva/desvio.
     */
    private function persistirPontosAtencaoNasCurvasExistentes(Report $report): void
    {
        foreach ($this->ordemCurvas as $chave) {
            $pacoteId = $chave === 'obra' ? null : $chave;
            $curva = $report->curvas->firstWhere('pacote_trabalho_id', $pacoteId);

            if (! $curva) {
                continue;
            }

            $pontos = array_values(array_filter(
                $this->pontosPorCurva[$chave] ?? [],
                fn ($p) => trim($p['texto'] ?? '') !== ''
            ));

            foreach ($pontos as $i => $ponto) {
                $curva->pontosAtencao()->create([
                    'categoria' => $ponto['categoria'] ?: null,
                    'texto' => $ponto['texto'],
                    'ordem' => $i,
                ]);
            }
        }
    }
};

?>

<div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Indicador de progresso --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        @foreach(['1' => 'Período', '2' => 'Curvas', '3' => 'Pontos de Atenção', '4' => 'Fotos', '5' => 'Revisão'] as $n => $rotulo)
        <div class="text-center flex-fill">
            <span class="badge rounded-pill {{ $etapa === $n ? 'bg-primary' : ((int)$etapa > (int)$n ? 'bg-success' : 'bg-label-secondary') }}">
                {{ $n }}
            </span>
            <div class="small mt-1 {{ $etapa === $n ? 'fw-bold' : 'text-muted' }}">{{ $rotulo }}</div>
        </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body">

            {{-- ============================================================ --}}
            {{-- PASSO 1 — Período e linha de base --}}
            {{-- ============================================================ --}}
            @if($etapa === '1')
            <h6 class="mb-3">Período do report</h6>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Semana de referência <span class="text-danger">*</span></label>
                    <input type="date" class="form-control @error('periodoReferencia') is-invalid @enderror"
                           wire:model="periodoReferencia">
                    @error('periodoReferencia')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Título <span class="text-muted">(opcional)</span></label>
                    <input type="text" class="form-control" wire:model="titulo" placeholder="ex: Report Semana 26">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Linha de Base <span class="text-muted">(opcional)</span></label>
                    <select class="form-select" wire:model="linhaBaseId">
                        <option value="">— Usar última importação de linha de base —</option>
                        @foreach($this->linhasBase as $lb)
                        <option value="{{ $lb->id }}">{{ $lb->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Realizado/Tendência <span class="text-muted">(opcional)</span></label>
                    <select class="form-select" wire:model="avancoImportacaoId">
                        <option value="">— Usar última importação de avanço —</option>
                        @foreach($this->avancosDisponiveis as $imp)
                        <option value="{{ $imp->id }}">
                            {{ $imp->importado_em->format('d/m/Y H:i') }}
                            @if($imp->data_status) (status {{ $imp->data_status->format('d/m/Y') }}) @endif
                        </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="alert alert-light border d-flex align-items-center gap-2 mt-3 mb-0 py-2">
                <i class="bx bx-info-circle text-primary"></i>
                @if($this->avancosDisponiveis->isNotEmpty())
                <small>
                    @if($avancoImportacaoId)
                    Tendência e realizado usarão a importação de avanço selecionada acima.
                    @else
                    Tendência e realizado usarão a importação de avanço mais recente
                    (<strong>{{ $this->avancosDisponiveis->first()->importado_em->format('d/m/Y H:i') }}</strong>
                    @if($this->avancosDisponiveis->first()->data_status)
                    — status em {{ $this->avancosDisponiveis->first()->data_status->format('d/m/Y') }}
                    @endif
                    ).
                    @endif
                </small>
                @else
                <small class="text-danger">Esta obra ainda não tem nenhuma importação de avanço (realizado/tendência).</small>
                @endif
            </div>
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 2 — Seleção de curvas --}}
            {{-- ============================================================ --}}
            @if($etapa === '2')
            <h6 class="mb-3">Quais curvas este report vai mostrar?</h6>
            <p class="text-muted small mb-3">Escolha a obra inteira e/ou qualquer nível da EAP — uma curva S será gerada para cada escolha.</p>

            @error('ordemCurvas')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="obraInteira"
                       wire:click="toggleObraInteira" @checked($obraInteiraMarcada)>
                <label class="form-check-label fw-semibold" for="obraInteira">
                    <i class="bx bx-buildings me-1"></i>Obra inteira
                </label>
            </div>

            <div class="border rounded p-2" style="max-height: 400px; overflow-y: auto;">
                @forelse($this->arvorePacotes as $linha)
                @php $p = $linha['pacote']; @endphp
                <div class="form-check" style="padding-left: {{ 24 + $linha['nivel'] * 20 }}px">
                    <input class="form-check-input" type="checkbox" id="pacote_{{ $p->id }}"
                           wire:click="togglePacote('{{ $p->id }}')" @checked(isset($pacotesMarcados[$p->id]))
                           style="margin-left: -20px">
                    <label class="form-check-label" for="pacote_{{ $p->id }}">
                        <i class="bx bx-folder text-warning me-1"></i>{{ $p->codigo }} — {{ $p->nome }}
                    </label>
                </div>
                @empty
                <p class="text-muted small mb-0 p-2">Nenhum pacote de EAP cadastrado nesta obra.</p>
                @endforelse
            </div>
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 3 — Diagnóstico + Pontos de atenção --}}
            {{-- ============================================================ --}}
            @if($etapa === '3')

            {{-- ------------------------------------------------------------------ --}}
            {{-- Diagnóstico automático (Fase 1 do wizard de Diagnóstico Colaborativo)
                 — SOMENTE LEITURA nesta fase (confirmar/rejeitar/contextualizar ficam
                 pra fases futuras). Única fonte de dado é $this->diagnostico
                 (App\Support\Report\DiagnosticoReport::calcular()) — nenhuma regra de
                 cálculo é duplicada aqui, só leitura/apresentação do que o serviço já
                 devolve. Blocos/seções sem dado somem inteiros, nunca "Nenhum dado
                 encontrado". --}}
            {{-- ------------------------------------------------------------------ --}}
            @php
                $diag = $this->diagnostico;
                $diagCriticos = $diag['decisoesPrioritarias'] ?? [];
                $diagDesvios = $diag['principaisDesvios'] ?? [];
                $diagRiscos = $diag['topRiscos'] ?? [];
                // Regra de contagem do banner (decisão explícita desta fase):
                // aderenciaPlanejamento NUNCA entra nessa soma — fica só no
                // Contexto, mesmo quando desfavorável/crítica.
                $diagTotalRelevantes = count($diagCriticos) + count($diagDesvios) + count($diagRiscos);

                $diagConfiabilidade = $diag['confiabilidadeCronograma'] ?? ['estado' => 'indisponivel'];
                $diagHhPorCurva = collect($diag['hhExpostaPorAtraso'] ?? [])->filter(fn ($h) => ($h['estado'] ?? null) === 'ok');
                $diagProximosEventos = $diag['proximosEventosRelevantes'] ?? [];
                $diagAderencia = $diag['aderenciaPlanejamento'] ?? ['tem_dado' => false];

                $diagTemContexto = $diagConfiabilidade['estado'] !== 'indisponivel'
                    || $diagHhPorCurva->isNotEmpty()
                    || ! empty($diagProximosEventos)
                    || ($diagAderencia['tem_dado'] ?? false);
            @endphp

            <div class="mb-4">
                <div class="alert alert-primary d-flex align-items-start gap-2 mb-3">
                    <i class="bx bx-bulb fs-4"></i>
                    <div>
                        <strong>✨ O Radar analisou sua semana</strong>
                        <div class="small mt-1">
                            @if($diagTotalRelevantes > 0)
                            Encontramos {{ $diagTotalRelevantes }} {{ $diagTotalRelevantes === 1 ? 'ponto que merece' : 'pontos que merecem' }} atenção.
                            @else
                            ✅ Tudo sob controle nesta semana.
                            @endif
                        </div>
                    </div>
                </div>

                {{-- 🔴 CRÍTICO — só aparece com conteúdo --}}
                @if(count($diagCriticos) > 0)
                <div class="card border-danger mb-3">
                    <div class="card-header bg-label-danger py-2">
                        <strong>🔴 Crítico</strong> <span class="text-muted small">— exige atenção imediata</span>
                    </div>
                    <div class="card-body">
                        @foreach($diagCriticos as $item)
                        <div class="d-flex align-items-start gap-2 {{ !$loop->last ? 'mb-2 pb-2 border-bottom' : '' }}">
                            <i class="bx bx-error-circle text-danger mt-1"></i>
                            <div>
                                <div class="fw-semibold">{{ $item['descricao'] }}</div>
                                <div class="small text-muted">
                                    {{ $item['pacote_titulo'] ?? '' }}
                                    @if(!empty($item['prazo_limite']))
                                    · Prazo: {{ \Carbon\Carbon::parse($item['prazo_limite'])->format('d/m/Y') }}
                                    @endif
                                    @if($item['vencida'] ?? false)
                                    <span class="badge bg-label-danger ms-1">Vencida</span>
                                    @endif
                                    @if($item['bloqueante'] ?? false)
                                    <span class="badge bg-label-warning ms-1">Bloqueante</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- 🟠 RELEVANTE — só aparece com conteúdo (Principais Desvios e/ou Top Riscos) --}}
                @if(count($diagDesvios) > 0 || count($diagRiscos) > 0)
                <div class="card border-warning mb-3">
                    <div class="card-header bg-label-warning py-2">
                        <strong>🟠 Relevante</strong> <span class="text-muted small">— merece análise</span>
                    </div>
                    <div class="card-body">
                        @if(count($diagDesvios) > 0)
                        <div class="{{ count($diagRiscos) > 0 ? 'mb-3' : '' }}">
                            <div class="small text-muted mb-2">Principais Desvios</div>
                            <div class="row g-2">
                                @foreach($diagDesvios as $d)
                                <div class="col-md-4">
                                    <div class="border rounded p-2 h-100">
                                        <div class="fw-semibold small">{{ $d['titulo_exibicao'] }}</div>
                                        <div class="text-danger">{{ number_format($d['percentual_impacto'], 1, ',', '.') }} pts</div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        @if(count($diagRiscos) > 0)
                        <div>
                            <div class="small text-muted mb-2">Top Riscos</div>
                            @foreach($diagRiscos as $r)
                            <div class="d-flex align-items-start gap-2 {{ !$loop->last ? 'mb-2 pb-2 border-bottom' : '' }}">
                                <i class="bx bx-shield-x text-warning mt-1"></i>
                                <div>
                                    <div class="fw-semibold small">{{ $r['descricao'] }}</div>
                                    <div class="small text-muted">
                                        {{ $r['pacote_titulo'] ?? '' }}
                                        @if(!empty($r['prazo_limite']))
                                        · Prazo: {{ \Carbon\Carbon::parse($r['prazo_limite'])->format('d/m/Y') }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                {{-- 🔵 CONTEXTO — compacto, colapsado por padrão (Alpine core puro,
                     sem @alpinejs/collapse — mesmo achado já documentado no projeto
                     de que esse plugin não está instalado). --}}
                @if($diagTemContexto)
                <div class="card mb-3" x-data="{ diagContextoAberto: false }">
                    <div class="card-header py-2 d-flex align-items-center justify-content-between" style="cursor: pointer" @click="diagContextoAberto = !diagContextoAberto">
                        <div>
                            <strong>🔵 Contexto</strong> <span class="text-muted small">— informações que ajudam a entender a semana</span>
                        </div>
                        <i class="bx" :class="diagContextoAberto ? 'bx-chevron-up' : 'bx-chevron-down'"></i>
                    </div>
                    <div class="card-body" x-show="diagContextoAberto" x-transition x-cloak>
                        <div class="row g-3">
                            @if($diagConfiabilidade['estado'] !== 'indisponivel')
                            <div class="col-md-3">
                                <div class="small text-muted">Confiabilidade do Cronograma</div>
                                @if($diagConfiabilidade['estado'] === 'ok')
                                <div class="fw-semibold">{{ $diagConfiabilidade['score'] }}/100 ({{ $diagConfiabilidade['faixa_label'] }})</div>
                                @else
                                <div class="fw-semibold">{{ $diagConfiabilidade['total_ocorrencias'] }} ocorrência(s)</div>
                                @endif
                            </div>
                            @endif

                            @if($diagHhPorCurva->isNotEmpty())
                            <div class="col-md-3">
                                <div class="small text-muted">HH Exposta por Atraso</div>
                                @foreach($diagHhPorCurva as $h)
                                <div class="fw-semibold small">{{ number_format($h['hh_exposta_estimada'], 0, ',', '.') }}h ({{ $h['percentual_atividades_atrasadas'] }}%)</div>
                                @endforeach
                            </div>
                            @endif

                            @if(!empty($diagProximosEventos))
                            <div class="col-md-3">
                                <div class="small text-muted">Próximos Eventos</div>
                                @foreach($diagProximosEventos as $ev)
                                <div class="small">{{ $ev['titulo'] }} — {{ \Carbon\Carbon::parse($ev['data'])->format('d/m/Y') }}</div>
                                @endforeach
                            </div>
                            @endif

                            @if($diagAderencia['tem_dado'] ?? false)
                            <div class="col-md-3">
                                <div class="small text-muted">Aderência ao Planejamento</div>
                                <div class="fw-semibold">{{ number_format($diagAderencia['media'], 1, ',', '.') }}% {{ $diagAderencia['faixa_emoji'] }} {{ $diagAderencia['faixa_label'] }}</div>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <h6 class="mb-3">Pontos de atenção por curva</h6>
            <p class="text-muted small mb-3">Use as setas para definir a ordem de exibição no report. Os pontos de atenção são digitados manualmente — não vêm do cronograma.</p>

            @foreach($ordemCurvas as $i => $chave)
            <div class="card mb-3 border" wire:key="curva-preview-{{ $chave }}">
                <div class="card-header d-flex align-items-center gap-2 py-2">
                    <strong class="flex-grow-1">{{ $this->tituloCurva($chave) }}</strong>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" wire:click="moverCurva('{{ $chave }}', -1)" @disabled($i === 0)>
                        <i class="bx bx-up-arrow-alt"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" wire:click="moverCurva('{{ $chave }}', 1)" @disabled($i === count($ordemCurvas) - 1)>
                        <i class="bx bx-down-arrow-alt"></i>
                    </button>
                </div>
                <div class="card-body py-2">
                    <p class="text-muted small mb-2">Prévia da curva — use como referência pra escrever o ponto de atenção.</p>

                    <small class="text-muted d-block mb-1">Mensal</small>
                    <div wire:ignore class="mb-3" style="height: 220px;">
                        <canvas id="chart-preview-{{ $chave }}"></canvas>
                    </div>

                    <small class="text-muted d-block mb-1">Semanal (últimas 4 semanas)</small>
                    <div wire:ignore class="mb-3" style="height: 220px;">
                        <canvas id="chart-preview-semanal-{{ $chave }}"></canvas>
                    </div>

                    <small class="text-muted d-block mb-1 text-center">Aderência — última semana atualizada</small>
                    <div class="row justify-content-center mb-3">
                        <div class="col-md-4">
                            <div wire:ignore class="text-center" style="height: 180px;">
                                <canvas id="chart-preview-aderencia-{{ $chave }}"></canvas>
                            </div>
                        </div>
                    </div>

                    @foreach($pontosPorCurva[$chave] ?? [] as $j => $ponto)
                    <div class="row g-2 mb-2 align-items-center">
                        <div class="col-md-3">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="pontosPorCurva.{{ $chave }}.{{ $j }}.categoria"
                                   placeholder="Categoria (opcional)">
                        </div>
                        <div class="col-md-8">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="pontosPorCurva.{{ $chave }}.{{ $j }}.texto"
                                   placeholder="Descreva o ponto de atenção...">
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0"
                                    wire:click="removerPontoAtencao('{{ $chave }}', {{ $j }})">
                                <i class="bx bx-x"></i>
                            </button>
                        </div>
                    </div>
                    @endforeach
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="adicionarPontoAtencao('{{ $chave }}')">
                        <i class="bx bx-plus me-1"></i>Adicionar ponto de atenção
                    </button>
                </div>
            </div>
            @endforeach
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 4 — Fotos --}}
            {{-- ============================================================ --}}
            @if($etapa === '4')
            <h6 class="mb-3">Relatório fotográfico do período</h6>
            <p class="text-muted small mb-3">Fotos com legenda — a galeria fica no report inteiro, não em uma curva específica.</p>

            <div class="mb-3">
                <input type="file" class="form-control @error('novasFotos.*') is-invalid @enderror"
                       wire:model="novasFotos" multiple accept="image/*">
                @error('novasFotos.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div wire:loading wire:target="novasFotos" class="small text-muted mt-1">Enviando fotos...</div>
            </div>

            <div class="row g-3">
                @foreach($novasFotos as $i => $foto)
                <div class="col-md-3">
                    <div class="card h-100">
                        {{-- Achado do bug "salvar rascunho não funciona": arquivos que o
                             navegador deixa passar por accept="image/*" mas que o Livewire
                             não sabe pré-visualizar (ex.: fotos HEIC, padrão do iPhone —
                             ausente de config('livewire.temporary_file_upload.preview_mimes'))
                             faziam temporaryUrl() lançar FileNotPreviewableException e
                             quebrar o render do Passo 4 inteiro, travando o usuário antes
                             mesmo de chegar no Passo 5/"Salvar rascunho". A validação de
                             mimes em salvar() já rejeita esses arquivos — este fallback só
                             evita o crash na prévia, nunca aceita o arquivo por baixo dos panos. --}}
                        @if($foto->isPreviewable())
                        <img src="{{ $foto->temporaryUrl() }}" class="card-img-top" style="height:140px; object-fit:cover">
                        @else
                        <div class="card-img-top d-flex align-items-center justify-content-center bg-light text-muted" style="height:140px">
                            <div class="text-center small px-2">
                                <i class="bx bx-file fs-3 d-block mb-1"></i>
                                {{ $foto->getClientOriginalName() }}
                            </div>
                        </div>
                        @endif
                        <div class="card-body p-2">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="legendasFotos.{{ $i }}" placeholder="Legenda...">
                        </div>
                        <div class="card-footer p-1 text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0" wire:click="removerFoto({{ $i }})">
                                <i class="bx bx-trash"></i> Remover
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            {{-- ============================================================ --}}
            {{-- PASSO 5 — Revisão --}}
            {{-- ============================================================ --}}
            @if($etapa === '5')
            <h6 class="mb-3">Revisão</h6>

            <dl class="row mb-3">
                <dt class="col-sm-3">Período</dt>
                <dd class="col-sm-9">{{ \Carbon\Carbon::parse($periodoReferencia)->format('d/m/Y') }}</dd>
                <dt class="col-sm-3">Linha de Base</dt>
                <dd class="col-sm-9">{{ $linhaBaseId ? $this->linhasBase->firstWhere('id', $linhaBaseId)?->nome : 'Última importação de linha de base' }}</dd>
                <dt class="col-sm-3">Realizado/Tendência</dt>
                <dd class="col-sm-9">
                    @if($avancoImportacaoId)
                        {{ $this->avancosDisponiveis->firstWhere('id', $avancoImportacaoId)?->importado_em?->format('d/m/Y H:i') }}
                    @else
                        Última importação de avanço
                    @endif
                </dd>
                <dt class="col-sm-3">Curvas</dt>
                <dd class="col-sm-9">
                    <ul class="mb-0 ps-3">
                        @foreach($ordemCurvas as $chave)
                        <li>
                            {{ $this->tituloCurva($chave) }}
                            <span class="text-muted small">({{ count($pontosPorCurva[$chave] ?? []) }} ponto(s) de atenção)</span>
                        </li>
                        @endforeach
                    </ul>
                </dd>
                <dt class="col-sm-3">Fotos</dt>
                <dd class="col-sm-9">{{ count($novasFotos) }} foto(s) anexada(s)</dd>
            </dl>

            <div class="alert alert-warning py-2">
                <i class="bx bx-info-circle me-1"></i>
                O report será salvo como <strong>rascunho</strong> — só o setor de planejamento verá até você clicar em "Emitir" na página de detalhe.
            </div>
            @endif

        </div>

        {{-- ------------------------------------------------------------------ --}}
        {{-- Navegação --}}
        {{-- ------------------------------------------------------------------ --}}
        <div class="card-footer d-flex justify-content-between">
            <button type="button" class="btn btn-outline-secondary" wire:click="voltar" @disabled($etapa === '1')>
                <i class="bx bx-chevron-left me-1"></i>Voltar
            </button>

            @if($etapa !== '5')
            <button type="button" class="btn btn-primary" wire:click="avancar">
                Próximo<i class="bx bx-chevron-right ms-1"></i>
            </button>
            @else
            <button type="button" class="btn btn-success" wire:click="salvar" wire:loading.attr="disabled">
                <i class="bx bx-save me-1"></i>Salvar rascunho
            </button>
            @endif
        </div>
    </div>

</div>

@include('pages.radar._partials.relatorio-grafico-config')

@script
<script>
    $wire.on('show-toast', ({ message }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            toastr.success(message);
        }
    });

    // IMPORTANTE: este bloco de script roda UMA VEZ só, na primeira
    // renderização do componente (ainda no passo 1, com $ordemCurvas
    // vazio) — um JSON estático embutido aqui dentro ficaria CONGELADO
    // nesse estado vazio inicial pra sempre, mesmo depois do usuário
    // escolher curvas no passo 2 (bug real: a prévia nunca aparecia).
    // Por isso os dados são buscados AO VIVO via
    // $wire.dadosGraficosPreviewParaJs() (chamada normal de ação Livewire)
    // toda vez que o passo 3 é alcançado, nunca embutidos como JSON
    // estático no script.
    const [limiarAtencao, limiarOtimo] = @json($this->limiaresAderencia());

    // Guarda defensiva compartilhada: Chart.getChart evita erro de "canvas
    // already in use" em qualquer edge case de re-render (o wrapper de
    // cada canvas tem wire:ignore, então o Livewire não deveria recriá-lo
    // sozinho, mas isso protege contra qualquer cenário residual).
    const desenharDuploEixo = (idCanvas, dados, cfg) => {
        const canvas = document.getElementById(idCanvas);
        if (!canvas || !dados.labels.length) return;

        window.Chart.getChart(canvas)?.destroy();
        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: dados.labels,
                datasets: cfg.construirDatasets(dados.barras, dados.linhas),
            },
            options: cfg.opcoesDuploEixo(),
        });
    };

    const renderizarPreview = (previewData) => {
        const cfg = window.RelatorioGraficoConfig;

        previewData.forEach((curva) => {
            desenharDuploEixo(`chart-preview-${curva.chave}`, curva.mensal, cfg);
            desenharDuploEixo(`chart-preview-semanal-${curva.chave}`, curva.semanal, cfg);

            const canvasAderencia = document.getElementById(`chart-preview-aderencia-${curva.chave}`);
            if (canvasAderencia) {
                window.Chart.getChart(canvasAderencia)?.destroy();
                const config = cfg.gaugeConfig(limiarAtencao, limiarOtimo, 100);
                config.data.datasets[0].needleValue = curva.aderencia_atual;

                new window.Chart(canvasAderencia, {
                    ...config,
                    plugins: [cfg.pluginAgulha, cfg.pluginTextoCentral],
                });
            }
        });
    };

    const buscarEDesenharPreview = () => {
        // Nota: usar requestAnimationFrame (não $wire.$nextTick) — $nextTick
        // não existe como método JS do $wire nesta versão do Livewire
        // instalada, e sendo tratado como uma chamada de ação remota, o que
        // gera MethodNotFoundException no servidor. requestAnimationFrame
        // adia a execução pro próximo frame, depois que o Livewire já
        // atualizou o DOM (canvas já existe quando o Chart.js for criado).
        requestAnimationFrame(() => {
            $wire.dadosGraficosPreviewParaJs().then(renderizarPreview).catch((erro) => {
                // Bug real já visto aqui: window.Chart indefinido (Chart.js
                // não incluído nesta página) fazia a promise falhar em
                // silêncio — nenhum erro visível, nenhuma curva desenhada.
                // Nunca deixar essa falha sumir sem rastro de novo.
                console.error('Falha ao montar a prévia da curva do report:', erro);
            });
        });
    };

    $wire.$watch('etapa', (valor) => {
        if (valor === '3') {
            buscarEDesenharPreview();
        }
    });

    if ($wire.etapa === '3') {
        buscarEDesenharPreview();
    }
</script>
@endscript
