<?php

namespace App\Support\Gestao;

use App\DTOs\Gestao\Cockpit\CockpitAtividadeLinha;
use App\DTOs\Gestao\Cockpit\CockpitObra;
use App\DTOs\Gestao\Cockpit\CockpitProntidaoHorizonte;
use App\DTOs\Gestao\SituacaoGerencial;
use App\Enums\EstadoCoberturaMaterial;
use App\Enums\StatusInventarioEstoque;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Fornecedor;
use App\Models\InventarioEstoque;
use App\Models\ItemSuprimento;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\ReservaEstoque;
use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use App\Support\Industrializacao\ResumoIndustrializacaoQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.5 — Cockpit Executivo da Obra. Read model ÚNICO
 * consumido pela UI (`⚡cockpit.blade.php`) — nunca recalcula nenhuma
 * regra de negócio (Seção 2 do pedido): tudo aqui é COMPOSIÇÃO de
 * serviços já existentes e já testados das Etapas 21.1/21.2 + Central de
 * Prontidão (Ciclo 15) + Suprimentos/Estoque/Industrialização (Ciclos
 * 19/20), nunca uma segunda fonte de verdade.
 *
 * **Horizonte principal vs. horizonte de prontidão**: `$horizontePrincipalDias`
 * (default 28) escopa `SituacoesGerenciaisQuery::porObra()` (riscos/ações
 * hoje/suprimentos×cronograma/engenharia/estoque/inventário/industrialização
 * — os mesmos 28 dias já usados pela Central de Notificações/digest desde
 * a 21.2/21.3/21.4, nunca uma janela nova). A Matriz de Prontidão (Seção
 * 7-9) usa sempre os 56 dias mais largos (8 semanas) — `CoberturaMaterialAtividadeQuery::
 * porObra($obra, 56)` é chamada **uma única vez** (Seção 7: "não calcular
 * 3x o mesmo universo") e os buckets de 2/4/8 semanas são derivados EM
 * MEMÓRIA sobre esse mesmo resultado, nunca 3 chamadas separadas.
 */
class CockpitObraQuery
{
    private const HORIZONTES_SEMANAS = ['2' => 14, '4' => 28, '8' => 56];

    private const HORIZONTE_PRONTIDAO_MAX_DIAS = 56;

    private const LIMITE_RISCOS = 10;

    private const LIMITE_PIPELINE_MATERIAIS = 20;

    public static function resumo(Work $obra, int $horizontePrincipalDias = 28): CockpitObra
    {
        $referencia = Carbon::today();

        // --- Situações gerenciais (21.2), fonte única de riscos/ações/
        // decisões — chamada UMA vez, todos os blocos derivados abaixo
        // são FILTROS sobre a MESMA Collection, nunca uma 2ª consulta.
        $situacoes = SituacoesGerenciaisQuery::porObra($obra, $horizontePrincipalDias);

        [$riscos, $acoesHoje, $totalInformativas] = self::separarRiscoEAcoes($situacoes);

        // --- Cobertura material — 1 chamada, horizonte mais largo (56d) ---
        $paresProntidao = CoberturaMaterialAtividadeQuery::porObra($obra, self::HORIZONTE_PRONTIDAO_MAX_DIAS, $referencia);

        $prontidao = self::montarProntidaoPorHorizonte($paresProntidao, $referencia);
        $materiaisPorId = self::carregarMateriaisEnvolvidos($paresProntidao);

        $prontidaoOperacionalPorAtividade = (new CentralProntidaoQuery())
            ->paraObra($obra, horizonteAte: $referencia->copy()->addDays(self::HORIZONTE_PRONTIDAO_MAX_DIAS))
            ->keyBy(fn ($v) => $v->atividadeId);

        $matrizAtividades = self::montarMatrizAtividades($paresProntidao, $prontidaoOperacionalPorAtividade, $materiaisPorId, $referencia);

        [$pipelineTotal, $pipelineMateriais] = self::montarPipelineMateriais($obra);

        $suprimentosCronograma = self::montarSuprimentosCronograma($situacoes);

        $fornecedores = self::montarFornecedores($obra);

        $estoque = [
            'reservas_descobertas' => self::porTipo($situacoes, TipoSituacaoGerencial::ReservaDescoberta),
            'materiais_sem_destinacao' => self::porTipo($situacoes, TipoSituacaoGerencial::MaterialSemDestinacao),
            'saidas_sem_conciliacao' => self::porTipo($situacoes, TipoSituacaoGerencial::SaidaSemConciliacao),
            'desvios_aplicacao' => self::porTipo($situacoes, TipoSituacaoGerencial::DesvioAplicacao),
            'materiais_parados' => self::porTipo($situacoes, TipoSituacaoGerencial::MaterialParado),
        ];

        $industrializacao = self::montarIndustrializacao($obra, $situacoes);

        $engenharia = self::porTipo($situacoes, TipoSituacaoGerencial::DocumentoBloqueante);

        $inventario = [
            'em_contagem' => InventarioEstoque::query()
                ->where('obra_id', $obra->id)
                ->where('status', StatusInventarioEstoque::EmContagem->value)
                ->count(),
            'aguardando_decisao' => self::porTipo($situacoes, TipoSituacaoGerencial::InventarioAguardandoDecisao),
        ];

        return new CockpitObra(
            obraId: $obra->id,
            horizontePrincipalDias: $horizontePrincipalDias,
            panorama: ResumoExecutivoGerencial::deSituacoes($situacoes),
            riscos: $riscos,
            acoesHoje: $acoesHoje,
            totalInformativas: $totalInformativas,
            prontidao: $prontidao,
            matrizAtividades: $matrizAtividades,
            pipelineTotalMateriais: $pipelineTotal,
            pipelineMateriais: $pipelineMateriais,
            suprimentosCronograma: $suprimentosCronograma,
            fornecedores: $fornecedores,
            estoque: $estoque,
            industrializacao: $industrializacao,
            engenharia: $engenharia,
            inventario: $inventario,
            gaps: self::gaps(),
        );
    }

    /**
     * Bloco 5 ("o que pode parar a obra") = TOP N por prioridade JÁ
     * calculada (`SituacaoGerencial::chaveOrdenacao()`, nunca uma nova
     * ordenação), severidade Crítica/Alta. Bloco 6 ("onde agir hoje") =
     * toda situação NÃO Informativa que ainda não apareceu no bloco 5
     * (nunca duplicada entre os dois — Seção 6 do pedido, "não jogar
     * tudo numa mesma lista"). Informativas nunca entram em nenhum dos
     * dois blocos (Seção 18/19 — "informação gerencial", nunca
     * "decisão"), só contadas.
     *
     * @return array{0: Collection, 1: Collection, 2: int}
     */
    private static function separarRiscoEAcoes(Collection $situacoes): array
    {
        $riscos = $situacoes
            ->filter(fn (SituacaoGerencial $s) => in_array($s->severidade, [
                \App\Enums\SeveridadeSituacao::Critica,
                \App\Enums\SeveridadeSituacao::Alta,
            ], true))
            ->take(self::LIMITE_RISCOS)
            ->values();

        $chavesEmRisco = $riscos->pluck('chaveLogica');

        $acoesHoje = $situacoes
            ->filter(fn (SituacaoGerencial $s) => $s->severidade !== \App\Enums\SeveridadeSituacao::Informativa
                && ! $chavesEmRisco->contains($s->chaveLogica))
            ->values();

        $totalInformativas = $situacoes->filter(fn (SituacaoGerencial $s) => $s->severidade === \App\Enums\SeveridadeSituacao::Informativa)->count();

        return [$riscos, $acoesHoje, $totalInformativas];
    }

    /** Ciclo 21, Etapa 21.6 — `public` pra reuso direto por `CockpitSuprimentosQuery` (Seção 4: "compartilhe queries inferiores, não copie código"). */
    public static function porTipo(Collection $situacoes, TipoSituacaoGerencial $tipo): Collection
    {
        return $situacoes->filter(fn (SituacaoGerencial $s) => $s->tipo === $tipo)->values();
    }

    /**
     * Prontidão 2/4/8 semanas (Seção 7/8) — mapa de agrupamento
     * documentado em `CockpitProntidaoHorizonte`. `$paresPorObra` já vem
     * calculado UMA vez pro horizonte mais largo; aqui só filtramos EM
     * MEMÓRIA por `inicio_planejado`, nunca uma nova consulta.
     */
    /** Ciclo 21, Etapa 21.6 — `public`, mesmo motivo de `porTipo()` acima. */
    public static function montarProntidaoPorHorizonte(Collection $paresPorObra, Carbon $referencia): array
    {
        $grupos = [
            'cobertas' => [EstadoCoberturaMaterial::Coberto],
            'parcial' => [
                EstadoCoberturaMaterial::ParcialmenteCoberto,
                EstadoCoberturaMaterial::RecebidoAguardandoDisponibilizacao,
                EstadoCoberturaMaterial::DeficitAposConsumoEmergencial,
            ],
            'descobertas' => [
                EstadoCoberturaMaterial::SemCobertura,
                EstadoCoberturaMaterial::AguardandoCompra,
                EstadoCoberturaMaterial::CompradoAguardandoRecebimento,
            ],
        ];

        $resultado = [];

        foreach (self::HORIZONTES_SEMANAS as $semanas => $dias) {
            $fim = $referencia->copy()->addDays($dias)->toDateString();
            $linhas = $paresPorObra->filter(fn (array $linha) => $linha['inicio_planejado']->toDateString() <= $fim);

            $total = $linhas->count();
            $cobertas = $linhas->filter(fn ($l) => in_array($l['estado_agregado'], $grupos['cobertas'], true))->count();
            $parcial = $linhas->filter(fn ($l) => in_array($l['estado_agregado'], $grupos['parcial'], true))->count();
            $descobertas = $linhas->filter(fn ($l) => in_array($l['estado_agregado'], $grupos['descobertas'], true))->count();
            $infoInsuficiente = $linhas->filter(fn ($l) => $l['estado_agregado'] === EstadoCoberturaMaterial::InformacaoInsuficiente)->count();

            $avaliaveis = $total - $infoInsuficiente;

            $resultado[$semanas] = new CockpitProntidaoHorizonte(
                horizonteDias: $dias,
                horizonteSemanas: (int) $semanas,
                total: $total,
                cobertas: $cobertas,
                parcial: $parcial,
                descobertas: $descobertas,
                informacaoInsuficiente: $infoInsuficiente,
                percentualCoberturaAvaliavel: $avaliaveis > 0 ? round(($cobertas / $avaliaveis) * 100, 1) : null,
            );
        }

        return $resultado;
    }

    /** Ciclo 21, Etapa 21.6 — `public`, mesmo motivo de `porTipo()` acima. */
    public static function carregarMateriaisEnvolvidos(Collection $paresPorObra): Collection
    {
        $materialIds = $paresPorObra
            ->flatMap(fn (array $linha) => $linha['pares']->pluck('material_id'))
            ->unique()
            ->values()
            ->all();

        if (empty($materialIds)) {
            return collect();
        }

        return Material::query()->whereIn('id', $materialIds)->get(['id', 'codigo', 'descricao'])->keyBy('id');
    }

    /**
     * Matriz de Prontidão Futura (Seção 9) — JOIN em memória entre
     * cobertura material (`$paresPorObra`) e prontidão OPERACIONAL
     * (`$prontidaoOperacionalPorAtividade`, já resolvida em lote por
     * `CentralProntidaoQuery`), por `atividade_id`. Nenhuma das duas
     * fontes é recalculada — só combinadas.
     */
    /** Ciclo 21, Etapa 21.6 — `public`, mesmo motivo de `porTipo()` acima. */
    public static function montarMatrizAtividades(
        Collection $paresPorObra,
        Collection $prontidaoOperacionalPorAtividade,
        Collection $materiaisPorId,
        Carbon $referencia,
    ): Collection {
        return $paresPorObra->map(function (array $linha) use ($prontidaoOperacionalPorAtividade, $materiaisPorId, $referencia) {
            $operacional = $prontidaoOperacionalPorAtividade->get($linha['atividade_id']);

            $materiaisCriticos = $linha['pares']
                ->filter(fn (array $par) => $par['estado'] !== EstadoCoberturaMaterial::Coberto)
                ->map(function (array $par) use ($materiaisPorId) {
                    $material = $materiaisPorId->get($par['material_id']);

                    return [
                        'material_id' => $par['material_id'],
                        'codigo' => $material?->codigo,
                        'estado' => $par['estado']->value,
                        'faltante' => round(max(0, $par['demanda'] - $par['reservado_pacote']), 3),
                        'item_suprimento_id' => $par['item_suprimento_id'],
                    ];
                })
                ->values()
                ->all();

            return new CockpitAtividadeLinha(
                atividadeId: $linha['atividade_id'],
                codigo: $linha['atividade_codigo'] ?? $linha['atividade_id'],
                descricao: $linha['atividade_nome'],
                frente: $operacional?->frenteNome,
                pacote: $operacional?->pacoteNome,
                inicioPlanejado: $linha['inicio_planejado'],
                diasParaInicio: (int) $referencia->diffInDays($linha['inicio_planejado'], false),
                statusOperacional: $operacional?->statusOperacional?->label(),
                prontidaoMaterial: $linha['estado_agregado'],
                materiaisCriticos: $materiaisCriticos,
                restricoesDocumentos: $operacional?->resumoMotivos ?? [],
            );
        })->values();
    }

    /**
     * Pipeline de Suprimentos (Seção 10) — NUNCA um funil somado (unidades
     * incompatíveis entre materiais). "Materiais relevantes à obra" =
     * união de material_id presente em: (a) pares de cobertura do
     * horizonte de prontidão, (b) `MovimentacaoEstoque` da obra, (c)
     * `ReservaEstoque` ativa da obra — mesmos padrões batch já usados em
     * `SituacoesGerenciaisQuery::materialParado()`/`reservaDescoberta()`,
     * nunca uma heurística nova. Retorna a linha CRUA de
     * `PipelineMaterialQuery::porMateriais()` por Material (nunca somada
     * entre materiais), ordenada por déficit desc → necessidade desc,
     * cortada a `LIMITE_PIPELINE_MATERIAIS` — o total real (antes do
     * corte) vai à parte, pra a UI nunca fingir "isto é tudo".
     *
     * @return array{0: int, 1: Collection}
     */
    /** Ciclo 21, Etapa 21.6 — `public`, mesmo motivo de `porTipo()` acima. */
    public static function montarPipelineMateriais(Work $obra): array
    {
        $idsMovimentacao = MovimentacaoEstoque::query()
            ->where('obra_id', $obra->id)
            ->distinct()
            ->pluck('material_id');

        $idsReserva = ReservaEstoque::query()
            ->where('obra_id', $obra->id)
            ->distinct()
            ->pluck('material_id');

        $materialIds = $idsMovimentacao->merge($idsReserva)->unique()->values();

        if ($materialIds->isEmpty()) {
            return [0, collect()];
        }

        $materiais = Material::query()->whereIn('id', $materialIds)->get();
        $pipeline = PipelineMaterialQuery::porMateriais($materiais, $obra->id);

        $linhas = $materiais->map(function (Material $material) use ($pipeline) {
            $linha = $pipeline->get($material->id, []);

            return array_merge($linha, [
                'material_codigo' => $material->codigo,
                'material_descricao' => $material->descricao,
            ]);
        });

        $ordenadas = $linhas
            ->sortByDesc(fn ($l) => [$l['deficit'] ?? 0, $l['necessidade'] ?? 0])
            ->values();

        return [$linhas->count(), $ordenadas->take(self::LIMITE_PIPELINE_MATERIAIS)->values()];
    }

    /**
     * Suprimentos × Cronograma (Seção 11) — reaproveita as situações
     * `MaterialCritico` JÁ calculadas (mesmo horizonte principal), nunca
     * uma segunda derivação. Enriquecida com `ItemSuprimento::
     * folgaAtendimento()` (semântica AUTORITATIVA, nunca recalculada) —
     * batch-load dos Pacotes envolvidos com o MESMO eager-load já
     * validado em `ConciliacaoAlocacao::porPacote()` (Ciclo 19.3).
     *
     * **Gap documentado**: cobre só os 3 estados que `materialCritico()`
     * já trata como acionáveis (SemCobertura/AguardandoCompra/
     * DeficitAposConsumoEmergencial) — `CompradoAguardandoRecebimento`
     * (pedido emitido, ainda não recebido) não gera `SituacaoGerencial`
     * própria (decisão da 21.2), mas continua visível na Matriz de
     * Prontidão Futura (bloco 9, `materiaisCriticos` por atividade).
     */
    /** Ciclo 21, Etapa 21.6 — `public`, mesmo motivo de `porTipo()` acima. */
    public static function montarSuprimentosCronograma(Collection $situacoes): Collection
    {
        $materialCriticas = self::porTipo($situacoes, TipoSituacaoGerencial::MaterialCritico);

        if ($materialCriticas->isEmpty()) {
            return collect();
        }

        $pacoteIds = $materialCriticas
            ->map(fn (SituacaoGerencial $s) => $s->contexto['item_suprimento_id'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $pacotes = ItemSuprimento::query()
            ->whereIn('id', $pacoteIds)
            ->with(['atividades', 'requisicoesCompra.pedidos', 'requisicoesCompra.etapas'])
            ->get()
            ->keyBy('id');

        return $materialCriticas->map(function (SituacaoGerencial $s) use ($pacotes) {
            $pacote = $pacotes->get($s->contexto['item_suprimento_id'] ?? null);

            return [
                'situacao' => $s,
                'folga_dias' => $pacote?->folgaAtendimento(),
                'pacote_nome' => $pacote?->nome,
            ];
        })->values();
    }

    /**
     * Fornecedores (Seção 12) — `ResumoFornecedorQuery` (21.2) não expõe
     * `nome` no próprio row (serviço intocado desde a 21.2, sem motivo
     * pra alterar aqui) — o Cockpit anexa o nome via 1 lookup batch
     * adicional, puramente de apresentação. Ordenado por impacto factual
     * (pedidos atrasados desc, nunca um ranking/score inventado — Seção
     * 12: "não criar Top 5 piores fornecedores").
     */
    /** Ciclo 21, Etapa 21.6 — `public`, mesmo motivo de `porTipo()` acima. */
    public static function montarFornecedores(Work $obra): Collection
    {
        $resumo = ResumoFornecedorQuery::porObra($obra->id);

        if ($resumo->isEmpty()) {
            return collect();
        }

        $nomes = Fornecedor::query()->whereIn('id', $resumo->keys())->pluck('nome', 'id');

        return $resumo
            ->map(fn (array $linha, string $id) => array_merge($linha, ['nome' => $nomes->get($id)]))
            ->sortByDesc(fn ($l) => [$l['pedidos_atrasados'], $l['pedidos_abertos']])
            ->values()
            ->keyBy('fornecedor_id');
    }

    /**
     * Industrialização / Material em Terceiros (Seção 14) — mesma
     * composição de `montarFornecedores()`: `ResumoIndustrializacaoQuery`
     * (21.2) não expõe fornecedor/numero, anexados aqui via lookup batch.
     * "Pendências" (industrializacaoPendente) já é a MESMA situação
     * `SituacaoGerencial` já calculada — nunca duplicada.
     */
    /** Ciclo 21, Etapa 21.6 — `public`, mesmo motivo de `porTipo()` acima. */
    public static function montarIndustrializacao(Work $obra, Collection $situacoes): Collection
    {
        $resumo = ResumoIndustrializacaoQuery::porObra($obra->id);

        if ($resumo->isEmpty()) {
            return collect();
        }

        $ordens = OrdemIndustrializacao::query()
            ->whereIn('id', $resumo->keys())
            ->with('fornecedor:id,nome')
            ->get()
            ->keyBy('id');

        return $resumo->map(function (array $linha, string $id) use ($ordens) {
            $ordem = $ordens->get($id);

            return array_merge($linha, [
                'numero' => $ordem?->numero,
                'fornecedor_nome' => $ordem?->fornecedor?->nome,
            ]);
        });
    }

    /**
     * Gaps conhecidos (Seção 26/pedido geral: "não invente — mostre o
     * gap") — sempre os MESMOS documentados nos serviços de origem
     * (21.2), nunca novos, nunca escondidos da UI.
     */
    private static function gaps(): array
    {
        return [
            'Fornecedores: materiais de atividades próximas e documentos pendentes vinculados não são calculados nesta etapa (sem vínculo Fornecedor↔Documento determinístico).',
            'Industrialização: prazo formal de produção não existe no domínio hoje — nunca classificado como "atrasado".',
            'Pipeline de Suprimentos e Estoque: valores nunca somados entre materiais de unidades diferentes — sempre exibidos por Material ou por contagem de situações.',
        ];
    }
}
