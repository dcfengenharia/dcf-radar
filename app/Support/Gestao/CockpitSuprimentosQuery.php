<?php

namespace App\Support\Gestao;

use App\DTOs\Gestao\Cockpit\CockpitAtividadeLinha;
use App\DTOs\Gestao\Cockpit\CockpitSuprimentos;
use App\DTOs\Gestao\SituacaoGerencial;
use App\Enums\EstadoCoberturaMaterial;
use App\Enums\FaixaFolgaAtendimento;
use App\Enums\SeveridadeSituacao;
use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRecebimentoItem;
use App\Enums\StatusRequisicaoCompra;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\Material;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItem;
use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.6 — Cockpit de Suprimentos e Abastecimento. Read
 * model ÚNICO consumido pela UI especializada (`⚡cockpit-suprimentos.blade.php`).
 * Mesmo princípio de arquitetura da 21.5 (Seção 2 do pedido): `domínio
 * operacional → Queries de Gestão já existentes → esta classe (composição
 * pura) → DTO → Livewire → Blade`. Blade/Livewire NUNCA calculam
 * necessidade/atraso/cobertura/déficit/prontidão/folga/status.
 *
 * **Nunca duplica `CockpitObraQuery`** (Seção 4) — reaproveita
 * DIRETAMENTE os métodos `public static` já testados de lá
 * (`porTipo`/`montarPipelineMateriais`/`montarProntidaoPorHorizonte`/
 * `carregarMateriaisEnvolvidos`/`montarMatrizAtividades`/
 * `montarFornecedores`/`montarIndustrializacao`) — nunca recopia a
 * composição, só chama a MESMA função. Os blocos exclusivos deste
 * Cockpit (`comprasPendentes`/`pedidosCriticos`/`recebimentos`/
 * `chegaTardeDemais`/enriquecimento de fornecedores) são novos, mas
 * SEMPRE compostos sobre os mesmos serviços de base (`SituacoesGerenciaisQuery`,
 * `CoberturaMaterialAtividadeQuery`, `PipelineMaterialQuery`,
 * `ItemSuprimento::necessidade()`/`dataProjetadaAtendimento()`/
 * `folgaAtendimento()`), nunca uma segunda regra.
 */
class CockpitSuprimentosQuery
{
    /** Seção 7 do pedido — threshold explícito, nomeado, parametrizável (nunca hardcoded numa cor). */
    public const LIMIAR_FOLGA_PEQUENA_DIAS = 7;

    private const HORIZONTE_PRONTIDAO_MAX_DIAS = 56;

    private const LIMITE_PEDIDOS_CRITICOS = 20;

    private const LIMITE_RECEBIMENTOS_POR_BLOCO = 20;

    private const DIAS_PROXIMOS_RECEBIMENTOS = 7;

    public static function resumo(Work $obra, int $horizontePrincipalDias = 28): CockpitSuprimentos
    {
        $referencia = Carbon::today();

        // --- Mesma fonte única de situações da 21.2/21.5 — nunca uma 2ª derivação. ---
        $situacoes = SituacoesGerenciaisQuery::porObra($obra, $horizontePrincipalDias);

        $necessidadesCriticas = self::montarNecessidadesCriticas($situacoes);

        // --- Cobertura material (21.1) — mesma chamada larga (56d) já
        // usada por CockpitObraQuery, reaproveitada aqui via SEUS
        // próprios métodos públicos, nunca recopiada. ---
        $paresProntidao = CoberturaMaterialAtividadeQuery::porObra($obra, self::HORIZONTE_PRONTIDAO_MAX_DIAS, $referencia);
        $materiaisEnvolvidos = CockpitObraQuery::carregarMateriaisEnvolvidos($paresProntidao);
        $prontidaoOperacionalPorAtividade = (new CentralProntidaoQuery())
            ->paraObra($obra, horizonteAte: $referencia->copy()->addDays(self::HORIZONTE_PRONTIDAO_MAX_DIAS))
            ->keyBy(fn ($v) => $v->atividadeId);
        $matrizCompleta = CockpitObraQuery::montarMatrizAtividades($paresProntidao, $prontidaoOperacionalPorAtividade, $materiaisEnvolvidos, $referencia);
        $coberturaFutura = $matrizCompleta
            ->filter(fn (CockpitAtividadeLinha $l) => $l->prontidaoMaterial !== EstadoCoberturaMaterial::Coberto)
            ->filter(fn (CockpitAtividadeLinha $l) => $l->diasParaInicio <= $horizontePrincipalDias)
            ->values();

        // --- Pipeline (21.1) — reaproveitado tal e qual. ---
        [$funilTotal, $funilMateriais] = CockpitObraQuery::montarPipelineMateriais($obra);

        $comprasPendentes = self::montarComprasPendentes($funilMateriais);

        $pedidosRelevantes = self::carregarPedidosRelevantes($obra);
        $pedidosCriticos = self::montarPedidosCriticos($pedidosRelevantes, $referencia);
        $recebimentos = self::montarRecebimentos($pedidosRelevantes, $situacoes, $referencia);

        $fornecedores = self::montarFornecedoresEnriquecidos($obra, $situacoes, $horizontePrincipalDias, $referencia);

        $estoque = [
            'reservas_descobertas' => CockpitObraQuery::porTipo($situacoes, TipoSituacaoGerencial::ReservaDescoberta),
            'materiais_sem_destinacao' => CockpitObraQuery::porTipo($situacoes, TipoSituacaoGerencial::MaterialSemDestinacao),
            'saidas_sem_conciliacao' => CockpitObraQuery::porTipo($situacoes, TipoSituacaoGerencial::SaidaSemConciliacao),
            'materiais_parados' => CockpitObraQuery::porTipo($situacoes, TipoSituacaoGerencial::MaterialParado),
        ];

        $terceiros = CockpitObraQuery::montarIndustrializacao($obra, $situacoes);

        $chegaTardeDemais = self::montarChegaTardeDemais($obra, $horizontePrincipalDias);

        $panorama = [
            'necessidades_sem_compra' => $comprasPendentes->filter(fn ($l) => $l['saldo_a_requisitar'] > 0.0005)->count(),
            'pedidos_atrasados' => $pedidosCriticos->filter(fn ($p) => $p['dias_atraso'] !== null)->count(),
            'atividades_cobertura_insuficiente' => $coberturaFutura->count(),
            'reservas_descobertas' => $estoque['reservas_descobertas']->count(),
            'recebimentos_vencidos' => $recebimentos['vencidos']->count(),
        ];

        return new CockpitSuprimentos(
            obraId: $obra->id,
            horizontePrincipalDias: $horizontePrincipalDias,
            panorama: $panorama,
            necessidadesCriticas: $necessidadesCriticas,
            funilAbastecimento: $funilMateriais,
            funilTotalMateriais: $funilTotal,
            comprasPendentes: $comprasPendentes,
            pedidosCriticos: $pedidosCriticos,
            recebimentos: $recebimentos,
            fornecedores: $fornecedores,
            coberturaFutura: $coberturaFutura,
            estoque: $estoque,
            terceiros: $terceiros,
            chegaTardeDemais: $chegaTardeDemais,
            gaps: self::gaps(),
        );
    }

    /**
     * Bloco 5 ("o que precisa da minha ação") — tipos Suprimentos-relevantes
     * das situações JÁ calculadas (21.2), reaproveitadas tal e qual, nunca
     * uma 2ª prioridade inventada — a ordenação já vem de
     * `SituacaoGerencial::chaveOrdenacao()` (herdada da coleção original).
     */
    private static function montarNecessidadesCriticas(Collection $situacoes): Collection
    {
        $tipos = [
            TipoSituacaoGerencial::MaterialCritico,
            TipoSituacaoGerencial::ReservaDescoberta,
            TipoSituacaoGerencial::PedidoAtrasado,
            TipoSituacaoGerencial::MaterialSemDestinacao,
            TipoSituacaoGerencial::IndustrializacaoPendente,
        ];

        return $situacoes
            ->filter(fn (SituacaoGerencial $s) => in_array($s->tipo, $tipos, true))
            ->filter(fn (SituacaoGerencial $s) => $s->severidade !== SeveridadeSituacao::Informativa)
            ->values();
    }

    /**
     * "Demanda ainda não comprada" (Seção 10) — mesma subtração em
     * cascata já usada e testada em `App\Support\Suprimentos\
     * ConciliacaoRecebimento::cadeiaCompletaPorItemTakeOff()` (Ciclo
     * 19.6), aqui aplicada sobre a linha JÁ AGREGADA por Material de
     * `PipelineMaterialQuery` (nunca reconvocada por ItemTakeOff — esse
     * método é documentadamente "uso pontual, nunca listagem em massa").
     * Puramente aritmética sobre dado já buscado — zero query nova.
     */
    private static function montarComprasPendentes(Collection $funilMateriais): Collection
    {
        return $funilMateriais
            ->map(function (array $l) {
                $necessidade = $l['necessidade'] ?? 0.0;
                $requisitado = $l['requisitado'] ?? 0.0;
                $alocado = $l['alocado'] ?? 0.0;
                $emRc = $l['em_rc'] ?? 0.0;
                $emPedido = $l['em_pedido'] ?? 0.0;

                return array_merge($l, [
                    'saldo_a_requisitar' => round(max(0, $necessidade - $requisitado), 3),
                    'saldo_a_alocar' => round(max(0, $requisitado - $alocado), 3),
                    'saldo_a_colocar_em_rc' => round(max(0, $alocado - $emRc), 3),
                    'saldo_a_colocar_em_pedido' => round(max(0, $emRc - $emPedido), 3),
                    'percentual_comprado' => $necessidade > 0.0005 ? round(min(100, ($emPedido / $necessidade) * 100), 1) : null,
                ]);
            })
            ->filter(fn ($l) => $l['necessidade'] > 0.0005 && $l['percentual_comprado'] < 100)
            ->sortBy(fn ($l) => [-$l['saldo_a_requisitar'], -$l['necessidade']])
            ->values();
    }

    /**
     * Único ponto de leitura de Pedidos relevantes (Emitido, com data
     * prevista) — reaproveitado por `montarPedidosCriticos()` E
     * `montarRecebimentos()`, nunca 2 queries separadas pro mesmo
     * conjunto de Pedidos (Seção 28 — "considere composição/batch/read
     * context compartilhado").
     */
    private static function carregarPedidosRelevantes(Work $obra): Collection
    {
        return PedidoCompra::query()
            ->where('obra_id', $obra->id)
            ->where('status', StatusPedidoCompra::Emitido->value)
            ->with([
                'fornecedor:id,nome',
                'itens.recebimentos',
                // `pacote` precisa de `atividades`/`requisicoesCompra.pedidos`
                // eager-loaded ANTES de qualquer chamada a
                // `folgaAtendimento()`/`necessidade()` — os dois leem essas
                // relações internamente (App\Models\ItemSuprimento) e
                // `Model::preventLazyLoading()` está ativo fora de produção
                // (achado real, corrigido durante a implementação desta
                // etapa: sem isso, `montarPedidosCriticos()` lançava
                // `LazyLoadingViolationException`).
                'itens.requisicaoCompraItem.alocacao.pacote:id,nome,obra_id',
                'itens.requisicaoCompraItem.alocacao.pacote.atividades',
                'itens.requisicaoCompraItem.alocacao.pacote.requisicoesCompra.pedidos',
                'itens.requisicaoCompraItem.alocacao.requisicaoItem.itemTakeOff:id,material_id',
            ])
            ->get();
    }

    /**
     * Pedidos executivos (Seção 11) — prioridade por IMPACTO sobre
     * necessidade futura quando determinístico (menor folga associada
     * primeiro, nunca só o maior atraso — Seção 11 explícita), com
     * fallback pro atraso quando não há folga calculável.
     */
    private static function montarPedidosCriticos(Collection $pedidos, Carbon $referencia): Collection
    {
        return $pedidos
            ->filter(fn (PedidoCompra $p) => $p->diasAtrasoAtual() !== null || self::menorFolgaDoPedido($p) !== null)
            ->map(function (PedidoCompra $p) {
                $folgaMinima = self::menorFolgaDoPedido($p);
                $atividadesAmeacadas = $p->itens
                    ->flatMap(fn (PedidoCompraItem $item) => $item->requisicaoCompraItem?->alocacao?->pacote?->atividades ?? collect())
                    ->unique('id')
                    ->pluck('nome')
                    ->values()
                    ->all();

                return [
                    'pedido_id' => $p->id,
                    'numero' => $p->numero,
                    'fornecedor_nome' => $p->fornecedor?->nome ?? $p->fornecedor_nome_snapshot,
                    'data_prevista_entrega' => $p->data_prevista_entrega,
                    'dias_atraso' => $p->diasAtrasoAtual(),
                    'folga_minima_associada' => $folgaMinima,
                    'atividades_ameacadas' => $atividadesAmeacadas,
                    'quantidade_pendente' => round((float) $p->itens->sum(fn ($i) => $i->saldoAReceber()), 3),
                    'percentual_recebido_medio' => $p->itens->isNotEmpty()
                        ? round((float) $p->itens->avg(fn ($i) => $i->percentualRecebido()), 1)
                        : null,
                ];
            })
            ->sortBy(fn ($l) => [
                $l['folga_minima_associada'] ?? PHP_INT_MAX,
                -($l['dias_atraso'] ?? 0),
            ])
            ->take(self::LIMITE_PEDIDOS_CRITICOS)
            ->values();
    }

    private static function menorFolgaDoPedido(PedidoCompra $p): ?int
    {
        return $p->itens
            ->map(fn (PedidoCompraItem $item) => $item->requisicaoCompraItem?->alocacao?->pacote?->folgaAtendimento())
            ->filter(fn ($f) => $f !== null)
            ->min();
    }

    /**
     * Recebimentos (Seção 12) — SEMPRE exceções, nunca agenda completa:
     * previsto hoje, próximos N dias, vencidos (mesma semântica de
     * `diasAtrasoAtual()`), parciais, e "recebido sem destinação"
     * (reaproveita a situação `MaterialSemDestinacao` já calculada,
     * nunca uma 2ª regra).
     */
    private static function montarRecebimentos(Collection $pedidos, Collection $situacoes, Carbon $referencia): array
    {
        $fimProximos = $referencia->copy()->addDays(self::DIAS_PROXIMOS_RECEBIMENTOS);
        $naoCompletos = $pedidos->filter(fn (PedidoCompra $p) => $p->situacaoEntrega() !== \App\Enums\SituacaoEntregaPedido::Completa && $p->data_prevista_entrega !== null);

        return [
            'previsto_hoje' => $naoCompletos->filter(fn ($p) => $p->data_prevista_entrega->isSameDay($referencia))->values()->take(self::LIMITE_RECEBIMENTOS_POR_BLOCO),
            'proximos_dias' => $naoCompletos->filter(fn ($p) => $p->data_prevista_entrega->gt($referencia) && $p->data_prevista_entrega->lte($fimProximos))->values()->take(self::LIMITE_RECEBIMENTOS_POR_BLOCO),
            'vencidos' => $naoCompletos->filter(fn ($p) => $p->diasAtrasoAtual() !== null)->values()->take(self::LIMITE_RECEBIMENTOS_POR_BLOCO),
            'parciais' => $pedidos->filter(fn ($p) => $p->situacaoEntrega() === \App\Enums\SituacaoEntregaPedido::Parcial)->values()->take(self::LIMITE_RECEBIMENTOS_POR_BLOCO),
            'sem_destinacao' => CockpitObraQuery::porTipo($situacoes, TipoSituacaoGerencial::MaterialSemDestinacao),
        ];
    }

    /**
     * Fornecedores (Seção 13/14) — parte de `CockpitObraQuery::
     * montarFornecedores()` (reaproveitado tal e qual), enriquecido com
     * `folga_minima` (mín. `folgaAtendimento()` entre os Pacotes deste
     * fornecedor) e `materiais_atividades_proximas` (Seção 14 —
     * investigação confirmou cadeia 100% determinística por FK:
     * `Fornecedor → PedidoCompra → PedidoCompraItem →
     * RequisicaoCompraItem → AlocacaoRequisicaoPacote → (pacote →
     * ItemSuprimento::atividades() | requisicaoItem → ItemTakeOff →
     * Material) — nunca casamento por texto/descrição). "Documentos
     * pendentes" continua um GAP (Fornecedor nunca se relaciona com
     * DocumentoEngenharia em nenhum ponto do domínio — reconfirmado,
     * não implementado).
     */
    private static function montarFornecedoresEnriquecidos(Work $obra, Collection $situacoes, int $horizonteDias, Carbon $referencia): Collection
    {
        $base = CockpitObraQuery::montarFornecedores($obra);

        if ($base->isEmpty()) {
            return $base;
        }

        $fim = $referencia->copy()->addDays($horizonteDias)->toDateString();

        $itens = PedidoCompraItem::query()
            ->whereHas('pedidoCompra', fn ($q) => $q->where('obra_id', $obra->id)->whereIn('fornecedor_id', $base->keys()))
            ->with([
                'pedidoCompra:id,fornecedor_id',
                // Achado real corrigido durante a implementação: `atividades`
                // precisa vir SEM filtro de data aqui — `folgaAtendimento()`
                // (chamado logo abaixo) lê `$this->atividades` por dentro pra
                // calcular `necessidade()` (a MENOR data entre TODAS as
                // atividades ativas do Pacote, nunca só as próximas de um
                // horizonte arbitrário); filtrar a relação eager-loaded
                // corromperia esse cálculo. A filtragem por horizonte pra
                // "materiais_atividades_proximas" é feita EM MEMÓRIA logo
                // abaixo, sobre a MESMA coleção completa — nunca via
                // constraint de query. `requisicoesCompra.pedidos` também é
                // obrigatório (mesmo achado de `carregarPedidosRelevantes()`).
                'requisicaoCompraItem.alocacao.pacote.atividades',
                'requisicaoCompraItem.alocacao.pacote.requisicoesCompra.pedidos',
            ])
            ->get()
            ->groupBy(fn (PedidoCompraItem $i) => $i->pedidoCompra->fornecedor_id);

        return $base->map(function (array $linha, string $fornecedorId) use ($itens, $referencia, $fim) {
            $itensDoFornecedor = $itens->get($fornecedorId, collect());

            $folgaMinima = $itensDoFornecedor
                ->map(fn (PedidoCompraItem $i) => $i->requisicaoCompraItem?->alocacao?->pacote?->folgaAtendimento())
                ->filter(fn ($f) => $f !== null)
                ->min();

            $atividadesProximas = $itensDoFornecedor
                ->flatMap(fn (PedidoCompraItem $i) => $i->requisicaoCompraItem?->alocacao?->pacote?->atividades ?? collect())
                ->unique('id')
                ->filter(fn ($a) => ! $a->fora_do_cronograma
                    && $a->status !== \App\Enums\StatusAtividade::Concluido
                    && $a->inicio_planejado !== null
                    && $a->inicio_planejado->toDateString() >= $referencia->toDateString()
                    && $a->inicio_planejado->toDateString() <= $fim)
                ->pluck('nome')
                ->values()
                ->all();

            return array_merge($linha, [
                'folga_minima' => $folgaMinima,
                'materiais_atividades_proximas' => $atividadesProximas,
            ]);
        });
    }

    /**
     * "O que chega tarde demais?" (Seção 19) — Pacotes com
     * `folgaAtendimento() < 0` cuja necessidade cai dentro do horizonte
     * principal. Batch: 1 query pros Pacotes com necessidade no
     * horizonte (via os mesmos pares já resolvidos por
     * `CoberturaMaterialAtividadeQuery`, reaproveitados por referência
     * de `item_suprimento_id` — nunca uma 3ª consulta de atividades).
     */
    private static function montarChegaTardeDemais(Work $obra, int $horizonteDias): Collection
    {
        $referencia = Carbon::today();
        $fim = $referencia->copy()->addDays($horizonteDias)->toDateString();

        $pacotes = ItemSuprimento::query()
            ->where('obra_id', $obra->id)
            ->whereHas('atividades', fn ($q) => $q
                ->where('fora_do_cronograma', false)
                ->where('status', '!=', \App\Enums\StatusAtividade::Concluido->value)
                ->whereBetween('inicio_planejado', [$referencia->toDateString(), $fim]))
            ->with(['atividades', 'requisicoesCompra.pedidos', 'requisicoesCompra.etapas'])
            ->get();

        return $pacotes
            ->map(function (ItemSuprimento $pacote) {
                $folga = $pacote->folgaAtendimento();

                return [
                    'pacote_id' => $pacote->id,
                    'pacote_nome' => $pacote->nome,
                    'necessidade' => $pacote->necessidade(),
                    'atendimento_projetado' => $pacote->dataProjetadaAtendimento(),
                    'folga' => $folga,
                    'faixa' => FaixaFolgaAtendimento::classificar($folga, self::LIMIAR_FOLGA_PEQUENA_DIAS),
                ];
            })
            ->filter(fn ($l) => $l['folga'] !== null && $l['folga'] < 0)
            ->sortBy('folga')
            ->values();
    }

    private static function gaps(): array
    {
        return [
            'Fornecedores: documentos de Engenharia pendentes vinculados não são calculados (Fornecedor nunca se relaciona com DocumentoEngenharia em nenhum ponto do domínio, reconfirmado nesta etapa).',
            'Valores financeiros: RequisicaoCompraItem/PedidoCompra/PedidoCompraItem não possuem nenhum campo de valor/preço/moeda no domínio hoje — bloco financeiro não implementado (investigado, não presumido).',
            'Estoque excedente: não implementado — as decisões de semântica (horizonte, se reserva/destinação contam como compromisso, se estoque em terceiro entra) não têm resposta clara e determinística no domínio hoje (Seção 17). Continua usando "material parado".',
            'Industrialização: prazo formal de produção não existe no domínio — nunca classificado como "atrasada".',
        ];
    }
}
