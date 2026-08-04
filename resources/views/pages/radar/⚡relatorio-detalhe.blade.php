<?php

use App\Enums\GranularidadePeriodo;
use App\Exports\ReportExport;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\CausaNaoCumprimento;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportDesvio;
use App\Models\ReportFoto;
use App\Models\ReportPontoAtencao;
use App\Services\ReportGerador;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Detalhe de um Report — todos os números aqui vêm das tabelas
 * ReportCurva/ReportCurvaDatapoint/ReportDesvio já gravadas na geração
 * (ver App\Services\ReportGerador). Esta página NUNCA recalcula nada ao
 * vivo — é a "fotografia" que o usuário vê, mesmo que o cronograma
 * tenha sido reimportado depois.
 *
 * EXCEÇÃO: enquanto o report ainda está em RASCUNHO, pontos de atenção e
 * fotos continuam editáveis aqui mesmo (não só no assistente de criação)
 * — são texto/anexo livre, não números calculados da curva, então editá-
 * los não fere a filosofia de fotografia. Gate via Policy `update`
 * (`App\Policies\ReportPolicy`), que já só libera enquanto rascunho.
 */
new class extends Component {
    use WithFileUploads;

    public Report $report;

    public string $novoComentario = '';

    /** Edição do título enquanto rascunho — sincronizado de $report->titulo no mount(). */
    public string $tituloEdit = '';

    /** [curvaId => [['id' => ?string, 'categoria' => ?string, 'texto' => string], ...]] */
    public array $pontosAtencaoEdit = [];

    /** [fotoId => legenda] — edição de legenda das fotos já enviadas. */
    public array $legendasFotosEdit = [];

    public array $novasFotosUpload = [];
    public array $legendasNovasFotosUpload = [];

    /** Link público gerado pra este report (ver gerarLinkCliente()) — null até o botão ser clicado. */
    public ?string $linkClienteGerado = null;

    /**
     * Limiares do velocímetro de aderência (zonas vermelha/amarela/verde) —
     * repassados ao JS via limiaresAderencia() na hora de montar o gráfico
     * (ver bloco @script no fim desta view), pra não duplicar os números.
     */
    private const ADERENCIA_LIMIAR_OTIMO = 95.0;
    private const ADERENCIA_LIMIAR_ATENCAO = 80.0;

    public function mount(Report $report): void
    {
        $this->authorize('view', $report);

        $this->report = $report->load($this->relacoesReportCompletas());

        $this->tituloEdit = (string) $this->report->titulo;
        $this->sincronizarPontosAtencaoEdit();
        $this->sincronizarLegendasFotosEdit();
    }

    /**
     * Hook de ciclo de vida do Livewire (roda em toda requisição
     * SUBSEQUENTE à primeira, antes de render()) — guarda contra a
     * rehidratação do model entre requisições dropando relações
     * aninhadas sob 'curvas'. Causa raiz: quando o Report tem 2+ curvas
     * com sub-relações assimétricas (ex.: uma curva sem nenhum desvio,
     * ou sem nenhum datapoint), Illuminate\Database\Eloquent\Collection::
     * getQueueableRelations() faz array_intersect(...) entre as relações
     * "queueáveis" de cada item da coleção — qualquer caminho presente só
     * em ALGUMAS curvas (ex.: 'desvios.restricaoImpacto', 'datapoints',
     * ou até a própria 'desvios') é descartado da lista que o Livewire
     * reaplica ->with() ao recarregar o model, mesmo tendo sido
     * eager-carregado em mount(). Reconhecido no navegador ao salvar o
     * Título do Report (wire:click="salvarTitulo"): a ação dispara um
     * re-render completo do componente, que acessa topRiscos()/
     * dadosGraficos() mais abaixo na página. Em vez de caçar relação por
     * relação em cada computed (abordagem que não convergiu — 3 relações
     * diferentes quebraram em sequência), refaz aqui a MESMA lista de
     * mount() via loadMissing() — idempotente, sem query extra quando a
     * rehidratação preservou tudo.
     */
    public function hydrate(): void
    {
        $this->report->loadMissing($this->relacoesReportCompletas());
    }

    /**
     * Única fonte de verdade da lista de relações eager-carregadas do
     * Report — reaproveitada por mount() (->load(), primeira carga),
     * hydrate() (->loadMissing(), guarda contra rehidratação do
     * Livewire) e por qualquer ação que precise recarregar 'curvas.*'
     * depois de uma mutação (ex.: pontos de atenção). Existir como um
     * único método evita a mesma classe de bug documentada em
     * hydrate() reaparecer por uma lista duplicada e desatualizada em
     * um quarto lugar — um `->load('curvas.pontosAtencao')` isolado
     * (só essa sub-relação) SUBSTITUI inteiramente
     * `$this->report->relations['curvas']` por instâncias novas de
     * ReportCurva, descartando 'desvios'/'desvios.restricaoImpacto'/
     * 'desvios.pacoteTrabalho'/'datapoints'/'pacoteTrabalho' — mesmo
     * mecanismo do array_intersect(), só que disparado por dentro da
     * própria ação, não pela rehidratação entre requisições.
     */
    private function relacoesReportCompletas(): array
    {
        return [
            'obra',
            'cronogramaImportacao.healthCheck',
            'curvas.datapoints',
            'curvas.desvios.pacoteTrabalho',
            'curvas.desvios.restricaoImpacto',
            'curvas.pacoteTrabalho',
            'curvas.pontosAtencao',
            'fotos.enviadoPor:id,first_name,last_name',
            'comentarios.autor:id,first_name,last_name',
            'criador:id,first_name,last_name',
            'emissor:id,first_name,last_name',
            'indicadoresSemana',
        ];
    }

    /**
     * Confiabilidade do Cronograma (Fase 5, Etapa A) — lê o Health Check/
     * Score já persistidos da importação que originou este Report, via a
     * relação indireta Report->cronogramaImportacao->healthCheck (todas
     * já existentes; nenhuma foi criada nesta etapa). Nunca recalcula
     * nada — App\Support\HealthCheck\HealthCheckEngine e
     * App\Support\HealthCheck\Score\ScoreCalculator continuam sendo as
     * únicas fontes de cálculo.
     *
     * Deliberadamente independente do avanço físico do report (curvas/
     * desvio) — mede a qualidade estrutural da base de planejamento, não
     * se a obra está adiantada ou atrasada (por isso "Confiabilidade do
     * Cronograma", nunca "Saúde da Obra"/"Saúde do Projeto").
     *
     * CronogramaImportacaoHealthCheck é imutável após criada (único ponto
     * de escrita do sistema é App\Jobs\ImportarCronogramaJob::handle(),
     * um único ->create(), protegido por unique('cronograma_importacao_id')
     * — nunca ->update()) — por isso ler via essa relação indireta é
     * seguro mesmo para um Report já emitido, sem exigir snapshot próprio
     * nesta etapa.
     *
     * 3 estados possíveis, nenhum inventando dado ausente:
     * 'indisponivel' — importação sem Health Check (report anterior à
     *   Fase 1 do Health Check, ou importação sem análise registrada);
     * 'sem_score' — Health Check existe mas Score não (importação
     *   anterior à Fase 3 do Score) — mostra só os achados, nunca um
     *   score inventado;
     * 'ok' — os dois existem — mostra score/faixa/achados normalmente,
     *   sem alerta artificial quando não há crítico/alto.
     */
    #[Computed]
    public function confiabilidadeCronograma(): array
    {
        $healthCheck = $this->report->cronogramaImportacao->healthCheck;

        if (! $healthCheck) {
            return ['estado' => 'indisponivel'];
        }

        $scoreResultado = $healthCheck->scoreResultado();

        if (! $scoreResultado) {
            return [
                'estado' => 'sem_score',
                'total_ocorrencias' => $healthCheck->total_ocorrencias,
                'total_criticos' => $healthCheck->total_criticos,
                'total_altos' => $healthCheck->total_altos,
            ];
        }

        return [
            'estado' => 'ok',
            'score' => $scoreResultado->score,
            'faixa_label' => $scoreResultado->faixa->label(),
            'faixa_cor' => $scoreResultado->faixa->cor(),
            'total_ocorrencias' => $healthCheck->total_ocorrencias,
            'total_criticos' => $healthCheck->total_criticos,
            'total_altos' => $healthCheck->total_altos,
        ];
    }

    /**
     * Principais Desvios da Obra (Fase 5, Etapa B do roadmap original —
     * "Onde está o problema?") — agrega, em tempo de leitura, as linhas
     * FILHAS (eh_nivel_pai=false) de App\Models\ReportDesvio de TODAS as
     * curvas do Report, e seleciona as 3 com pior percentual_impacto.
     * Mesma arquitetura de topRiscos() (Etapa D do roadmap): leitura pura
     * sobre dado já congelado por App\Services\ReportGerador na geração
     * do Report — NENHUMA persistência nova, NENHUMA consulta a
     * Restricao ou qualquer fonte externa (só ReportDesvio).
     *
     * Convenção de sinal CONFIRMADA em App\Services\ReportGerador::
     * calcularLinhaDesvio() antes de implementar: percentual_desvio =
     * percentual_real - percentual_previsto; percentual_impacto =
     * percentual_desvio * peso (peso >= 0, então percentual_impacto
     * SEMPRE tem o mesmo sinal de percentual_desvio) — valor NEGATIVO =
     * atraso (realizado abaixo do previsto), valor POSITIVO/ZERO =
     * dentro do plano ou adiantado. MESMA convenção já usada em 2
     * lugares desta página antes desta etapa: resumoExecutivo()
     * ['maior_desvio'] (sortBy ascendente por percentual_impacto,
     * primeiro item = pior) e a tabela de Análise de Desvios de cada
     * curva mais abaixo nesta view ("filhas ordenadas pelo pior
     * percentual_impacto, mais negativo primeiro" + "as 3 piores COM
     * desvio negativo real ganham destaque visual, nunca destaca linha
     * com desvio positivo") — este computed reaplica EXATAMENTE essa
     * mesma regra já estabelecida, só agregando entre curvas em vez de
     * dentro de uma curva só.
     *
     * Filtro: só percentual_impacto < 0 (estritamente negativo) — nunca
     * neutro/positivo, mesmo critério já usado na tabela por curva.
     * Linhas "nível pai" (eh_nivel_pai=true, o total da própria curva)
     * NUNCA competem no ranking — mesma exclusão já aplicada na tabela
     * por curva, pois são agregados de seus próprios filhos, não um
     * "lugar" específico da obra.
     *
     * Ordenação: percentual_impacto ascendente (mais negativo primeiro)
     * -> empate: percentual_desvio ascendente (mesmo sinal de
     * percentual_impacto sempre, já que peso >= 0 — "maior módulo" e
     * "mais negativo" coincidem aqui) -> empate total: id ascendente
     * (determinístico).
     *
     * NUNCA afirma causalidade — cada item é "existe um desvio associado
     * a este pacote", nunca "este pacote causou o problema da obra".
     *
     * @return array<int, array> lista de até 3 desvios (id,
     *               titulo_exibicao, percentual_previsto,
     *               percentual_real, percentual_desvio, percentual_impacto)
     */
    #[Computed]
    public function principaisDesvios(): array
    {
        return $this->report->curvas
            ->flatMap(fn (ReportCurva $c) => $c->desvios)
            ->filter(fn (ReportDesvio $d) => ! $d->eh_nivel_pai && (float) $d->percentual_impacto < 0)
            ->sortBy(fn (ReportDesvio $d) => [
                (float) $d->percentual_impacto,
                (float) $d->percentual_desvio,
                $d->id,
            ])
            ->values()
            ->take(3)
            ->map(fn (ReportDesvio $d) => [
                'id' => $d->id,
                'titulo_exibicao' => $d->titulo_exibicao,
                'percentual_previsto' => (float) $d->percentual_previsto,
                'percentual_real' => (float) $d->percentual_real,
                'percentual_desvio' => (float) $d->percentual_desvio,
                'percentual_impacto' => (float) $d->percentual_impacto,
            ])
            ->all();
    }

    /**
     * Causas do Desvio (Fase 5, Etapa B) — associa, pra cada linha já
     * existente do quadro de desvios (App\Models\ReportDesvio), as
     * atividades do MESMO escopo que têm App\Models\CausaNaoCumprimento
     * registrada. NUNCA recalcula desvio, NUNCA afirma causalidade — só
     * associação factual ("causa declarada", "evidência encontrada"),
     * nunca "causado por"/"devido a". Ver diagnóstico arquitetural
     * aprovado da Etapa B.
     *
     * Escopo de pacote resolvido EXATAMENTE como aprovado: linha "nível
     * pai" via ReportCurva->pacoteTrabalho() (null = obra inteira, todos
     * os pacotes), linhas filhas via ReportDesvio->pacoteTrabalho() —
     * sempre pacote + PacoteTrabalho::descendantIds() (mesmo método já
     * usado por App\Services\ReportGerador::gerarQuadroDesvios(), nunca
     * duplicado aqui).
     *
     * Filtro temporal (Regra 7 aprovada): só considera CausaNaoCumprimento
     * registrada até a data de referência do Report (data_status, com
     * periodo_referencia como fallback) — nunca um registro posterior à
     * fotografia. Ainda SEM snapshot (aprovado explicitamente pra uma
     * etapa futura) — leitura ao vivo, mas já corretamente escopada no
     * tempo.
     *
     * Múltiplas causas da MESMA atividade nunca inflam a contagem — cada
     * atividade conta 1x em "com causa"/"sem causa" (distinct por
     * atividade_id), mas TODOS os registros históricos de causa daquela
     * atividade são preservados na lista de exibição.
     *
     * Performance: 2 queries no total (Atividade + CausaNaoCumprimento),
     * nunca uma por linha de desvio — coletadas uma vez e filtradas em
     * memória por escopo de cada linha (mesmo princípio já usado em
     * outras agregações do projeto, ex. Δ Score no histórico de
     * importações). `PacoteTrabalho::descendantIds()` continua sendo
     * chamado por linha (mesmo custo que o próprio ReportGerador já paga
     * na geração — não duplicado nem piorado aqui).
     *
     * @return array<string, array> chave = ReportDesvio->id
     */
    #[Computed]
    public function causasDoDesvio(): array
    {
        $dataReferencia = $this->report->data_status ?? $this->report->periodo_referencia;

        $pacoteIdsPorDesvio = [];
        $todosPacoteIds = [];

        foreach ($this->report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $pacote = $desvio->eh_nivel_pai ? $curva->pacoteTrabalho : $desvio->pacoteTrabalho;

                $pacoteIds = $pacote
                    ? [$pacote->id, ...$pacote->descendantIds()]
                    : PacoteTrabalho::where('obra_id', $this->report->obra_id)->pluck('id')->all();

                $pacoteIdsPorDesvio[$desvio->id] = $pacoteIds;
                $todosPacoteIds = [...$todosPacoteIds, ...$pacoteIds];
            }
        }

        $todosPacoteIds = array_values(array_unique($todosPacoteIds));

        $atividades = Atividade::where('obra_id', $this->report->obra_id)
            ->whereIn('pacote_trabalho_id', $todosPacoteIds)
            ->get(['id', 'pacote_trabalho_id', 'nome', 'codigo_cronograma']);

        $causasPorAtividade = CausaNaoCumprimento::whereIn('atividade_id', $atividades->pluck('id'))
            ->whereDate('created_at', '<=', $dataReferencia)
            ->orderByDesc('created_at')
            ->get(['id', 'atividade_id', 'descricao', 'created_at'])
            ->groupBy('atividade_id');

        $linhas = [];

        foreach ($pacoteIdsPorDesvio as $desvioId => $pacoteIds) {
            $atividadesDoEscopo = $atividades->whereIn('pacote_trabalho_id', $pacoteIds);
            $comCausa = $atividadesDoEscopo->filter(fn (Atividade $a) => $causasPorAtividade->has($a->id));

            $linhas[$desvioId] = [
                'total_atividades' => $atividadesDoEscopo->count(),
                'atividades_com_causa' => $comCausa->count(),
                'atividades_sem_causa' => $atividadesDoEscopo->count() - $comCausa->count(),
                'causas' => $comCausa
                    ->flatMap(fn (Atividade $a) => $causasPorAtividade->get($a->id)->map(fn (CausaNaoCumprimento $c) => [
                        'atividade_nome' => $a->nome,
                        'atividade_codigo' => $a->codigo_cronograma,
                        'descricao' => $c->descricao,
                        'registrada_em' => $c->created_at,
                    ]))
                    ->sortByDesc('registrada_em')
                    ->values()
                    ->all(),
            ];
        }

        return $linhas;
    }

    /**
     * HH Expostas por Atraso (Fase 5, Etapa C1) — estimativa de quanto do
     * HH previsto do escopo de cada CURVA está associado a atividades já
     * classificadas como atrasadas NO MOMENTO DA GERAÇÃO do Report.
     *
     * Reaproveita EXCLUSIVAMENTE campos já congelados por
     * App\Services\ReportGerador::contarAtividades() em ReportCurva
     * (total_atividades/atividades_atrasadas/total_hh_previsto) — nenhuma
     * consulta nova, nenhuma leitura de Atividade/Restricao ao vivo,
     * nenhum recálculo de ReportGerador/ReportDesvio/Score/Health Check.
     *
     * É uma ESTIMATIVA PROPORCIONAL (assume HH distribuído uniformemente
     * entre as atividades do escopo), não a soma exata do HH de cada
     * atividade atrasada individualmente — essa lista individual nunca
     * foi persistida (só a contagem agregada), e reconstruí-la exigiria
     * reclassificar "atrasada" a partir de Atividade.data_termino ao vivo,
     * exatamente o que esta etapa não deve fazer. A interface é
     * transparente sobre essa limitação.
     *
     * Granularidade: por CURVA — não por linha do quadro de desvios como
     * nas Etapas A/B, porque ReportDesvio nunca gravou essa contagem por
     * pacote (só ReportCurva tem o agregado, no nível da curva inteira).
     *
     * Zero query nova: opera inteiramente sobre atributos de ReportCurva
     * já carregados por mount().
     *
     * @return array<string, array> chave = ReportCurva->id
     */
    #[Computed]
    public function hhExpostaPorAtraso(): array
    {
        $resultado = [];

        foreach ($this->report->curvas as $curva) {
            if ($curva->total_atividades <= 0) {
                $resultado[$curva->id] = ['estado' => 'sem_dado'];

                continue;
            }

            $percentualAtrasadas = round($curva->atividades_atrasadas / $curva->total_atividades * 100, 1);
            $hhExpostaEstimada = round((float) $curva->total_hh_previsto * $curva->atividades_atrasadas / $curva->total_atividades, 2);

            $resultado[$curva->id] = [
                'estado' => 'ok',
                'total_atividades' => $curva->total_atividades,
                'atividades_atrasadas' => $curva->atividades_atrasadas,
                'percentual_atividades_atrasadas' => $percentualAtrasadas,
                'hh_exposta_estimada' => $hhExpostaEstimada,
                'total_hh_previsto' => (float) $curva->total_hh_previsto,
            ];
        }

        return $resultado;
    }

    /**
     * Impacto de Restrições (Fase 5, Etapa C2) — lê o snapshot já
     * persistido por App\Services\ImpactoRestricoesGerador
     * (App\Models\ReportDesvioRestricao, 1:1 com ReportDesvio) —
     * NUNCA relê Restricao ao vivo aqui, porque ela é mutável (reabertura
     * apaga resolvida_em, status muda livremente) e isso quebraria
     * "Report é fotografia". Reports gerados antes desta etapa
     * simplesmente não têm o snapshot — vira `null` pra aquela linha,
     * nunca inventa dado (mesmo padrão de confiabilidadeCronograma()/
     * scoreResultado() ausente em reports antigos).
     *
     * NUNCA afirma causalidade — só quantidades e detalhes de restrições
     * ABERTAS associadas ao escopo da linha; a existência de uma
     * restrição não prova que ela causou o desvio.
     *
     * @return array<string, array|null> chave = ReportDesvio->id
     */
    #[Computed]
    public function impactoRestricoes(): array
    {
        $linhas = [];

        foreach ($this->report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $snapshot = $desvio->restricaoImpacto;

                $linhas[$desvio->id] = $snapshot ? [
                    'total_abertas' => $snapshot->total_abertas,
                    'total_vencidas' => $snapshot->total_vencidas,
                    'total_criticas' => $snapshot->total_criticas,
                    'detalhes' => $snapshot->detalhes ?? [],
                ] : null;
            }
        }

        return $linhas;
    }

    /**
     * Top Riscos da Obra (Fase 5, Etapa D) — agrega, em tempo de leitura,
     * as restrições já congeladas pela Etapa C2 (impactoRestricoes(),
     * que por sua vez lê App\Models\ReportDesvioRestricao) em TODAS as
     * curvas/linhas do Report, e seleciona as 3 mais urgentes pra exibir
     * dentro de "Diagnóstico da Obra". NENHUMA persistência nova — cada
     * linha de origem já é imutável desde que foi criada (nunca é
     * atualizada depois), então agregar/ordenar em tempo de leitura é
     * seguro e sempre reproduz o mesmo resultado (mesmo princípio já
     * usado por resumoExecutivo() pra agregar ReportDesvio ao vivo).
     *
     * Fonte EXCLUSIVA: o snapshot já persistido via impactoRestricoes()
     * — NUNCA relê Restricao ao vivo aqui. Reports gerados antes da
     * Etapa C2 não têm nenhum snapshot — retorna array vazio, nunca
     * inventa dado.
     *
     * Ordenação: vencida primeiro, depois maior P×I (classificacao_risco
     * === 'alto', mesmo limiar ≥50 já usado em ImpactoRestricoesGerador),
     * depois prazo mais próximo — mesmo critério já usado DENTRO de cada
     * linha na Etapa C2, agora aplicado ENTRE todas as linhas do Report.
     *
     * NUNCA afirma causalidade — cada item é "uma restrição associada ao
     * escopo deste pacote", nunca "a causa do risco/desvio". Cada risco
     * mantém a identificação do pacote/frente de origem (titulo_exibicao
     * do ReportDesvio de onde veio), mesmo o bloco sendo exibido no
     * nível Report/obra.
     *
     * Fora de escopo nesta etapa (decisão explícita): tendência
     * piorando/melhorando (não existe mecanismo pra isso fora do Score
     * do Health Check, eixo diferente), "ação pendente" como campo
     * próprio, e riscos sem Restrição associada.
     *
     * @return array<int, array> lista de até 3 riscos (descricao, status,
     *               categoria_nome, responsavel_nome, prazo_limite,
     *               bloqueante, probabilidade, impacto, vencida,
     *               classificacao_risco, pacote_titulo)
     */
    #[Computed]
    public function topRiscos(): array
    {
        $riscos = [];

        foreach ($this->report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $snapshot = $this->impactoRestricoes[$desvio->id] ?? null;

                if (! $snapshot) {
                    continue;
                }

                foreach ($snapshot['detalhes'] as $item) {
                    $riscos[] = [
                        ...$item,
                        'pacote_titulo' => $desvio->titulo_exibicao,
                    ];
                }
            }
        }

        return collect($riscos)
            ->sortBy(fn (array $r) => [
                $r['vencida'] ? 0 : 1,
                $r['classificacao_risco'] === 'alto' ? 0 : 1,
                $r['prazo_limite'] ?? '9999-12-31',
            ])
            ->values()
            ->take(3)
            ->all();
    }

    /**
     * Próximos Eventos Relevantes (Fase 5, Etapa F do roadmap original —
     * "O que vem pela frente?") — Lookahead Executivo, NUNCA um segundo
     * Plano Semanal. Responde só "o que começa, termina ou atinge um
     * marco na janela seguinte a este Report".
     *
     * Fonte EXCLUSIVA: App\Models\AtividadeSnapshot (datas históricas,
     * ancoradas em Report->cronograma_importacao_id — a MESMA importação
     * já travada pelo próprio Report, nunca "a mais recente") +
     * App\Models\Atividade só pra descritivos que o snapshot ainda não
     * congela (nome, codigo_cronograma, is_marco, caminho_critico).
     * NUNCA ReportDesvio, ProgramacaoSemanal/ProgramacaoSemanalItem ou
     * Restricao — decisão explícita desta etapa, preserva a fotografia
     * histórica sem introduzir nenhuma consulta viva de Restricao.
     *
     * Limitação aceita explicitamente (mesma classe já tolerada em
     * causasDoDesvio()): nome/codigo_cronograma/is_marco/caminho_critico
     * NÃO são congelados pelo AtividadeSnapshot — só as datas são. Uma
     * atividade renomeada ou reclassificada depois da emissão pode, em
     * tese, mudar esses atributos descritivos num Report antigo; as
     * DATAS em si nunca mudam, pois vêm sempre do snapshot da importação
     * travada.
     *
     * Janela: EXATAMENTE a mesma convenção já usada em
     * decisoesPrioritarias()/ReportGerador::gerarIndicadoresSemana() —
     * periodo_referencia + 1 semana até o fim dessa semana. Nenhum
     * horizonte novo (14/30/60/90 dias) inventado.
     *
     * "Início previsto"/"Término previsto" usam inicio_planejado/
     * data_termino do snapshot (a tendência congelada NA IMPORTAÇÃO DO
     * PRÓPRIO REPORT, decisão explícita de ancorar só em
     * cronograma_importacao_id) — nunca baseline_inicio/baseline_termino,
     * nunca campo ao vivo da Atividade.
     *
     * Marco: atividade com is_marco=true gera SÓ o evento "marco" (nunca
     * também início/término separados, mesma data de qualquer forma,
     * já que um marco tem duração zero) — usa data_termino do snapshot
     * como data do marco, com inicio_planejado como fallback.
     *
     * Uma atividade NÃO-marco pode gerar até 2 eventos (início E término)
     * quando ambas as datas caem na janela.
     *
     * Ordenação: data mais próxima primeiro, empate por atividade_id
     * (determinístico, mesmo padrão já usado em principaisDesvios()).
     * Top 5.
     *
     * NUNCA afirma causalidade, nunca inclui HH/responsável/percentual/
     * desvio/restrição/risco/causa/ação — só fatos de cronograma
     * (evento, data, criticidade).
     *
     * @return array<int, array> lista de até 5 eventos (atividade_id,
     *               titulo, tipo ['inicio'|'termino'|'marco'], data,
     *               caminho_critico)
     */
    #[Computed]
    public function proximosEventosRelevantes(): array
    {
        $inicioJanela = $this->report->periodo_referencia->copy()->addWeek();
        $fimJanela = $inicioJanela->copy()->endOfWeek();

        $snapshots = AtividadeSnapshot::where('cronograma_importacao_id', $this->report->cronograma_importacao_id)
            ->where(function ($query) use ($inicioJanela, $fimJanela) {
                $query->whereBetween('inicio_planejado', [$inicioJanela->toDateString(), $fimJanela->toDateString()])
                    ->orWhereBetween('data_termino', [$inicioJanela->toDateString(), $fimJanela->toDateString()]);
            })
            ->get(['atividade_id', 'inicio_planejado', 'data_termino']);

        if ($snapshots->isEmpty()) {
            return [];
        }

        $atividades = Atividade::whereIn('id', $snapshots->pluck('atividade_id'))
            ->where('fora_do_cronograma', false)
            ->get(['id', 'nome', 'codigo_cronograma', 'is_marco', 'caminho_critico'])
            ->keyBy('id');

        $eventos = [];

        foreach ($snapshots as $snapshot) {
            $atividade = $atividades->get($snapshot->atividade_id);

            if (! $atividade) {
                continue;
            }

            $titulo = $atividade->codigo_cronograma
                ? "{$atividade->codigo_cronograma} - {$atividade->nome}"
                : $atividade->nome;

            if ($atividade->is_marco) {
                $dataMarco = $snapshot->data_termino ?? $snapshot->inicio_planejado;

                if ($dataMarco && $dataMarco->between($inicioJanela, $fimJanela)) {
                    $eventos[] = [
                        'atividade_id' => $atividade->id,
                        'titulo' => $titulo,
                        'tipo' => 'marco',
                        'data' => $dataMarco->toDateString(),
                        'caminho_critico' => $atividade->caminho_critico,
                    ];
                }

                continue;
            }

            if ($snapshot->inicio_planejado && $snapshot->inicio_planejado->between($inicioJanela, $fimJanela)) {
                $eventos[] = [
                    'atividade_id' => $atividade->id,
                    'titulo' => $titulo,
                    'tipo' => 'inicio',
                    'data' => $snapshot->inicio_planejado->toDateString(),
                    'caminho_critico' => $atividade->caminho_critico,
                ];
            }

            if ($snapshot->data_termino && $snapshot->data_termino->between($inicioJanela, $fimJanela)) {
                $eventos[] = [
                    'atividade_id' => $atividade->id,
                    'titulo' => $titulo,
                    'tipo' => 'termino',
                    'data' => $snapshot->data_termino->toDateString(),
                    'caminho_critico' => $atividade->caminho_critico,
                ];
            }
        }

        return collect($eventos)
            ->sortBy(fn (array $e) => [$e['data'], $e['atividade_id']])
            ->values()
            ->take(5)
            ->all();
    }

    /**
     * Aderência ao Planejamento (Fase 5, Etapa G do roadmap original —
     * "Quão previsível é?" / "Quão aderente é o planejamento?") — lê
     * EXCLUSIVAMENTE a série já congelada em App\Models\ReportCurvaDatapoint
     * (via o mesmo App\Support\ReportCurvaSerializer::serieParaGrafico()
     * já usado por dadosGraficos()), nunca ppcPorSemana()/
     * ProgramacaoSemanal/ProgramacaoSemanalItem/Atividade ao vivo. NUNCA
     * chamada de "PPC"/"Índice de Previsibilidade"/"Score" — é
     * literalmente a aderência realizado÷previsto já calculada e exibida
     * em outras partes desta mesma página, apenas agregada em janela.
     *
     * "aderencia_periodo" NÃO é uma coluna de ReportCurvaDatapoint — é
     * DERIVADA por ReportCurvaSerializer (realizado_pct_periodo ÷
     * previsto_pct_periodo × 100), mesma fórmula já usada pela tabela
     * semanal/velocímetro desta página. Reaproveitada aqui tal como é,
     * nenhuma fórmula nova.
     *
     * Curva de referência: MESMA seleção já aprovada em resumoExecutivo()
     * — a curva "obra inteira" (pacote_trabalho_id null) quando existir;
     * senão, a de pior aderencia_atual (via $this->dadosGraficos, já
     * existente, não recalculado aqui). Não é uma fórmula nova — é o
     * mesmo critério de "qual curva representa o Report como um todo"
     * já usado alhures nesta classe.
     *
     * Elegibilidade: só semanas com aderencia_periodo !== null entram —
     * nunca preenche lacuna com zero, nunca infla a contagem. Exige
     * PELO MENOS 3 semanas elegíveis; com menos, 'tem_dado' => false e o
     * bloco inteiro não renderiza (nenhuma média artificial).
     *
     * Semanas exibidas: as ÚLTIMAS 4 elegíveis (ou as 3, se só houver 3),
     * em ordem cronológica (mais antiga → mais recente) — a própria
     * tabela de serieParaGrafico() já vem ordenada por periodo_inicio
     * ascendente, então um slice(-4) preserva a ordem sem precisar
     * reordenar.
     *
     * Média: média aritmética simples de aderencia_periodo só das
     * semanas exibidas (3 ou 4) — nunca sobre todo o histórico da obra.
     * Arredondamento SÓ na apresentação (Blade); o valor retornado aqui
     * mantém a precisão já gravada em aderencia_periodo.
     *
     * Semáforo: >=90 Boa / >=75 e <90 Atenção / <75 Crítica — limiares
     * PRÓPRIOS desta etapa, nunca os limiares do PPC (que são
     * 80/60, usados em outra página, outro conceito).
     *
     * @return array{tem_dado: bool, media?: float, faixa_emoji?: string, faixa_label?: string, semanas?: array<int, array{label: string, aderencia: float}>}
     */
    #[Computed]
    public function aderenciaPlanejamento(): array
    {
        $curvas = $this->report->curvas;

        if ($curvas->isEmpty()) {
            return ['tem_dado' => false];
        }

        $aderenciaPorCurvaId = collect($this->dadosGraficos)->keyBy('id');

        $curvaReferencia = $curvas->firstWhere('pacote_trabalho_id', null);

        if (! $curvaReferencia) {
            $curvaReferencia = $curvas
                ->filter(fn (ReportCurva $c) => $aderenciaPorCurvaId[$c->id]['aderencia_atual'] !== null)
                ->sortBy(fn (ReportCurva $c) => $aderenciaPorCurvaId[$c->id]['aderencia_atual'])
                ->first();
        }

        if (! $curvaReferencia) {
            return ['tem_dado' => false];
        }

        $tabelaSemanal = $this->serieParaGrafico($curvaReferencia, GranularidadePeriodo::Semanal)['tabela'];

        $semanasElegiveis = collect($tabelaSemanal)
            ->filter(fn (array $linha) => $linha['aderencia_periodo'] !== null)
            ->values();

        if ($semanasElegiveis->count() < 3) {
            return ['tem_dado' => false];
        }

        $semanasExibidas = $semanasElegiveis->slice(-4)->values();

        $media = $semanasExibidas->avg('aderencia_periodo');

        $faixa = match (true) {
            $media >= 90 => ['emoji' => '🟢', 'label' => 'Boa'],
            $media >= 75 => ['emoji' => '🟠', 'label' => 'Atenção'],
            default => ['emoji' => '🔴', 'label' => 'Crítica'],
        };

        return [
            'tem_dado' => true,
            'media' => $media,
            'faixa_emoji' => $faixa['emoji'],
            'faixa_label' => $faixa['label'],
            'semanas' => $semanasExibidas->map(fn (array $l) => [
                'label' => $l['label'],
                'aderencia' => $l['aderencia_periodo'],
            ])->all(),
        ];
    }

    /**
     * Decisões Prioritárias (Fase 5, Etapa F) — MESMA fonte de dado de
     * topRiscos()/impactoRestricoes() (o snapshot já congelado pela Etapa
     * C2, App\Models\ReportDesvioRestricao), agregada em tempo de leitura
     * com um filtro e uma ordenação DIFERENTES: enquanto topRiscos() lista
     * os piores riscos da obra sem nenhum recorte de tempo, este método
     * responde "quais situações exigem atenção/decisão gerencial antes da
     * próxima semana". Uma situação entra quando está vencida, é
     * bloqueante, tem risco alto (classificacao_risco === 'alto', mesmo
     * limiar P×I >= 50 de ImpactoRestricoesGerador), OU tem prazo dentro
     * da janela "próxima semana" — mesma fórmula já usada em
     * ReportGerador::gerarIndicadoresSemana() (periodo_referencia + 1
     * semana até o fim dessa semana), recalculada aqui localmente. NUNCA
     * lê ReportIndicadorSemana — aquela tabela não preserva o id da
     * restrição, então não é cruzável com segurança contra este snapshot.
     *
     * NENHUMA persistência nova, NENHUMA consulta a Restricao ao vivo —
     * mesmo princípio de topRiscos(): a linha de origem é imutável desde
     * que foi criada, então filtrar/ordenar em tempo de leitura é seguro
     * e sempre reproduz o mesmo resultado.
     *
     * IMPORTANTE — o sistema não sabe qual decisão deve ser tomada, só
     * que a situação exige atenção: NUNCA "decisão necessária: X" nem
     * "ação recomendada: Y", só os fatos já congelados na C2 (descrição,
     * status, responsável, prazo, bloqueante, vencida, classificação de
     * risco). Mesma regra de linguagem não-causal já usada em
     * topRiscos()/impactoRestricoes()/causasDoDesvio().
     *
     * Ordenação: vencida → bloqueante → risco alto → prazo mais próximo
     * (nulo por último) — critério PRÓPRIO desta seção, deliberadamente
     * diferente do critério de topRiscos() (vencida → risco alto →
     * prazo): aqui "bloqueante" pesa na ordenação, não só no filtro de
     * entrada, por decisão explícita do usuário.
     *
     * @return array<int, array> lista de até 3 situações (descricao,
     *               status, categoria_nome, responsavel_nome,
     *               prazo_limite, bloqueante, probabilidade, impacto,
     *               vencida, classificacao_risco, pacote_titulo)
     */
    #[Computed]
    public function decisoesPrioritarias(): array
    {
        $inicioProximaSemana = $this->report->periodo_referencia->copy()->addWeek();
        $fimProximaSemana = $inicioProximaSemana->copy()->endOfWeek();

        $situacoes = [];

        foreach ($this->report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $snapshot = $this->impactoRestricoes[$desvio->id] ?? null;

                if (! $snapshot) {
                    continue;
                }

                foreach ($snapshot['detalhes'] as $item) {
                    $dentroDaProximaSemana = $item['prazo_limite']
                        && Carbon::parse($item['prazo_limite'])->between($inicioProximaSemana, $fimProximaSemana);

                    $elegivel = $item['vencida']
                        || $item['bloqueante']
                        || $item['classificacao_risco'] === 'alto'
                        || $dentroDaProximaSemana;

                    if (! $elegivel) {
                        continue;
                    }

                    $situacoes[] = [
                        ...$item,
                        'pacote_titulo' => $desvio->titulo_exibicao,
                    ];
                }
            }
        }

        return collect($situacoes)
            ->sortBy(fn (array $s) => [
                $s['vencida'] ? 0 : 1,
                $s['bloqueante'] ? 0 : 1,
                $s['classificacao_risco'] === 'alto' ? 0 : 1,
                $s['prazo_limite'] ?? '9999-12-31',
            ])
            ->values()
            ->take(3)
            ->all();
    }

    /**
     * Resumo Executivo (Fase 5 — Consolidação) — 5 indicadores 100%
     * determinísticos, derivados exclusivamente de dados já existentes
     * no Report (ReportCurva/ReportDesvio/ReportPontoAtencao já
     * carregados pelo mount()). Nenhum cálculo novo: só MIN/primeiro-item
     * sobre dado já persistido/já calculado por outro computed desta
     * mesma classe. Nunca texto generativo, nunca inferência não
     * suportada pelo dado atual.
     *
     * "Curva de referência" (usada por 'status' e 'variacao_termino'):
     * a curva "obra inteira" (pacote_trabalho_id null) quando existir;
     * senão, a de pior aderência da última semana atualizada (menor
     * aderencia_atual, ignorando curvas sem esse dado) — decisão
     * confirmada explicitamente com o usuário antes de implementar.
     *
     * "Status do Cronograma" reaproveita os MESMOS limiares já usados no
     * resto da página (ADERENCIA_LIMIAR_ATENCAO/_OTIMO) — nenhum limiar
     * novo inventado, decisão confirmada explicitamente.
     *
     * "Maior desvio" = pior percentual_impacto entre TODAS as linhas
     * (nível pai + filhas) de TODAS as curvas do report — o número mais
     * grave já calculado em qualquer tabela de Análise de Desvios da
     * página. Combina deliberadamente as perguntas "qual pacote mais
     * preocupa" e "qual o principal desvio" num único indicador (a
     * própria etapa pede eliminar redundância — mostrar as duas
     * perguntas em cards separados repetiria o mesmo fato duas vezes).
     *
     * "Ponto de atenção" = primeiro texto encontrado, na ordem natural
     * das curvas/pontos (ambos já ordenados por 'ordem' nas relações) —
     * sem ranquear por gravidade (texto livre não tem severidade).
     */
    #[Computed]
    public function resumoExecutivo(): array
    {
        $curvas = $this->report->curvas;

        if ($curvas->isEmpty()) {
            return ['tem_dado' => false];
        }

        $aderenciaPorCurvaId = collect($this->dadosGraficos)->keyBy('id');

        $curvaReferencia = $curvas->firstWhere('pacote_trabalho_id', null);

        if (! $curvaReferencia) {
            $curvaReferencia = $curvas
                ->filter(fn (ReportCurva $c) => $aderenciaPorCurvaId[$c->id]['aderencia_atual'] !== null)
                ->sortBy(fn (ReportCurva $c) => $aderenciaPorCurvaId[$c->id]['aderencia_atual'])
                ->first();
        }

        $aderenciaReferencia = $curvaReferencia ? $aderenciaPorCurvaId[$curvaReferencia->id]['aderencia_atual'] : null;

        $status = match (true) {
            $aderenciaReferencia === null => 'sem_dado',
            $aderenciaReferencia >= self::ADERENCIA_LIMIAR_OTIMO => 'dentro_do_plano',
            $aderenciaReferencia >= self::ADERENCIA_LIMIAR_ATENCAO => 'atencao',
            default => 'critico',
        };

        $piorDesvio = $curvas
            ->flatMap(fn (ReportCurva $c) => $c->desvios)
            ->sortBy(fn (ReportDesvio $d) => (float) $d->percentual_impacto)
            ->first();

        $variacaoTermino = $curvaReferencia ? $this->variacaoDias($curvaReferencia) : null;

        $pontoAtencao = $curvas
            ->flatMap(fn (ReportCurva $c) => $c->pontosAtencao)
            ->first();

        return [
            'tem_dado' => true,
            'curva_referencia_titulo' => $curvaReferencia?->titulo_exibicao,
            'status' => $status,
            'maior_desvio' => $piorDesvio ? [
                'titulo' => $piorDesvio->titulo_exibicao,
                'percentual_impacto' => (float) $piorDesvio->percentual_impacto,
            ] : null,
            'variacao_termino' => $variacaoTermino,
            'ponto_atencao' => $pontoAtencao ? [
                'categoria' => $pontoAtencao->categoria,
                'texto' => $pontoAtencao->texto,
            ] : null,
        ];
    }

    public function salvarTitulo(): void
    {
        $this->authorize('update', $this->report);

        $this->report->update(['titulo' => trim($this->tituloEdit) ?: null]);
        $this->dispatch('show-toast', message: 'Título atualizado.');
    }

    /**
     * Gera um link assinado (Laravel signed route, 30 dias de validade)
     * pra este report específico — único jeito de acesso somente-leitura
     * sem login, servido por App\Http\Controllers\ClienteRelatorioPublicoController.
     * Só faz sentido pra report já emitido (rascunho nunca é exposto
     * publicamente, mesma regra de visibilidade normal do sistema).
     */
    public function gerarLinkCliente(): void
    {
        $this->authorize('view', $this->report);
        abort_unless($this->report->estaEmitido(), 403);

        $this->linkClienteGerado = URL::temporarySignedRoute(
            'cliente.relatorio.publico',
            now()->addDays(30),
            ['report' => $this->report->id]
        );
    }

    private function sincronizarPontosAtencaoEdit(): void
    {
        foreach ($this->report->curvas as $curva) {
            $this->pontosAtencaoEdit[$curva->id] = $curva->pontosAtencao->map(fn ($p) => [
                'id' => $p->id,
                'categoria' => $p->categoria,
                'texto' => $p->texto,
            ])->all();
        }
    }

    private function sincronizarLegendasFotosEdit(): void
    {
        $this->legendasFotosEdit = $this->report->fotos->mapWithKeys(fn ($f) => [$f->id => $f->legenda])->all();
    }

    /**
     * Monta os dados de cada curva (mensal + semanal) pra gráficos E
     * tabelas — consumido tanto pelo Blade (tabelas, server-side) quanto
     * pelo bloco @script no fim da view (gráficos Chart.js, via @json).
     */
    #[Computed]
    public function dadosGraficos(): array
    {
        return $this->report->curvas->map(function (ReportCurva $curva) {
            $mensal = $this->serieParaGrafico($curva, GranularidadePeriodo::Mensal);
            $semanal = $this->serieParaGrafico($curva, GranularidadePeriodo::Semanal);

            return [
                'id' => $curva->id,
                'mensal' => $mensal,
                'semanal' => $semanal,
                // Aderência "da semana corrente" = aderencia_periodo (ver
                // serieParaGrafico()) da ÚLTIMA semana que teve atualização
                // de verdade (previsto E realizado presentes) — não
                // necessariamente a última das 4 semanas gravadas, pois o
                // realizado pode não ter chegado até lá ainda (nesse caso a
                // última semana ficaria com aderencia_periodo=null e o
                // velocímetro pareceria "quebrado").
                'aderencia_atual' => $this->aderenciaDaUltimaSemanaAtualizada($semanal['tabela']),
            ];
        })->all();
    }

    /** Varre a tabela semanal de trás pra frente e devolve a aderência DO PERÍODO (%real÷%previsto daquela semana) da última semana com dado — nunca a última data gravada se ela ainda não tiver realizado. */
    private function aderenciaDaUltimaSemanaAtualizada(array $tabelaSemanal): ?float
    {
        for ($i = count($tabelaSemanal) - 1; $i >= 0; $i--) {
            if ($tabelaSemanal[$i]['aderencia_periodo'] !== null) {
                return $tabelaSemanal[$i]['aderencia_periodo'];
            }
        }

        return null;
    }

    /**
     * Delega pra App\Support\ReportCurvaSerializer (extraída daqui pra
     * ser reaproveitada também pelo link público do cliente — mesma
     * lógica, sem duplicar/arriscar divergência entre as duas telas).
     */
    private function serieParaGrafico(ReportCurva $curva, GranularidadePeriodo $gran): array
    {
        return \App\Support\ReportCurvaSerializer::serieParaGrafico($curva, $gran);
    }

    /** Limiares do velocímetro de aderência, expostos pro Blade repassar ao JS (evita duplicar os números). */
    public function limiaresAderencia(): array
    {
        return [self::ADERENCIA_LIMIAR_ATENCAO, self::ADERENCIA_LIMIAR_OTIMO];
    }

    /** Indicadores da semana anterior (previsto×concluído), 1 por categoria, indexados por 'categoria'. */
    #[Computed]
    public function indicadoresSemanaAnterior(): \Illuminate\Support\Collection
    {
        return $this->report->indicadoresSemana
            ->where('janela', 'semana_anterior')
            ->keyBy('categoria');
    }

    /** Indicadores da próxima semana (só previsto), 1 por categoria, indexados por 'categoria'. */
    #[Computed]
    public function indicadoresSemanaProxima(): \Illuminate\Support\Collection
    {
        return $this->report->indicadoresSemana
            ->where('janela', 'semana_proxima')
            ->keyBy('categoria');
    }

    /**
     * Variação do término (tendência − linha de base), em dias — positivo
     * = atraso, negativo = adiantado. Calculado via subtração de
     * timestamps (não Carbon::diffInDays) pra não depender de convenção
     * de sinal entre versões do Carbon.
     */
    private function variacaoDias(ReportCurva $curva): ?int
    {
        if (! $curva->termino_linha_base || ! $curva->termino_tendencia) {
            return null;
        }

        return (int) round(($curva->termino_tendencia->timestamp - $curva->termino_linha_base->timestamp) / 86400);
    }

    public function emitir(): void
    {
        $this->authorize('emitir', $this->report);

        app(ReportGerador::class)->emitir($this->report, auth()->user());

        $this->dispatch('hide-emitir-modal');
        $this->dispatch('show-toast', message: 'Report emitido. Agora é somente-leitura e visível a todos com acesso à obra.');
    }

    public function adicionarComentario(): void
    {
        $this->authorize('comentar', $this->report);

        $this->validate([
            'novoComentario' => 'required|string|min:2',
        ], [], ['novoComentario' => 'comentário']);

        $this->report->comentarios()->create([
            'autor_id' => auth()->id(),
            'comentario' => $this->novoComentario,
        ]);

        $this->novoComentario = '';
        $this->report->load('comentarios.autor:id,first_name,last_name');
        $this->dispatch('show-toast', message: 'Comentário adicionado.');
    }

    public function adicionarPontoAtencaoEdit(string $curvaId): void
    {
        $this->authorize('update', $this->report);

        $this->pontosAtencaoEdit[$curvaId][] = ['id' => null, 'categoria' => '', 'texto' => ''];
    }

    public function removerPontoAtencaoEdit(string $curvaId, int $indice): void
    {
        $this->authorize('update', $this->report);

        $ponto = $this->pontosAtencaoEdit[$curvaId][$indice] ?? null;
        if ($ponto && $ponto['id']) {
            ReportPontoAtencao::where('id', $ponto['id'])->delete();
            // Nunca ->load('curvas.pontosAtencao') isolado — ver docblock
            // de relacoesReportCompletas(). Recarrega a lista completa
            // pra não perder desvios/datapoints/pacoteTrabalho das curvas.
            $this->report->load($this->relacoesReportCompletas());
        }

        unset($this->pontosAtencaoEdit[$curvaId][$indice]);
        $this->pontosAtencaoEdit[$curvaId] = array_values($this->pontosAtencaoEdit[$curvaId]);
    }

    public function salvarPontosAtencao(string $curvaId): void
    {
        $this->authorize('update', $this->report);

        $curva = $this->report->curvas->firstWhere('id', $curvaId);
        abort_unless($curva, 404);

        foreach ($this->pontosAtencaoEdit[$curvaId] as $i => &$ponto) {
            if (trim($ponto['texto'] ?? '') === '') {
                continue;
            }

            if ($ponto['id']) {
                ReportPontoAtencao::where('id', $ponto['id'])->update([
                    'categoria' => $ponto['categoria'] ?: null,
                    'texto' => $ponto['texto'],
                    'ordem' => $i,
                ]);
            } else {
                $novo = $curva->pontosAtencao()->create([
                    'categoria' => $ponto['categoria'] ?: null,
                    'texto' => $ponto['texto'],
                    'ordem' => $i,
                ]);
                $ponto['id'] = $novo->id;
            }
        }
        unset($ponto);

        // Nunca ->load('curvas.pontosAtencao') isolado — ver docblock de
        // relacoesReportCompletas(). Recarrega a lista completa pra não
        // perder desvios/datapoints/pacoteTrabalho das curvas.
        $this->report->load($this->relacoesReportCompletas());
        $this->dispatch('show-toast', message: 'Pontos de atenção atualizados.');
    }

    public function enviarFotos(): void
    {
        $this->authorize('update', $this->report);

        $this->validate([
            'novasFotosUpload.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if (empty($this->novasFotosUpload)) {
            return;
        }

        $ordemBase = ((int) $this->report->fotos->max('ordem')) + 1;
        foreach ($this->novasFotosUpload as $i => $arquivo) {
            $caminho = $arquivo->store("report-fotos/{$this->report->obra_id}/{$this->report->id}", 'public');
            $this->report->fotos()->create([
                'caminho_arquivo' => $caminho,
                'legenda' => $this->legendasNovasFotosUpload[$i] ?? null,
                'ordem' => $ordemBase + $i,
                'enviado_por' => auth()->id(),
            ]);
        }

        $this->reset(['novasFotosUpload', 'legendasNovasFotosUpload']);
        $this->report->load('fotos.enviadoPor:id,first_name,last_name');
        $this->sincronizarLegendasFotosEdit();
        $this->dispatch('show-toast', message: 'Fotos adicionadas.');
    }

    public function removerFoto(string $fotoId): void
    {
        $this->authorize('update', $this->report);

        $foto = $this->report->fotos->firstWhere('id', $fotoId);
        abort_unless($foto, 404);

        if ($foto->caminho_arquivo) {
            Storage::disk('public')->delete($foto->caminho_arquivo);
        }
        $foto->delete();

        $this->report->load('fotos.enviadoPor:id,first_name,last_name');
        unset($this->legendasFotosEdit[$fotoId]);
        $this->dispatch('show-toast', message: 'Foto removida.');
    }

    public function salvarLegendaFoto(string $fotoId): void
    {
        $this->authorize('update', $this->report);

        ReportFoto::where('id', $fotoId)->update([
            'legenda' => $this->legendasFotosEdit[$fotoId] ?: null,
        ]);

        $this->report->load('fotos.enviadoPor:id,first_name,last_name');
        $this->dispatch('show-toast', message: 'Legenda atualizada.');
    }

    public function exportarPdf()
    {
        $pdf = Pdf::loadView('exports.report-pdf', ['report' => $this->report]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            "report-{$this->report->periodo_referencia->format('Y-m-d')}.pdf"
        );
    }

    public function exportarExcel()
    {
        return Excel::download(
            new ReportExport($this->report),
            "report-{$this->report->periodo_referencia->format('Y-m-d')}.xlsx"
        );
    }
};

?>

<div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Cabeçalho --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            @can('update', $report)
            <div class="d-flex align-items-center gap-2 mb-1">
                <input type="text" class="form-control form-control-sm w-auto" style="min-width: 260px"
                       wire:model="tituloEdit" wire:keydown.enter="salvarTitulo"
                       placeholder="Título (opcional) — ex: Report Semana 26">
                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="salvarTitulo">
                    <i class="bx bx-save"></i>
                </button>
                <span class="badge bg-{{ $report->status->corBadge() }}">{{ $report->status->label() }}</span>
            </div>
            @else
            <h5 class="mb-1">
                {{ $report->titulo ?: 'Report ' . $report->periodo_referencia->format('d/m/Y') }}
                <span class="badge bg-{{ $report->status->corBadge() }} ms-2">{{ $report->status->label() }}</span>
            </h5>
            @endcan
            <small class="text-muted">
                Semana de {{ $report->periodo_referencia->format('d/m/Y') }}
                @if($report->data_status)
                — status em {{ $report->data_status->format('d/m/Y') }}
                @endif
                @if($report->criador)
                — criado por {{ $report->criador->first_name }} {{ $report->criador->last_name }}
                @endif
                @if($report->estaEmitido())
                — emitido em {{ $report->emitido_em?->format('d/m/Y H:i') }}
                @if($report->emissor)
                por {{ $report->emissor->first_name }} {{ $report->emissor->last_name }}
                @endif
                @else
                — rascunho, ainda não emitido
                @endif
            </small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-danger btn-sm" wire:click="exportarPdf">
                <i class="bx bxs-file-pdf me-1"></i>PDF
            </button>
            <button class="btn btn-outline-success btn-sm" wire:click="exportarExcel">
                <i class="bx bxs-file-export me-1"></i>Excel
            </button>
            @if($report->estaEmitido())
            <button class="btn btn-outline-primary btn-sm" wire:click="gerarLinkCliente">
                <i class="bx bx-link me-1"></i>Link para o cliente
            </button>
            @endif
            @can('emitir', $report)
            <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#emitirReportModal">
                <i class="bx bx-send me-1"></i>Emitir
            </button>
            @endcan
        </div>
    </div>

    @if($linkClienteGerado)
    <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-4" x-data>
        <i class="bx bx-link"></i>
        <input type="text" readonly class="form-control form-control-sm" value="{{ $linkClienteGerado }}" x-ref="linkCliente" onclick="this.select()">
        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                x-on:click="navigator.clipboard.writeText($refs.linkCliente.value)">
            <i class="bx bx-copy"></i> Copiar
        </button>
        <small class="text-muted flex-shrink-0">Válido por 30 dias, sem login.</small>
    </div>
    @endif

    {{--
        O alerta grande de Rascunho/Emitido que existia aqui (Fase 5,
        Consolidação) foi REMOVIDO — duplicava o badge de status do
        cabeçalho. A informação extra que ele carregava (quem emitiu e
        quando) foi preservada, só migrou para a linha muted do
        cabeçalho ("— emitido em .../por ..." ou "— rascunho, ainda não
        emitido"), logo abaixo do título.
    --}}

    {{-- ------------------------------------------------------------------ --}}
    {{-- Resumo Executivo (Fase 5 — Consolidação) — 5 indicadores           --}}
    {{-- determinísticos, ver resumoExecutivo() no componente. Logo após    --}}
    {{-- cabeçalho/status, antes de qualquer detalhe por curva.             --}}
    {{-- ------------------------------------------------------------------ --}}
    @php $resumo = $this->resumoExecutivo; @endphp
    @if($resumo['tem_dado'])
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bx bx-bar-chart-alt-2 me-1"></i>Resumo Executivo</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <p class="text-muted small mb-1">Status do Cronograma</p>
                        @switch($resumo['status'])
                            @case('dentro_do_plano')
                                <span class="badge bg-success fs-6">Dentro do Plano</span>
                                @break
                            @case('atencao')
                                <span class="badge bg-warning fs-6">Atenção</span>
                                @break
                            @case('critico')
                                <span class="badge bg-danger fs-6">Crítico</span>
                                @break
                            @default
                                <span class="badge bg-label-secondary fs-6">Sem dado suficiente</span>
                        @endswitch
                        @if($resumo['curva_referencia_titulo'])
                        <small class="d-block text-muted mt-1">baseado em: {{ $resumo['curva_referencia_titulo'] }}</small>
                        @endif
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <p class="text-muted small mb-1">Maior Desvio</p>
                        @if($resumo['maior_desvio'])
                        <h6 class="mb-0">{{ $resumo['maior_desvio']['titulo'] }}</h6>
                        <span class="badge {{ $resumo['maior_desvio']['percentual_impacto'] < 0 ? 'bg-danger' : 'bg-success' }}">
                            {{ number_format($resumo['maior_desvio']['percentual_impacto'], 1, ',', '.') }}% de impacto
                        </span>
                        @else
                        <h6 class="mb-0 text-muted">—</h6>
                        @endif
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <p class="text-muted small mb-1">Tendência de Término</p>
                        @if($resumo['variacao_termino'] === null)
                        <h6 class="mb-0 text-muted">—</h6>
                        @else
                        <span class="badge fs-6 bg-{{ $resumo['variacao_termino'] > 0 ? 'danger' : ($resumo['variacao_termino'] < 0 ? 'success' : 'secondary') }}">
                            {{ $resumo['variacao_termino'] > 0 ? "+{$resumo['variacao_termino']}d atraso" : ($resumo['variacao_termino'] < 0 ? "{$resumo['variacao_termino']}d adiantado" : 'No prazo') }}
                        </span>
                        @endif
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <p class="text-muted small mb-1">Principal Ponto de Atenção</p>
                        @if($resumo['ponto_atencao'])
                        @if($resumo['ponto_atencao']['categoria'])
                        <span class="badge bg-label-warning mb-1">{{ $resumo['ponto_atencao']['categoria'] }}</span>
                        @endif
                        <p class="small mb-0">{{ \Illuminate\Support\Str::limit($resumo['ponto_atencao']['texto'], 90) }}</p>
                        @else
                        <p class="small text-muted mb-0">Nenhum ponto de atenção registrado.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Diagnóstico da Obra (Fase 5) — visão de nível REPORT (não por      --}}
    {{-- curva): Confiabilidade do Cronograma (Etapa A) hoje; espaço        --}}
    {{-- reservado para Top Riscos (Etapa D, futura). Reorganização de     --}}
    {{-- layout — nenhuma regra/cálculo/query alterada.                    --}}
    {{-- ------------------------------------------------------------------ --}}
    <h5 class="mb-3">Diagnóstico da Obra</h5>
    <div class="mb-4">
        {{-- Confiabilidade do Cronograma (Fase 5, Etapa A) — Health Check/ --}}
        {{-- Score já persistidos da importação de origem deste Report.    --}}
        {{-- Nunca recalculado aqui (ver confiabilidadeCronograma() no     --}}
        {{-- componente). Independente do avanço físico: mede a qualidade  --}}
        {{-- estrutural da base de planejamento, não se a obra está        --}}
        {{-- adiantada/atrasada.                                          --}}
        @php $confiabilidade = $this->confiabilidadeCronograma; @endphp
        <div class="card mb-2">
            <div class="card-body py-2 px-3 d-flex align-items-center flex-wrap gap-2">
                <i class="bx bx-shield-quarter text-muted"></i>
                <small class="text-muted me-1">Confiabilidade do Cronograma:</small>

                @if ($confiabilidade['estado'] === 'indisponivel')
                    <small class="text-muted">Não disponível para esta importação.</small>
                @elseif ($confiabilidade['estado'] === 'sem_score')
                    <small>
                        {{ $confiabilidade['total_ocorrencias'] }} achado(s)
                        @if ($confiabilidade['total_criticos'] > 0)
                            · {{ $confiabilidade['total_criticos'] }} crítico(s)
                        @endif
                        @if ($confiabilidade['total_altos'] > 0)
                            · {{ $confiabilidade['total_altos'] }} alto(s)
                        @endif
                        <span class="text-muted">(Score não disponível para esta importação)</span>
                    </small>
                @else
                    <span class="fw-medium">{{ $confiabilidade['score'] }}/100</span>
                    <span class="badge bg-{{ $confiabilidade['faixa_cor'] }}">{{ $confiabilidade['faixa_label'] }}</span>
                    <small class="text-muted">
                        @if ($confiabilidade['total_criticos'] > 0 || $confiabilidade['total_altos'] > 0)
                            {{ $confiabilidade['total_ocorrencias'] }} achado(s)
                            @if ($confiabilidade['total_criticos'] > 0)
                                · {{ $confiabilidade['total_criticos'] }} crítico(s)
                            @endif
                            @if ($confiabilidade['total_altos'] > 0)
                                · {{ $confiabilidade['total_altos'] }} alto(s)
                            @endif
                        @else
                            {{-- "Achados relevantes" = crítico/alto — achados de --}}
                            {{-- baixa/média/informativa severidade não disparam --}}
                            {{-- este alerta, mesmo existindo (nunca alarme --}}
                            {{-- artificial pra severidade baixa/informativa). --}}
                            Nenhuma inconsistência crítica ou alta identificada
                        @endif
                    </small>
                @endif
            </div>
        </div>
        {{--
            Principais Desvios (Fase 5, Etapa B do roadmap original —
            "Onde está o problema?") — lê principaisDesvios() (leitura em
            tempo real sobre App\Models\ReportDesvio já congelado pela
            geração do Report, SEM consulta a Restricao ou qualquer fonte
            externa). Bloco inteiro (heading incluso) só aparece quando
            há pelo menos 1 desvio negativo no Top 3 — mesmo padrão já
            usado em "Impacto de Restrições"/"Top Riscos da Obra".
            Linguagem estritamente factual: nunca afirma que um pacote
            causou o problema da obra, só que existe um desvio associado
            a ele.
        --}}
        @php $principaisDesvios = $this->principaisDesvios; @endphp
        @if(count($principaisDesvios) > 0)
        <div class="mb-2">
            <h6 class="mb-2"><i class="bx bx-trending-down text-danger me-1"></i>Principais Desvios</h6>
            <div class="row g-2">
                @foreach($principaisDesvios as $desvio)
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                            <span class="badge bg-label-secondary">{{ $desvio['titulo_exibicao'] }}</span>
                            <span class="badge bg-danger">
                                {{ number_format($desvio['percentual_impacto'], 1, ',', '.') }}% de impacto
                            </span>
                        </div>
                        <small class="text-muted d-block">
                            Previsto {{ number_format($desvio['percentual_previsto'], 1, ',', '.') }}%
                            × Realizado {{ number_format($desvio['percentual_real'], 1, ',', '.') }}%
                        </small>
                        <small class="text-danger d-block">
                            Desvio de {{ number_format($desvio['percentual_desvio'], 1, ',', '.') }}%
                        </small>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
        {{--
            Top Riscos da Obra (Fase 5, Etapa D) — lê topRiscos() (leitura
            em tempo real sobre o snapshot já persistido pela Etapa C2,
            SEM nenhuma consulta a Restricao ao vivo, SEM nenhuma
            persistência nova). Bloco inteiro (heading incluso) só
            aparece quando há pelo menos 1 risco no Top 3 — nunca um
            cabeçalho vazio, mesmo padrão já usado em "Impacto de
            Restrições". Linguagem estritamente factual: nunca afirma
            que um risco causou um desvio/atraso — só que a restrição
            está associada ao escopo daquele pacote.
        --}}
        @php $topRiscos = $this->topRiscos; @endphp
        @if(count($topRiscos) > 0)
        <div class="mt-2">
            <h6 class="mb-2"><i class="bx bx-error text-danger me-1"></i>Top Riscos da Obra</h6>
            <div class="row g-2">
                @foreach($topRiscos as $risco)
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                            <span class="badge bg-label-secondary">{{ $risco['pacote_titulo'] }}</span>
                            <div class="d-flex gap-1 flex-wrap">
                                @if($risco['vencida'])
                                <span class="badge bg-danger">Vencida</span>
                                @endif
                                @if($risco['classificacao_risco'] === 'alto')
                                <span class="badge bg-warning">Risco crítico</span>
                                @endif
                            </div>
                        </div>
                        <p class="small mb-1">{{ \Illuminate\Support\Str::limit($risco['descricao'], 90) }}</p>
                        <small class="text-muted d-block">
                            {{ $risco['status'] }}
                            @if($risco['responsavel_nome']) · {{ $risco['responsavel_nome'] }} @endif
                            @if($risco['prazo_limite']) · prazo {{ \Illuminate\Support\Carbon::parse($risco['prazo_limite'])->format('d/m/Y') }} @endif
                        </small>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
        {{--
            Próximos Eventos Relevantes (Fase 5, Etapa F do roadmap
            original — "O que vem pela frente?") — lê
            proximosEventosRelevantes() (leitura em tempo real sobre
            App\Models\AtividadeSnapshot, ancorado em
            $report->cronograma_importacao_id — a mesma importação já
            travada pelo Report, nunca "a mais recente". SEM nenhuma
            consulta a Restricao/ReportDesvio/ProgramacaoSemanal). Bloco
            inteiro só aparece quando há pelo menos 1 evento no horizonte
            da próxima semana — mesmo padrão já usado em "Top Riscos da
            Obra"/"Impacto de Restrições". Lookahead EXECUTIVO, nunca um
            segundo Plano Semanal — só fatos de cronograma (evento/data/
            criticidade), nunca HH/responsável/desvio/risco/causalidade.
        --}}
        @php $proximosEventos = $this->proximosEventosRelevantes; @endphp
        @if(count($proximosEventos) > 0)
        <div class="mt-2">
            <h6 class="mb-2"><i class="bx bx-calendar-event text-primary me-1"></i>Próximos Eventos Relevantes</h6>
            <div class="row g-2">
                @foreach($proximosEventos as $evento)
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                            <span class="badge bg-label-secondary">
                                @if($evento['tipo'] === 'marco')
                                    Marco
                                @elseif($evento['tipo'] === 'inicio')
                                    Início previsto
                                @else
                                    Término previsto
                                @endif
                                — {{ \Illuminate\Support\Carbon::parse($evento['data'])->format('d/m/Y') }}
                            </span>
                            @if($evento['caminho_critico'])
                            <span class="badge bg-danger">Caminho Crítico</span>
                            @endif
                        </div>
                        <p class="small mb-0">{{ $evento['titulo'] }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
        {{--
            Aderência ao Planejamento (Fase 5, Etapa G do roadmap original
            — "Quão previsível é?"/"Quão aderente é o planejamento?") — lê
            aderenciaPlanejamento() (leitura em tempo real sobre
            App\Models\ReportCurvaDatapoint já congelado, via o mesmo
            ReportCurvaSerializer já usado por dadosGraficos(). SEM
            ppcPorSemana()/ProgramacaoSemanal/Atividade ao vivo). Bloco
            inteiro só aparece com pelo menos 3 semanas elegíveis — mesmo
            padrão de "nunca cabeçalho vazio" já usado nos blocos
            anteriores. NUNCA chamado de PPC/Score/Índice de
            Previsibilidade — é a aderência realizado÷previsto já
            calculada em outras partes desta página, só agregada.
        --}}
        @php $aderenciaPlanejamento = $this->aderenciaPlanejamento; @endphp
        @if($aderenciaPlanejamento['tem_dado'])
        <div class="mt-2">
            <h6 class="mb-2"><i class="bx bx-trending-up text-primary me-1"></i>Aderência ao Planejamento</h6>
            <div class="border rounded p-2">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="fs-4">{{ $aderenciaPlanejamento['faixa_emoji'] }}</span>
                    <span class="fw-medium fs-5">{{ number_format($aderenciaPlanejamento['media'], 0) }}%</span>
                    <small class="text-muted">{{ $aderenciaPlanejamento['faixa_label'] }}</small>
                </div>
                <small class="text-muted d-block mb-1">
                    Aderência média — últimas {{ count($aderenciaPlanejamento['semanas']) }} semanas
                </small>
                <small class="text-muted d-block">
                    Evolução semanal:
                    {{ collect($aderenciaPlanejamento['semanas'])->map(fn ($s) => "{$s['label']} · ".number_format($s['aderencia'], 0).'%')->join(' → ') }}
                </small>
            </div>
        </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Aviso de precisão (Fase 5, Consolidação) — reduzido a ícone com    --}}
    {{-- tooltip (mesmo padrão data-bs-toggle="tooltip" já usado em         --}}
    {{-- ⚡curvas.blade.php, main.js já inicializa globalmente), nunca mais  --}}
    {{-- um parágrafo competindo com o conteúdo executivo. Texto idêntico,  --}}
    {{-- só reduzido de peso visual. Fica fora do loop de curvas de        --}}
    {{-- propósito: precisa aparecer mesmo num Report sem nenhuma curva    --}}
    {{-- ainda cadastrada.                                                --}}
    {{-- ------------------------------------------------------------------ --}}
    <p class="text-muted small mb-4">
        <i class="bx bx-info-circle" data-bs-toggle="tooltip" title="Aviso de precisão: os totais de HH conferem exatamente com o cronograma importado. A distribuição mensal/semanal é reconstruída pelo ponto médio de cada bloco faseado do MS Project e pode ter desvio de fronteira de até ~0,3% em relação ao que o MS Project exibe — isso é esperado e transparente."></i>
        Precisão dos dados
    </p>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Curvas --}}
    {{-- ------------------------------------------------------------------ --}}
    @foreach($report->curvas as $i => $curva)
    @php
        $dados = $this->dadosGraficos[$i];
        $variacao = $this->variacaoDias($curva);
    @endphp
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bx bx-line-chart me-1"></i>{{ $curva->titulo_exibicao }}</h5>
        </div>
        <div class="card-body">

            {{-- Cards de KPI --}}
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card h-100 bg-label-primary">
                        <div class="card-body">
                            <p class="text-muted mb-1">Atividades no Escopo</p>
                            <h3 class="mb-0">{{ $curva->total_atividades }}</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 bg-label-success">
                        <div class="card-body">
                            <p class="text-muted mb-1">Concluídas</p>
                            <h3 class="mb-0">{{ $curva->atividades_concluidas }}</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 {{ $curva->atividades_atrasadas > 0 ? 'bg-label-danger' : 'bg-label-secondary' }}">
                        <div class="card-body">
                            <p class="text-muted mb-1">Atrasadas</p>
                            <h3 class="mb-0">{{ $curva->atividades_atrasadas }}</h3>
                            {{-- HH Expostas por Atraso (Fase 5, Etapa C1) — dobrada aqui como --}}
                            {{-- linha secundária (Consolidação), nunca mais um card próprio.  --}}
                            {{-- Mesmo computed hhExpostaPorAtraso(), sem nenhuma alteração de  --}}
                            {{-- cálculo. Estimativa proporcional, nunca apresentada como HH    --}}
                            {{-- real (tooltip discreto explica isso). --}}
                            @php $hhExposta = $this->hhExpostaPorAtraso[$curva->id] ?? ['estado' => 'sem_dado']; @endphp
                            @if($hhExposta['estado'] === 'ok' && $hhExposta['hh_exposta_estimada'] > 0)
                            <small class="d-block mt-1">
                                ≈ {{ number_format($hhExposta['hh_exposta_estimada'], 0, ',', '.') }} HH expostas
                                <i class="bx bx-info-circle" data-bs-toggle="tooltip"
                                   title="Estimativa proporcional — assume distribuição uniforme do HH previsto entre as atividades do escopo desta curva. Não representa a soma exata do HH de cada atividade atrasada individualmente."></i>
                            </small>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <p class="text-muted mb-1">Variação de Término</p>
                            @if($variacao === null)
                            <h5 class="mb-0 text-muted">—</h5>
                            @else
                            <span class="badge fs-6 bg-{{ $variacao > 0 ? 'danger' : ($variacao < 0 ? 'success' : 'secondary') }}">
                                {{ $variacao > 0 ? "+{$variacao}d atraso" : ($variacao < 0 ? "{$variacao}d adiantado" : 'No prazo') }}
                            </span>
                            @endif
                            {{-- Términos em destaque (Fase 5, Consolidação) — colapsado aqui  --}}
                            {{-- como detalhe secundário do MESMO KPI (as datas são o insumo   --}}
                            {{-- literal do cálculo de variação acima) em vez de bloco próprio. --}}
                            <small class="d-block text-muted mt-1">
                                LB: {{ $curva->termino_linha_base?->format('d/m/Y') ?? '—' }}
                                → Tend.: {{ $curva->termino_tendencia?->format('d/m/Y') ?? '—' }}
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Quadro de análise de desvios (Fase 5: subiu para logo após os --}}
            {{-- KPIs/Términos — é o número mais importante da curva, antes  --}}
            {{-- do detalhamento técnico dos gráficos abaixo. Nenhuma        --}}
            {{-- coluna/fórmula alterada.                                   --}}
            <h6 class="mb-2"><i class="bx bx-table me-1"></i>Análise de Desvios</h6>
            <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Pacote</th>
                            <th class="text-end">Peso</th>
                            <th class="text-end">% Previsto</th>
                            <th class="text-end">% Real</th>
                            <th class="text-end">% Desvio</th>
                            <th class="text-end">% Impacto</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{--
                            Ordenação/destaque (Fase 5, Consolidação) — SÓ visual, nesta
                            tabela. Não toca $curva->desvios (continua ordenado por
                            'ordem', coluna persistida gravada pelo ReportGerador —
                            nenhuma escrita, nenhum resort da relação/model). Linha
                            "nível pai" sempre primeiro (é o total/resumo, não compete
                            no ranking); filhas ordenadas pelo pior percentual_impacto
                            (mais negativo primeiro); as 3 piores COM desvio negativo
                            real ganham destaque visual (nunca destaca uma linha
                            positiva só para completar 3).
                        --}}
                        @php
                            $nivelPaiLinha = $curva->desvios->firstWhere('eh_nivel_pai', true);
                            $filhosOrdenados = $curva->desvios->where('eh_nivel_pai', false)
                                ->sortBy(fn ($d) => (float) $d->percentual_impacto)
                                ->values();
                            $linhasExibicao = $nivelPaiLinha
                                ? collect([$nivelPaiLinha])->merge($filhosOrdenados)
                                : $filhosOrdenados;
                            $piores3Ids = $filhosOrdenados
                                ->filter(fn ($d) => (float) $d->percentual_impacto < 0)
                                ->take(3)
                                ->pluck('id')
                                ->all();
                        @endphp
                        @foreach($linhasExibicao as $desvio)
                        <tr class="{{ $desvio->eh_nivel_pai ? 'table-light fw-bold' : (in_array($desvio->id, $piores3Ids) ? 'border-start border-danger border-3' : '') }}">
                            <td>
                                @if(!$desvio->eh_nivel_pai && in_array($desvio->id, $piores3Ids))
                                <i class="bx bx-error text-danger me-1" data-bs-toggle="tooltip" title="Um dos 3 maiores desvios negativos desta curva"></i>
                                @endif
                                {{ $desvio->titulo_exibicao }}
                            </td>
                            <td class="text-end">{{ number_format($desvio->peso * 100, 1, ',', '.') }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_previsto, 1, ',', '.') }}%</td>
                            <td class="text-end">{{ number_format($desvio->percentual_real, 1, ',', '.') }}%</td>
                            <td class="text-end {{ $desvio->percentual_desvio < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($desvio->percentual_desvio, 1, ',', '.') }}%
                            </td>
                            <td class="text-end {{ $desvio->percentual_impacto < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($desvio->percentual_impacto, 1, ',', '.') }}%
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- ------------------------------------------------------------------ --}}
            {{-- Diagnóstico do Desvio (Fase 5) — Causas (Etapa B) + Impacto de       --}}
            {{-- Restrições (Etapa C2). HH Expostas (Etapa C1) saiu daqui na          --}}
            {{-- Consolidação e virou linha secundária do KPI "Atrasadas" acima       --}}
            {{-- (mesmo computed hhExpostaPorAtraso(), sem alteração).                --}}
            {{-- ------------------------------------------------------------------ --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bx bx-search-alt me-1"></i>Diagnóstico do Desvio</h6>
                </div>
                <div class="card-body">
                    <h6 class="mb-2"><i class="bx bx-search-alt me-1"></i>Causas Associadas ao Desvio</h6>
                    <div class="mb-3">
                        @foreach($curva->desvios as $desvio)
                        @php $causasLinha = $this->causasDoDesvio[$desvio->id] ?? null; @endphp
                        @if ($causasLinha && $causasLinha['total_atividades'] > 0)
                        <div class="border rounded p-3 mb-2">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <strong>{{ $desvio->titulo_exibicao }}</strong>
                                <small class="text-muted">
                                    {{ $causasLinha['atividades_com_causa'] }} de {{ $causasLinha['total_atividades'] }} atividade(s) com causa registrada
                                    @if ($causasLinha['atividades_sem_causa'] > 0)
                                        · {{ $causasLinha['atividades_sem_causa'] }} sem causa registrada
                                    @endif
                                </small>
                            </div>

                            @if (empty($causasLinha['causas']))
                            <p class="text-muted small mb-0">Nenhuma causa declarada associada a este pacote até o momento.</p>
                            @else
                            <ul class="list-unstyled small mb-0">
                                @foreach($causasLinha['causas'] as $causa)
                                <li class="mb-1">
                                    <span class="badge bg-label-secondary">{{ $causa['atividade_codigo'] ?? '—' }}</span>
                                    {{ $causa['atividade_nome'] }} — evidência encontrada: <em>"{{ $causa['descricao'] }}"</em>
                                    <span class="text-muted">(causa declarada em {{ $causa['registrada_em']->format('d/m/Y') }})</span>
                                </li>
                                @endforeach
                            </ul>
                            @endif
                        </div>
                        @endif
                        @endforeach
                    </div>

                    {{--
                        Impacto de Restrições (Fase 5, Etapa C2) — lê o
                        snapshot já persistido (ver impactoRestricoes() no
                        componente), nunca consulta Restricao ao vivo aqui.
                        Mesmo padrão visual de "Causas Associadas ao
                        Desvio" acima (sub-seção dentro do MESMO card,
                        nunca um card grande independente). Bloco inteiro
                        (heading incluso) só aparece quando há pelo menos
                        uma restrição aberta em alguma linha desta curva —
                        nunca um cabeçalho vazio. Linguagem estritamente
                        factual: nunca afirma que a restrição causou o
                        desvio, só que está associada ao mesmo escopo.
                    --}}
                    @php
                        $temImpactoNestaCurva = $curva->desvios->contains(function ($d) {
                            $l = $this->impactoRestricoes[$d->id] ?? null;
                            return $l && $l['total_abertas'] > 0;
                        });
                    @endphp
                    @if($temImpactoNestaCurva)
                    <hr class="my-3">
                    <h6 class="mb-2"><i class="bx bx-shield-quarter me-1"></i>Impacto de Restrições</h6>
                    <div class="mb-0">
                        @foreach($curva->desvios as $desvio)
                        @php $impactoLinha = $this->impactoRestricoes[$desvio->id] ?? null; @endphp
                        @if ($impactoLinha && $impactoLinha['total_abertas'] > 0)
                        <div class="border rounded p-3 mb-2">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <strong>{{ $desvio->titulo_exibicao }}</strong>
                                <div class="d-flex gap-1 flex-wrap">
                                    <span class="badge bg-label-secondary">{{ $impactoLinha['total_abertas'] }} aberta(s)</span>
                                    @if($impactoLinha['total_vencidas'] > 0)
                                    <span class="badge bg-danger">{{ $impactoLinha['total_vencidas'] }} vencida(s)</span>
                                    @endif
                                    @if($impactoLinha['total_criticas'] > 0)
                                    <span class="badge bg-warning">{{ $impactoLinha['total_criticas'] }} crítica(s)</span>
                                    @endif
                                </div>
                            </div>

                            <ul class="list-unstyled small mb-0">
                                @foreach(array_slice($impactoLinha['detalhes'], 0, 3) as $r)
                                <li class="mb-1 d-flex align-items-start gap-1">
                                    @if($r['vencida'])
                                    <i class="bx bx-error-circle text-danger mt-1 flex-shrink-0" data-bs-toggle="tooltip" title="Vencida"></i>
                                    @elseif($r['classificacao_risco'] === 'alto')
                                    <i class="bx bx-error text-warning mt-1 flex-shrink-0" data-bs-toggle="tooltip" title="Risco alto (probabilidade × impacto ≥ 50)"></i>
                                    @endif
                                    <span>
                                        {{ \Illuminate\Support\Str::limit($r['descricao'], 80) }}
                                        <span class="text-muted">
                                            — {{ $r['status'] }}
                                            @if($r['responsavel_nome']) · {{ $r['responsavel_nome'] }} @endif
                                            @if($r['prazo_limite']) · prazo {{ \Illuminate\Support\Carbon::parse($r['prazo_limite'])->format('d/m/Y') }} @endif
                                            @if($r['categoria_nome']) · {{ $r['categoria_nome'] }} @endif
                                        </span>
                                    </span>
                                </li>
                                @endforeach
                            </ul>
                            @if(count($impactoLinha['detalhes']) > 3)
                            <small class="text-muted">+{{ count($impactoLinha['detalhes']) - 3 }} restrição(ões) adicional(is) associada(s) a este escopo.</small>
                            @endif
                        </div>
                        @endif
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>

            {{-- Gráfico mensal (tudo em %) — sempre visível. Tabela detalhada  --}}
            {{-- (Fase 5, Consolidação) atrás de "Ver dados", via toggle Alpine --}}
            {{-- (não data-bs-toggle="collapse" — dessincroniza depois de       --}}
            {{-- qualquer morph do Livewire na página, bug já documentado nos  --}}
            {{-- UX Fixes do Health Check). Nenhum dado/cálculo alterado.       --}}
            <h6 class="mb-2">Curva S — Mensal</h6>
            <div wire:ignore class="mb-3" style="height: 260px;">
                <canvas id="chart-mensal-{{ $curva->id }}"></canvas>
            </div>
            <div x-data="{ verDados: false }" class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="verDados = !verDados">
                    <i class="bx" :class="verDados ? 'bx-chevron-up' : 'bx-chevron-down'"></i>
                    <span x-text="verDados ? 'Ocultar dados' : 'Ver dados'"></span>
                </button>
                <div x-show="verDados" x-transition x-cloak class="mt-2">
                    @include('pages.radar._partials.relatorio-tabela-curva', ['tabela' => $dados['mensal']['tabela']])
                </div>
            </div>

            {{-- Gráfico semanal (tudo em %) — sempre visível. Tabela detalhada --}}
            {{-- (com a coluna de Aderência, já colorida pelos mesmos limiares  --}}
            {{-- do antigo velocímetro) atrás de "Ver dados", mesmo mecanismo   --}}
            {{-- acima. --}}
            <h6 class="mb-2 mt-4">Curva S — Semanal (últimas 4 semanas)</h6>
            <div wire:ignore class="mb-3" style="height: 260px;">
                <canvas id="chart-semanal-{{ $curva->id }}"></canvas>
            </div>
            <div x-data="{ verDados: false }" class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="verDados = !verDados">
                    <i class="bx" :class="verDados ? 'bx-chevron-up' : 'bx-chevron-down'"></i>
                    <span x-text="verDados ? 'Ocultar dados' : 'Ver dados'"></span>
                </button>
                <div x-show="verDados" x-transition x-cloak class="mt-2">
                    @include('pages.radar._partials.relatorio-tabela-curva', [
                        'tabela' => $dados['semanal']['tabela'],
                        'comAderencia' => true,
                        'limiaresAderencia' => $this->limiaresAderencia(),
                    ])
                </div>
            </div>

            {{--
                Velocímetro de Aderência (Fase 5, Consolidação) — REMOVIDO.
                Investigação confirmou que a única informação a mais que o
                gauge tinha (zonas de cor vermelho/amarelo/verde pelos mesmos
                limiares ADERENCIA_LIMIAR_ATENCAO/_OTIMO) agora está na
                própria célula "Aderência" da tabela semanal acima (ver
                relatorio-tabela-curva.blade.php) — decisão confirmada
                explicitamente com o usuário antes de remover. O valor
                numérico (última semana atualizada) nunca deixou de existir:
                é a mesma coluna Aderência, agora colorida.
            --}}

            {{-- Pontos de atenção --}}
            <h6 class="mb-2"><i class="bx bx-error-circle me-1"></i>Pontos de Atenção</h6>
            @can('update', $report)
            @foreach($pontosAtencaoEdit[$curva->id] ?? [] as $j => $ponto)
            <div class="row g-2 mb-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm"
                           wire:model="pontosAtencaoEdit.{{ $curva->id }}.{{ $j }}.categoria"
                           placeholder="Categoria (opcional)">
                </div>
                <div class="col-md-8">
                    <input type="text" class="form-control form-control-sm"
                           wire:model="pontosAtencaoEdit.{{ $curva->id }}.{{ $j }}.texto"
                           placeholder="Descreva o ponto de atenção...">
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger py-0"
                            wire:click="removerPontoAtencaoEdit('{{ $curva->id }}', {{ $j }})">
                        <i class="bx bx-x"></i>
                    </button>
                </div>
            </div>
            @endforeach
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="adicionarPontoAtencaoEdit('{{ $curva->id }}')">
                    <i class="bx bx-plus me-1"></i>Adicionar
                </button>
                <button type="button" class="btn btn-sm btn-primary" wire:click="salvarPontosAtencao('{{ $curva->id }}')">
                    <i class="bx bx-save me-1"></i>Salvar pontos de atenção
                </button>
            </div>
            @else
            @if($curva->pontosAtencao->isEmpty())
            <p class="text-muted small mb-0">Nenhum ponto de atenção registrado para esta curva.</p>
            @else
            <ul class="mb-0">
                @foreach($curva->pontosAtencao as $ponto)
                <li>
                    @if($ponto->categoria)
                    <span class="badge bg-label-warning me-1">{{ $ponto->categoria }}</span>
                    @endif
                    {{ $ponto->texto }}
                </li>
                @endforeach
            </ul>
            @endif
            @endcan
        </div>
    </div>
    @endforeach

    {{-- ------------------------------------------------------------------ --}}
    {{-- Desempenho da Semana Anterior (Restrições/Engenharia/Suprimentos) --}}
    {{-- Fase 5: reposicionado para depois do loop de curvas (era antes)   --}}
    {{-- — conteúdo/computed/query intactos, só posição na página mudou.   --}}
    {{-- ------------------------------------------------------------------ --}}
    <h6 class="mb-2"><i class="bx bx-history me-1"></i>Desempenho da Semana Anterior</h6>
    <div class="row mb-2">
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Restrições',
            'icone' => 'bx-shield-quarter',
            'indicador' => $this->indicadoresSemanaAnterior['restricoes'] ?? null,
            'colunas' => ['titulo' => 'Restrição', 'responsavel_nome' => 'Responsável', 'status' => 'Status', 'prazo_limite' => 'Prazo'],
            'colunasData' => ['prazo_limite'],
            'mensagemVazia' => 'Nenhuma restrição prevista para a semana anterior.',
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Engenharia',
            'icone' => 'bx-drafting-compass',
            'indicador' => $this->indicadoresSemanaAnterior['engenharia'] ?? null,
            'colunas' => ['codigo' => 'Código', 'descricao' => 'Descrição', 'status' => 'Status', 'data_planejada' => 'Previsto'],
            'colunasData' => ['data_planejada'],
            'mensagemVazia' => 'Nenhum documento de engenharia previsto para a semana anterior.',
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Suprimentos',
            'icone' => 'bx-package',
            'indicador' => $this->indicadoresSemanaAnterior['suprimentos'] ?? null,
            'colunas' => ['nome' => 'Item', 'fornecedor_nome' => 'Fornecedor', 'status' => 'Status', 'data_prevista' => 'Previsto'],
            'colunasData' => ['data_prevista'],
            'mensagemVazia' => 'Nenhum item de suprimento previsto para a semana anterior.',
        ])
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Planejamento da Próxima Semana (Restrições/Engenharia/Suprimentos) --}}
    {{-- ------------------------------------------------------------------ --}}
    <h6 class="mb-2"><i class="bx bx-calendar-plus me-1"></i>Planejamento da Próxima Semana</h6>
    <div class="row mb-4">
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Restrições',
            'icone' => 'bx-shield-quarter',
            'indicador' => $this->indicadoresSemanaProxima['restricoes'] ?? null,
            'colunas' => ['titulo' => 'Restrição', 'responsavel_nome' => 'Responsável', 'status' => 'Status', 'prazo_limite' => 'Prazo'],
            'colunasData' => ['prazo_limite'],
            'mensagemVazia' => 'Nenhuma restrição prevista para a próxima semana.',
            'mostrarConcluido' => false,
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Engenharia',
            'icone' => 'bx-drafting-compass',
            'indicador' => $this->indicadoresSemanaProxima['engenharia'] ?? null,
            'colunas' => ['codigo' => 'Código', 'descricao' => 'Descrição', 'disciplina_nome' => 'Disciplina', 'data_planejada' => 'Previsto'],
            'colunasData' => ['data_planejada'],
            'mensagemVazia' => 'Nenhum documento de engenharia previsto para a próxima semana.',
            'mostrarConcluido' => false,
        ])
        @include('pages.radar._partials.relatorio-indicadores-semana', [
            'titulo' => 'Suprimentos',
            'icone' => 'bx-package',
            'indicador' => $this->indicadoresSemanaProxima['suprimentos'] ?? null,
            'colunas' => ['nome' => 'Item', 'fornecedor_nome' => 'Fornecedor', 'status' => 'Status', 'data_prevista' => 'Previsto'],
            'colunasData' => ['data_prevista'],
            'mensagemVazia' => 'Nenhum item de suprimento previsto para a próxima semana.',
            'mostrarConcluido' => false,
        ])
    </div>

    {{--
        Decisões Prioritárias (Fase 5, Etapa F) — lê decisoesPrioritarias()
        (leitura em tempo real sobre o MESMO snapshot já persistido pela
        Etapa C2, SEM nenhuma consulta a Restricao ao vivo, SEM nenhuma
        persistência nova). Bloco inteiro só aparece quando há pelo menos
        1 situação elegível — nunca um cabeçalho vazio. Enquadramento
        deliberadamente diferente de "Top Riscos da Obra": aqui o
        sistema NUNCA afirma qual decisão deve ser tomada — só lista
        situações (vencidas, bloqueantes, de risco alto, ou com prazo na
        próxima semana) que exigem atenção/decisão gerencial.
    --}}
    @php $decisoesPrioritarias = $this->decisoesPrioritarias; @endphp
    @if(count($decisoesPrioritarias) > 0)
    <div class="mb-4">
        <h6 class="mb-1"><i class="bx bx-flag text-warning me-1"></i>Decisões Prioritárias</h6>
        <p class="text-muted small mb-2">Situações que exigem atenção gerencial</p>
        <div class="row g-2">
            @foreach($decisoesPrioritarias as $situacao)
            <div class="col-md-4">
                <div class="border rounded p-2 h-100">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                        <span class="badge bg-label-secondary">{{ $situacao['pacote_titulo'] }}</span>
                        <div class="d-flex gap-1 flex-wrap">
                            @if($situacao['vencida'])
                            <span class="badge bg-danger">Vencida</span>
                            @endif
                            @if($situacao['bloqueante'])
                            <span class="badge bg-dark">Bloqueante</span>
                            @endif
                            @if($situacao['classificacao_risco'] === 'alto')
                            <span class="badge bg-warning">Risco crítico</span>
                            @endif
                        </div>
                    </div>
                    <p class="small mb-1">{{ \Illuminate\Support\Str::limit($situacao['descricao'], 90) }}</p>
                    <small class="text-muted d-block">
                        {{ $situacao['status'] }}
                        @if($situacao['responsavel_nome']) · {{ $situacao['responsavel_nome'] }} @endif
                        @if($situacao['prazo_limite']) · prazo {{ \Illuminate\Support\Carbon::parse($situacao['prazo_limite'])->format('d/m/Y') }} @endif
                    </small>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Galeria de fotos --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bx bx-images me-1"></i>Relatório Fotográfico</h6>
        </div>
        <div class="card-body">
            @can('update', $report)
            <div class="mb-3">
                <label class="form-label small">Adicionar fotos</label>
                <input type="file" class="form-control @error('novasFotosUpload.*') is-invalid @enderror"
                       wire:model="novasFotosUpload" multiple accept="image/*">
                @error('novasFotosUpload.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div wire:loading wire:target="novasFotosUpload" class="small text-muted mt-1">Enviando...</div>
            </div>

            @if(!empty($novasFotosUpload))
            <div class="row g-3 mb-2">
                @foreach($novasFotosUpload as $i => $foto)
                <div class="col-md-3">
                    <div class="card h-100">
                        <img src="{{ $foto->temporaryUrl() }}" class="card-img-top" style="height:120px; object-fit:cover">
                        <div class="card-body p-2">
                            <input type="text" class="form-control form-control-sm"
                                   wire:model="legendasNovasFotosUpload.{{ $i }}" placeholder="Legenda...">
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-primary mb-3" wire:click="enviarFotos">
                <i class="bx bx-upload me-1"></i>Enviar fotos
            </button>
            @endif
            @endcan

            @if($report->fotos->isEmpty())
            <p class="text-muted small mb-0">Nenhuma foto anexada a este report.</p>
            @else
            <div class="row g-3">
                @foreach($report->fotos as $foto)
                <div class="col-md-3">
                    <div class="card h-100">
                        @if($foto->url)
                        <img src="{{ $foto->url }}" class="card-img-top" style="height:160px; object-fit:cover; cursor: zoom-in;"
                             onclick="window.abrirFotoAmpliada('{{ $foto->url }}', @js($foto->legenda))">
                        @endif
                        @can('update', $report)
                        <div class="card-body p-2">
                            <input type="text" class="form-control form-control-sm mb-1"
                                   wire:model="legendasFotosEdit.{{ $foto->id }}" placeholder="Legenda...">
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary flex-fill py-0"
                                        wire:click="salvarLegendaFoto('{{ $foto->id }}')">Salvar</button>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0"
                                        onclick="confirmarAcao(this, {
                                            mensagem: 'Remover esta foto?',
                                            metodo: 'removerFoto',
                                            args: ['{{ $foto->id }}'],
                                            icone: 'bx-trash',
                                        })">
                                    <i class="bx bx-trash"></i>
                                </button>
                            </div>
                        </div>
                        @elseif($foto->legenda)
                        <div class="card-body p-2">
                            <small class="text-muted">{{ $foto->legenda }}</small>
                        </div>
                        @endcan
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- Modal: Confirmar emissão --}}
    <div wire:ignore.self class="modal fade" id="emitirReportModal" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-semibold">
                        <i class="bx bx-send me-2"></i>Emitir Report
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="mb-3">Este report deixará de ser rascunho e passará a ser visível (e comentável) por todos com acesso à obra.</p>
                    <div class="alert alert-warning d-flex align-items-center mb-0">
                        <i class="bx bx-info-circle me-2 flex-shrink-0"></i>
                        <span>Depois de emitido, o report vira <strong>somente-leitura</strong> para o planejamento.</span>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success shadow-none" wire:click="emitir">
                        <span wire:loading.remove wire:target="emitir"><i class="bx bx-send me-1"></i>Emitir Report</span>
                        <span wire:loading wire:target="emitir"><i class="bx bx-loader-alt bx-spin me-1"></i>Emitindo...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal de ampliação de foto (reaproveitado por todas as miniaturas) --}}
    <div class="modal fade" id="modal-foto-ampliada" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center pt-0">
                    <img id="modal-foto-ampliada-img" src="" class="img-fluid rounded" style="max-height: 75vh;">
                    <p id="modal-foto-ampliada-legenda" class="text-white-50 mt-2 mb-0"></p>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Comentários (só após emissão) --}}
    {{-- ------------------------------------------------------------------ --}}
    @can('comentar', $report)
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bx bx-comment-detail me-1"></i>Comentários</h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <textarea class="form-control @error('novoComentario') is-invalid @enderror" rows="2"
                          wire:model="novoComentario" placeholder="Escreva um comentário..."></textarea>
                @error('novoComentario')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button class="btn btn-sm btn-primary mt-2" wire:click="adicionarComentario">
                    <i class="bx bx-send me-1"></i>Comentar
                </button>
            </div>

            @forelse($report->comentarios as $comentario)
            <div class="d-flex gap-2 mb-3">
                <i class="bx bx-user-circle fs-4 text-muted"></i>
                <div>
                    <div class="small">
                        <strong>{{ $comentario->autor?->first_name }} {{ $comentario->autor?->last_name }}</strong>
                        <span class="text-muted ms-1">{{ $comentario->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                    <div>{{ $comentario->comentario }}</div>
                </div>
            </div>
            @empty
            <p class="text-muted small mb-0">Nenhum comentário ainda.</p>
            @endforelse
        </div>
    </div>
    @elseif($report->estaRascunho())
    <div class="alert alert-light border small text-muted">
        <i class="bx bx-info-circle me-1"></i>Comentários ficam disponíveis depois que o report é emitido.
    </div>
    @endcan

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

    $wire.on('hide-emitir-modal', () => {
        const el = document.getElementById('emitirReportModal');
        if (el) {
            bootstrap.Modal.getInstance(el)?.hide();
            setTimeout(() => {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            }, 150);
        }
    });

    const curvasGrafico = @json($this->dadosGraficos);
    const cfg = window.RelatorioGraficoConfig;

    curvasGrafico.forEach((curva) => {
        ['mensal', 'semanal'].forEach((gran) => {
            const canvas = document.getElementById(`chart-${gran}-${curva.id}`);
            if (!canvas || !curva[gran].labels.length) return;

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: curva[gran].labels,
                    datasets: cfg.construirDatasets(curva[gran].barras, curva[gran].linhas),
                },
                options: cfg.opcoesDuploEixo(),
            });
        });
        // Velocímetro de Aderência removido nesta etapa (Consolidação) — a
        // mesma informação (última semana atualizada, com zona de cor) já
        // está na coluna Aderência da tabela semanal (ver
        // relatorio-tabela-curva.blade.php). gaugeConfig/pluginAgulha/
        // pluginTextoCentral continuam em relatorio-grafico-config.blade.php
        // sem alteração — ainda usados pela prévia do assistente
        // (⚡relatorio-novo.blade.php).
    });
</script>
@endscript

<script>
    // Amplia uma foto do relatório fotográfico no modal compartilhado —
    // função global (não depende do Livewire) porque é só apresentação,
    // sem estado de servidor envolvido.
    window.abrirFotoAmpliada = function (url, legenda) {
        document.getElementById('modal-foto-ampliada-img').src = url;
        document.getElementById('modal-foto-ampliada-legenda').textContent = legenda || '';
        new bootstrap.Modal(document.getElementById('modal-foto-ampliada')).show();
    };
</script>
