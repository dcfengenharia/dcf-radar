<?php

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Enums\StatusPlanoAcao;
use App\Enums\TipoCronogramaImportacao;
use App\Exceptions\PlanoAcaoDuplicadoException;
use App\Models\CronogramaImportacao;
use App\Models\PlanoAcao;
use App\Models\User;
use App\Support\HealthCheck\AplicabilidadeCategoria;
use App\Support\HealthCheck\HealthCheckEngine;
use App\Support\HealthCheck\PlanoAcao\SobreposicaoUid;
use App\Support\HealthCheck\PlanoAcao\UidExtractor;
use App\Support\HealthCheck\Score\FaixaScore;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Detalhe de uma CronogramaImportacao — fotografia auditável daquela
 * importação específica (Fase 3, Etapa 5 + Etapa 3.1 — evolução/comparação).
 * Nunca recalcula Health Check nem Score: só lê o que já está persistido em
 * $importacao->healthCheck->resultado()/scoreResultado() (ver CLAUDE.md). A
 * "importação anterior" (Etapa 3.1) é resolvida com UMA única query
 * (CronogramaImportacao::importacaoAnterior(), mesma obra + mesmo tipo) — a
 * comparação de findings é feita 100% em memória sobre os dois JSONs já
 * carregados, nunca reexecuta nenhuma regra.
 */
new class extends Component {
    public CronogramaImportacao $importacao;

    public ?CronogramaImportacao $anterior = null;

    /** Categoria selecionada pra filtrar as Ocorrências — null = mostrar todas (Etapa 3.1, item 4). */
    public ?string $categoriaFiltro = null;

    /** findingIndex do item do Mapa de Ações sendo transformado em Plano de Ação (Fase 4.2) — null = modal fechado. */
    public ?int $indiceParaCriarAcao = null;

    public ?string $novaAcaoResponsavelId = null;

    public ?string $novaAcaoPrazo = null;

    /** Mensagem amigável (duplicação/validação) exibida dentro do modal — nunca uma exceção crua. */
    public ?string $novaAcaoErro = null;

    public function mount(CronogramaImportacao $importacao): void
    {
        $this->authorize('view', $importacao);

        $this->importacao = $importacao->load(['obra', 'autor', 'healthCheck']);
        $this->anterior = $this->importacao->importacaoAnterior();
    }

    public function filtrarCategoria(?string $categoria): void
    {
        $this->categoriaFiltro = $categoria;
    }

    /** Findings da importação atual, na mesma ordem persistida (== ordem que AcaoRecomendada::$findingIndex referencia). */
    private function findingOriginal(int $findingIndex): mixed
    {
        return $this->importacao->healthCheck->resultado()->findings[$findingIndex] ?? null;
    }

    public function abrirModalCriarAcao(int $findingIndex): void
    {
        $this->indiceParaCriarAcao = $findingIndex;
        $this->novaAcaoResponsavelId = null;
        $this->novaAcaoPrazo = null;
        $this->novaAcaoErro = null;
    }

    public function fecharModalCriarAcao(): void
    {
        $this->indiceParaCriarAcao = null;
    }

    /**
     * Cria o PlanoAcao a partir do finding original (resolvido via
     * findingIndex — Fase 4.2, ponto crítico do diagnóstico: sem ele, 2+
     * ocorrências da mesma regra_id seriam indistinguíveis no Mapa de Ações).
     * Duplicação controlada e validação de responsável já vivem no domínio
     * (PlanoAcao::criarDeFinding()) — aqui só traduz as exceções em mensagem
     * amigável, nunca deixa uma exception crua chegar na tela.
     */
    public function confirmarCriarAcao(): void
    {
        $this->authorize('create', [PlanoAcao::class, $this->importacao->obra_id]);

        $this->validate([
            'novaAcaoResponsavelId' => 'nullable|exists:users,id',
            'novaAcaoPrazo' => 'nullable|date',
        ]);

        $finding = $this->findingOriginal($this->indiceParaCriarAcao ?? -1);

        if ($finding === null) {
            $this->novaAcaoErro = 'Não foi possível localizar esta ocorrência — recarregue a página e tente novamente.';

            return;
        }

        $responsavel = $this->novaAcaoResponsavelId ? User::find($this->novaAcaoResponsavelId) : null;

        try {
            PlanoAcao::criarDeFinding($finding, $this->importacao, $this->importacao->obra_id, $responsavel, $this->novaAcaoPrazo);
        } catch (PlanoAcaoDuplicadoException $e) {
            $this->novaAcaoErro = 'Já existe uma ação aberta para este problema (criada em '
                . $e->acaoExistente->created_at->format('d/m/Y') . ').';

            return;
        } catch (\InvalidArgumentException $e) {
            $this->novaAcaoErro = $e->getMessage();

            return;
        }

        $this->indiceParaCriarAcao = null;
        $this->dispatch('show-toast', message: 'Ação criada com sucesso.');
    }

    /** Usuários com acesso à obra desta importação — mesma fonte já usada em Restrições pro seletor de responsável. */
    public function usuariosDaObra()
    {
        return $this->importacao->obra->users()->orderBy('users.first_name')->get(['users.id', 'users.first_name', 'users.last_name']);
    }

    /**
     * TODAS as ações Abertas da obra, buscadas UMA ÚNICA VEZ (Fase 4.3,
     * Etapa E — corrige N+1: antes, `contarAcoesAbertasParaFinding()`
     * rodava uma query nova por finding exibido no Mapa de Ações; agora é
     * 1 query fixa pra página inteira, e a contagem por finding é feita em
     * memória sobre esta MESMA coleção). `#[Computed]` cacheia o resultado
     * dentro do mesmo request — chamado várias vezes no `@forelse` do Mapa
     * de Ações sem custo extra.
     */
    #[Computed]
    public function acoesAbertasDaObra()
    {
        return PlanoAcao::where('obra_id', $this->importacao->obra_id)
            ->where('status', StatusPlanoAcao::Aberta->value)
            ->get();
    }

    /**
     * Estado de aplicabilidade (Avaliada/Parcialmente avaliada/Não avaliada)
     * de cada categoria do Health Check nesta importação específica — Ciclo
     * 10 ("Transparência Baseline"). Puramente derivado do tipo desta
     * importação + catálogo de regras do Engine, nunca recalcula nenhuma
     * regra, nunca lê o Score. Ver App\Support\HealthCheck\AplicabilidadeCategoria.
     */
    #[Computed]
    public function aplicabilidadePorCategoria()
    {
        return AplicabilidadeCategoria::calcularTodas(
            app(HealthCheckEngine::class),
            $this->importacao->tipo
        );
    }

    /**
     * Quantas ações Abertas do Plano de Ação têm origem numa regra de
     * Execução — só tem sentido mostrar quando esta importação é Baseline
     * (regras de Execução nunca são avaliadas/reconciliadas nesse caso,
     * ver PlanoAcaoReconciliador). Reaproveita $this->acoesAbertasDaObra,
     * já carregada pro Mapa de Ações — nenhuma query nova. Puramente
     * informativo: não altera, resolve nem reabre nenhuma ação.
     */
    #[Computed]
    public function acoesExecucaoAguardandoAvanco()
    {
        if ($this->importacao->tipo !== TipoCronogramaImportacao::Baseline) {
            return 0;
        }

        $engine = app(HealthCheckEngine::class);

        return $this->acoesAbertasDaObra
            ->filter(fn (PlanoAcao $acao) => $engine->naturezaDaRegra($acao->regra_id) === HealthCheckNaturezaRegra::Execucao)
            ->count();
    }

    /** Quantas ações Abertas já existem pra esta ocorrência específica (mesmo critério de sobreposição do reconciliador, agora em memória — sem query nova). */
    public function contarAcoesAbertasParaFinding(int $findingIndex): int
    {
        $finding = $this->findingOriginal($findingIndex);

        if ($finding === null) {
            return 0;
        }

        $uids = UidExtractor::extrair($finding->atividades);

        return $this->acoesAbertasDaObra
            ->filter(fn (PlanoAcao $acao) => $acao->regra_id === $finding->regraId
                && SobreposicaoUid::temSobreposicao($uids, $acao->uids_referencia))
            ->count();
    }

    /** @return array<int, array{regra_id: string, titulo: string, anterior: int, atual: int, melhorou: bool}> */
    public function compararFindings(): array
    {
        if (! $this->anterior?->healthCheck) {
            return [];
        }

        $mapaAtual = $this->contarPorRegra($this->importacao->healthCheck->findings ?? []);
        $mapaAnterior = $this->contarPorRegra($this->anterior->healthCheck->findings ?? []);

        $regraIds = array_unique([...array_keys($mapaAtual), ...array_keys($mapaAnterior)]);

        $comparacao = [];
        foreach ($regraIds as $regraId) {
            $qtdAnterior = $mapaAnterior[$regraId]['quantidade'] ?? 0;
            $qtdAtual = $mapaAtual[$regraId]['quantidade'] ?? 0;

            if ($qtdAnterior === $qtdAtual) {
                continue;
            }

            $comparacao[] = [
                'regra_id' => $regraId,
                'titulo' => $mapaAtual[$regraId]['titulo'] ?? $mapaAnterior[$regraId]['titulo'] ?? $regraId,
                'anterior' => $qtdAnterior,
                'atual' => $qtdAtual,
                'melhorou' => $qtdAtual < $qtdAnterior,
            ];
        }

        usort($comparacao, fn (array $a, array $b) => $a['regra_id'] <=> $b['regra_id']);

        return $comparacao;
    }

    /** @return array<string, array{quantidade: int, titulo: string}> chave = regra_id */
    private function contarPorRegra(array $findings): array
    {
        $mapa = [];

        foreach ($findings as $finding) {
            $regraId = $finding['regra_id'];
            $mapa[$regraId]['quantidade'] = ($mapa[$regraId]['quantidade'] ?? 0) + count($finding['atividades']);
            $mapa[$regraId]['titulo'] = $finding['titulo'];
        }

        return $mapa;
    }

    /** Findings da importação atual, filtrados pela categoria selecionada (ou todos, se nenhuma). */
    public function findingsFiltrados(): array
    {
        $findings = $this->importacao->healthCheck->findings ?? [];

        if ($this->categoriaFiltro === null) {
            return $findings;
        }

        return array_values(array_filter($findings, fn (array $f) => $f['categoria'] === $this->categoriaFiltro));
    }
};
?>

<div>
    {{-- =====================================================================
         CABEÇALHO — identificação inequívoca de qual fotografia é esta
         ===================================================================== --}}
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Cronograma importado</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">Arquivo</small>
                    <span class="fw-medium">{{ $importacao->arquivo ?? '—' }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Obra</small>
                    <span class="fw-medium">{{ $importacao->obra->name }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Usuário</small>
                    <span class="fw-medium">{{ $importacao->autor ? "{$importacao->autor->first_name} {$importacao->autor->last_name}" : '—' }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Data/hora da importação</small>
                    <span class="fw-medium">{{ $importacao->importado_em->format('d/m/Y H:i') }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Tipo</small>
                    <span class="fw-medium">{{ $importacao->tipo?->label() ?? '—' }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Data de status</small>
                    <span class="fw-medium">{{ $importacao->data_status?->format('d/m/Y') ?? '—' }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Criadas</small>
                    <span class="fw-medium">{{ $importacao->criadas }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Atualizadas</small>
                    <span class="fw-medium">{{ $importacao->atualizadas }}</span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Removidas</small>
                    <span class="fw-medium">{{ $importacao->removidas }}</span>
                </div>
            </div>
        </div>
    </div>

    @if (! $importacao->healthCheck)
    {{-- =====================================================================
         SEM HEALTH CHECK — importação de antes da Fase 1 do Health Check
         ===================================================================== --}}
    <div class="alert alert-secondary d-flex align-items-center gap-2 mb-0">
        <i class="bx bx-info-circle fs-4"></i>
        <span>Esta importação não possui uma análise de saúde registrada.</span>
    </div>
    @else
    @php
        $healthCheck = $importacao->healthCheck;
        $scoreResultado = $healthCheck->scoreResultado();
    @endphp

    {{-- =====================================================================
         SCORE DE SAÚDE
         ===================================================================== --}}
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Saúde do Cronograma</h5>

            @if (! $scoreResultado)
            <div class="alert alert-secondary d-flex align-items-center gap-2 mb-0">
                <i class="bx bx-info-circle fs-4"></i>
                <span>Análise disponível — Score não disponível (esta importação é anterior à implementação do Score de Saúde).</span>
            </div>
            @else
            <div class="row g-4 align-items-center mb-3">
                <div class="col-md-3 text-center">
                    <div class="display-4 fw-bold">{{ $scoreResultado->score }}<small class="fs-6 text-muted">/100</small></div>
                    <span class="badge bg-{{ $scoreResultado->faixa->cor() }}">{{ $scoreResultado->faixa->label() }}</span>
                </div>
                <div class="col-md-3 text-center">
                    <small class="text-muted d-block" title="Mede quantas atividades executáveis estão ativas — não mede ausência de problemas no cronograma">Cobertura da análise</small>
                    <span class="fs-4 fw-medium">{{ $scoreResultado->cobertura !== null ? $scoreResultado->cobertura . '%' : 'N/D' }}</span>
                </div>
                <div class="col-md-6">
                    @if (count($scoreResultado->mapaAcoes) > 0)
                    <div class="alert alert-{{ $scoreResultado->faixa->cor() }} mb-0">
                        Seu cronograma pode recuperar até <strong>{{ $scoreResultado->potencialRecuperavel }} pontos</strong>.
                        Comece pelos problemas abaixo, priorizados pelo impacto no Score.
                    </div>
                    @else
                    <div class="alert alert-success mb-0">
                        Nenhum problema com impacto negativo no Score foi identificado nesta análise.
                    </div>
                    @endif
                </div>
            </div>

            @include('pages.radar._partials.score-explicacao')
            @endif
        </div>
    </div>

    @if ($scoreResultado)
    {{-- =====================================================================
         EVOLUÇÃO DO SCORE — compara com a importação anterior da MESMA obra
         e do MESMO tipo (Etapa 3.1). Nunca recalcula nada — só lê os dois
         Scores já persistidos.
         ===================================================================== --}}
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Evolução do Score</h5>

            @if (! $anterior)
            <p class="text-muted mb-0">Esta é a primeira importação deste tipo para esta obra.</p>
            @else
                @php $scoreAnterior = $anterior->healthCheck?->scoreResultado(); @endphp
                @if (! $scoreAnterior)
                <p class="text-muted mb-0">A importação anterior não possui Score disponível para comparação.</p>
                @else
                    @php $delta = $scoreResultado->score - $scoreAnterior->score; @endphp
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            @if ($delta > 0)
                                <span class="fs-4 fw-bold text-success"><i class="bx bx-up-arrow-alt"></i> +{{ $delta }} pontos</span>
                            @elseif ($delta < 0)
                                <span class="fs-4 fw-bold text-danger"><i class="bx bx-down-arrow-alt"></i> {{ $delta }} pontos</span>
                            @else
                                <span class="fs-4 fw-bold text-muted"><i class="bx bx-minus"></i> sem alteração</span>
                            @endif
                            <small class="text-muted d-block">desde a última importação</small>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Importação anterior</small>
                            <span class="fw-medium">{{ $scoreAnterior->score }} ({{ $scoreAnterior->faixa->label() }})</span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Situação</small>
                            <span class="fw-medium">
                                @if ($delta > 0)
                                    A saúde do cronograma melhorou.
                                @elseif ($delta < 0)
                                    A saúde do cronograma piorou.
                                @else
                                    A saúde do cronograma permaneceu igual.
                                @endif
                            </span>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
    @endif

    {{-- =====================================================================
         INDICADORES DA IMPORTAÇÃO — resumo consolidado, tudo lido do banco
         (Etapa 3.1, item 3). "Atividades analisadas" é uma leitura aritmética
         simples de dois valores já persistidos (cobertura% × atividades
         tocadas pela importação) — não é um recálculo do Health Check/Score,
         só uma conta de exibição. "Regras com ocorrências" conta os
         regra_id distintos já presentes no JSON de findings — não
         corresponde ao total de regras avaliadas (esse número não é
         persistido e não seria seguro derivar sem reexecutar o motor).
         ===================================================================== --}}
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Indicadores da Importação</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Quantidade de atividades</small>
                    <span class="fw-medium">{{ $importacao->criadas + $importacao->atualizadas }}</span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Atividades analisadas (estimado)</small>
                    <span class="fw-medium">
                        @if ($scoreResultado && $scoreResultado->cobertura !== null)
                            {{ round($scoreResultado->cobertura / 100 * ($importacao->criadas + $importacao->atualizadas)) }}
                        @else
                            N/D
                        @endif
                    </span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Cobertura</small>
                    <span class="fw-medium">{{ $scoreResultado && $scoreResultado->cobertura !== null ? $scoreResultado->cobertura . '%' : 'N/D' }}</span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Regras com ocorrências</small>
                    <span class="fw-medium">{{ count(array_unique(array_column($healthCheck->findings, 'regra_id'))) }}</span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Total de ocorrências</small>
                    <span class="fw-medium">{{ $healthCheck->total_ocorrencias }}</span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Score</small>
                    <span class="fw-medium">{{ $scoreResultado?->score ?? 'N/D' }}</span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Faixa</small>
                    <span class="fw-medium">{{ $scoreResultado?->faixa->label() ?? 'N/D' }}</span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Versão das regras / do Score</small>
                    <span class="fw-medium">{{ $healthCheck->versao_regras }} / {{ $healthCheck->versao_score ?? 'N/D' }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- =====================================================================
         O QUE MUDOU DESDE A ÚLTIMA IMPORTAÇÃO — comparação de findings já
         persistidos (Etapa 3.1, item 2). Nunca reexecuta nenhuma regra.
         ===================================================================== --}}
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">O que mudou desde a última importação</h5>

            @if (! $anterior || ! $anterior->healthCheck)
            <p class="text-muted mb-0">Primeira importação.</p>
            @else
                @php $comparacao = $this->compararFindings(); @endphp
                @if (empty($comparacao))
                <p class="text-muted mb-0">Nenhuma mudança nos achados desde a última importação.</p>
                @else
                <ul class="list-group list-group-flush">
                    @foreach ($comparacao as $item)
                    <li class="list-group-item d-flex align-items-center gap-2 px-0" wire:key="mudanca-{{ $item['regra_id'] }}">
                        @if ($item['melhorou'])
                            <i class="bx bx-check-circle text-success"></i>
                        @else
                            <i class="bx bx-error text-warning"></i>
                        @endif
                        <span class="fw-medium">{{ $item['titulo'] }}</span>
                        <span class="text-muted ms-auto">{{ $item['anterior'] }} → {{ $item['atual'] }}</span>
                    </li>
                    @endforeach
                </ul>
                @endif
            @endif
        </div>
    </div>

    @if ($scoreResultado)
    {{-- =====================================================================
         SCORE POR DIMENSÃO — dinâmico, derivado de HealthCheckCategoria::cases()
         ===================================================================== --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Score por Dimensão</h5>
                @if ($categoriaFiltro !== null)
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="filtrarCategoria(null)">
                    <i class="bx bx-x me-1"></i>Mostrar todas as categorias
                </button>
                @endif
            </div>
            <small class="text-muted d-block mb-3">Clique numa categoria para ver só as ocorrências dela na lista abaixo.</small>
            <div class="row g-3">
                @foreach (HealthCheckCategoria::cases() as $categoria)
                    @php
                        $dimensao = $scoreResultado->porDimensao[$categoria->value] ?? null;
                        $aplicabilidade = $this->aplicabilidadePorCategoria[$categoria->value] ?? null;
                    @endphp
                    @if ($dimensao)
                    <div class="col-md-4 col-lg-3">
                        <div class="border rounded p-3 h-100 {{ $categoriaFiltro === $categoria->value ? 'border-primary' : '' }}"
                             style="cursor:pointer"
                             wire:click="filtrarCategoria('{{ $categoria->value }}')"
                             title="Ver só as ocorrências de {{ $categoria->label() }}">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <span class="fw-semibold">{{ $categoria->label() }}</span>
                                <span class="badge bg-{{ \App\Support\HealthCheck\Score\FaixaScore::paraScore($dimensao->score)->cor() }}">{{ $dimensao->score }}</span>
                            </div>
                            <small class="text-muted d-block">{{ \App\Support\HealthCheck\Score\FaixaScore::paraScore($dimensao->score)->label() }}</small>
                            <small class="text-muted d-block mt-2">
                                {{ $dimensao->quantidadeOcorrencias }} ocorrência(s)
                                @if ($dimensao->severidadeMaxima)
                                    · máx. {{ $dimensao->severidadeMaxima->label() }}
                                @endif
                            </small>
                            {{-- Ciclo 10 — "Transparência Baseline": distingue avaliada-e-limpa
                                 de não-avaliada/parcialmente-avaliada, pra "Score 100 · 0
                                 ocorrências" nunca ser confundido com "esta categoria foi
                                 validada". Nunca aparece pra Avanço/Ambos, onde as 10
                                 categorias são sempre Avaliada (sem poluir a UI à toa). --}}
                            @if ($aplicabilidade && $aplicabilidade->estado->value !== 'avaliada')
                            <div class="mt-2 pt-2 border-top">
                                <span class="badge bg-label-{{ $aplicabilidade->estado->cor() }}">
                                    <i class="bx {{ $aplicabilidade->estado->icone() }}"></i>
                                    {{ $aplicabilidade->estado->label() }} — {{ $aplicabilidade->regrasAplicaveis }}/{{ $aplicabilidade->totalRegras }} regras
                                </span>
                                <small class="text-muted d-block mt-1">
                                    @if ($aplicabilidade->estado->value === 'nao_avaliada')
                                        As regras desta dimensão dependem de dados de execução, disponíveis apenas em Avanço/Ambos.
                                    @else
                                        {{ $aplicabilidade->regrasNaoAplicaveis() }} regra(s) de execução não se aplica(m) a esta importação.
                                    @endif
                                </small>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    {{-- =====================================================================
         MAPA DE AÇÕES
         ===================================================================== --}}
    <div class="card mb-4 border-primary">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-target-lock me-1"></i>Mapa de Ações</h5>

            {{-- Ciclo 10 — "Transparência Baseline", item 4 da investigação:
                 puramente informativo, nunca altera/resolve/reabre nenhuma
                 ação. Reaproveita $this->acoesAbertasDaObra (já carregada
                 pro Mapa de Ações) — nenhuma query nova. Só aparece em
                 Baseline, porque só em Baseline as regras de Execução não
                 são reconciliadas (ver PlanoAcaoReconciliador). --}}
            @if ($this->acoesExecucaoAguardandoAvanco > 0)
            <div class="alert alert-secondary d-flex align-items-center gap-2">
                <i class="bx bx-time-five fs-4"></i>
                <span>
                    Existem <strong>{{ $this->acoesExecucaoAguardandoAvanco }}</strong> ação(ões) de execução aberta(s)
                    aguardando uma importação de Avanço para nova avaliação — Baseline não avalia regras de execução.
                </span>
            </div>
            @endif

            @forelse ($scoreResultado->mapaAcoes as $acao)
            <div class="border rounded p-3 mb-2" wire:key="acao-{{ $loop->index }}">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-{{ $acao->severidade->cor() }}">{{ $acao->severidade->emoji() }} {{ $acao->severidade->label() }}</span>
                    <span class="fw-semibold">{{ $acao->regraId }} — {{ $acao->titulo }}</span>
                </div>
                <div class="text-muted small mb-2">
                    {{ $acao->quantidadeAtividades }} atividade(s) afetada(s) · Categoria: {{ $acao->categoria->label() }}
                    · Impacto: {{ number_format($acao->impacto, 1) }} pontos
                </div>
                <div class="mb-1"><strong>O que fazer:</strong> {{ $acao->recomendacao }}</div>
                <div class="text-success small mb-2">
                    <i class="bx bx-trending-up"></i>
                    Após corrigir esta ocorrência, espera-se recuperar aproximadamente
                    {{ number_format(abs($acao->impacto), 1) }} ponto(s) no Score e eliminar
                    {{ $acao->quantidadeAtividades }} ocorrência(s) de "{{ $acao->titulo }}" ({{ $acao->severidade->label() }}).
                </div>

                {{-- Fase 4.2 — Plano de Ação: findingIndex resolve, sem ambiguidade,
                     qual finding original este item do Mapa de Ações representa
                     (necessário porque a mesma regra_id pode gerar múltiplas
                     ocorrências indistinguíveis entre si aqui, ex.: 2+ ciclos
                     STRUCT-005). Ausente só em Score calculado antes desta fase
                     (registro histórico) — nesse caso não é possível criar ação
                     nem contar ações abertas, mensagem amigável em vez de erro. --}}
                @if ($acao->findingIndex === null)
                <small class="text-muted"><i class="bx bx-info-circle"></i> Criar ação não disponível para análises anteriores a esta funcionalidade.</small>
                @else
                    @can('create', [\App\Models\PlanoAcao::class, $importacao->obra_id])
                        @php $qtdAbertas = $this->contarAcoesAbertasParaFinding($acao->findingIndex); @endphp
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirModalCriarAcao({{ $acao->findingIndex }})">
                            <i class="bx bx-plus"></i> Criar Ação
                        </button>
                        @if ($qtdAbertas > 0)
                        {{-- Fase 4.3, Etapa E: badge vira link pro Plano de Ação já
                             filtrado pela mesma regra — route('radar.plano-acao') não
                             tem parâmetro de obra (obra vem do ObraContext/sessão, mesmo
                             mecanismo de todo link radar.* já existente no projeto,
                             nunca um novo mecanismo de contexto inventado aqui). --}}
                        <a href="{{ route('radar.plano-acao', ['regra' => $acao->regraId]) }}"
                           class="badge bg-label-info ms-1" wire:navigate>
                            {{ $qtdAbertas }} ação(ões) aberta(s)
                        </a>
                        @endif
                    @endcan
                @endif
            </div>
            @empty
            <p class="text-muted mb-0">Nenhum problema com impacto negativo no Score foi identificado nesta análise.</p>
            @endforelse
        </div>
    </div>

    {{-- =====================================================================
         MODAL: CRIAR AÇÃO (Fase 4.2) — mesmo padrão de ⚡restricoes.blade.php
         (modal condicional em Blade, sem depender de JS do Bootstrap).
         ===================================================================== --}}
    @if ($indiceParaCriarAcao !== null)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-plus me-2"></i>Criar Ação</h5>
                    <button type="button" class="btn-close" wire:click="fecharModalCriarAcao"></button>
                </div>
                <div class="modal-body">
                    @if ($novaAcaoErro)
                    <div class="alert alert-warning">{{ $novaAcaoErro }}</div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select class="form-select" wire:model="novaAcaoResponsavelId">
                            <option value="">— Sem responsável —</option>
                            @foreach ($this->usuariosDaObra() as $u)
                            <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                            @endforeach
                        </select>
                        @error('novaAcaoResponsavelId')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prazo</label>
                        <input type="date" class="form-control" wire:model="novaAcaoPrazo">
                        @error('novaAcaoPrazo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalCriarAcao">Cancelar</button>
                    <button type="button" class="btn btn-primary" wire:click="confirmarCriarAcao" wire:loading.attr="disabled">
                        <span wire:loading wire:target="confirmarCriarAcao">
                            <span class="spinner-border spinner-border-sm me-1"></span>
                        </span>
                        Criar Ação
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
    @endif

    {{-- =====================================================================
         RESUMO POR SEVERIDADE — totais já persistidos, nunca recalculados
         ===================================================================== --}}
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Health Check — Resumo por Severidade</h5>
            <div class="row g-3 text-center">
                <div class="col">
                    <div class="fs-4 fw-bold">{{ $healthCheck->total_criticos }}</div>
                    <span class="badge bg-{{ HealthCheckSeveridade::Critico->cor() }}">{{ HealthCheckSeveridade::Critico->emoji() }} {{ HealthCheckSeveridade::Critico->label() }}</span>
                </div>
                <div class="col">
                    <div class="fs-4 fw-bold">{{ $healthCheck->total_altos }}</div>
                    <span class="badge bg-{{ HealthCheckSeveridade::Alto->cor() }}">{{ HealthCheckSeveridade::Alto->emoji() }} {{ HealthCheckSeveridade::Alto->label() }}</span>
                </div>
                <div class="col">
                    <div class="fs-4 fw-bold">{{ $healthCheck->total_medios }}</div>
                    <span class="badge bg-{{ HealthCheckSeveridade::Medio->cor() }}">{{ HealthCheckSeveridade::Medio->emoji() }} {{ HealthCheckSeveridade::Medio->label() }}</span>
                </div>
                <div class="col">
                    <div class="fs-4 fw-bold">{{ $healthCheck->total_baixos }}</div>
                    <span class="badge bg-{{ HealthCheckSeveridade::Baixo->cor() }}">{{ HealthCheckSeveridade::Baixo->emoji() }} {{ HealthCheckSeveridade::Baixo->label() }}</span>
                </div>
                <div class="col">
                    <div class="fs-4 fw-bold">{{ $healthCheck->total_informativos }}</div>
                    <span class="badge bg-{{ HealthCheckSeveridade::Informativo->cor() }}">{{ HealthCheckSeveridade::Informativo->emoji() }} {{ HealthCheckSeveridade::Informativo->label() }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- =====================================================================
         OCORRÊNCIAS COMPLETAS — reaproveita o partial já existente, 100%
         dinâmico por categoria (nada novo aqui).
         ===================================================================== --}}
    @php $findingsExibidos = $this->findingsFiltrados(); @endphp
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    Ocorrências do Health Check
                    @if ($categoriaFiltro !== null)
                        <span class="text-muted fs-6 fw-normal">— filtrado por {{ HealthCheckCategoria::from($categoriaFiltro)->label() }}</span>
                    @endif
                </h5>
                @if ($categoriaFiltro !== null)
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="filtrarCategoria(null)">
                    <i class="bx bx-x me-1"></i>Mostrar todas as categorias
                </button>
                @endif
            </div>
            @if (empty($healthCheck->findings))
            <p class="text-muted mb-0">Nenhuma inconsistência relevante foi encontrada nesta importação.</p>
            @elseif (empty($findingsExibidos))
            <p class="text-muted mb-0">Nenhuma ocorrência da categoria selecionada nesta importação.</p>
            @else
            @include('pages.radar._partials.health-check-findings', ['findings' => $findingsExibidos, 'idPrefix' => 'importacao-detalhe'])
            @endif
        </div>
    </div>
    @endif {{-- fecha o @else de "! $importacao->healthCheck" (linha ~83) --}}
</div>
