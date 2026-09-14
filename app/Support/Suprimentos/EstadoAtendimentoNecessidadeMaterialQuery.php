<?php

namespace App\Support\Suprimentos;

use App\Enums\EstadoComercialNecessidadeMaterial;
use App\Enums\EstadoCompromissoNecessidadeMaterial;
use App\Enums\EstadoGerencialNecessidade;
use App\Enums\QualidadeInformacaoAtendimento;
use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRequisicaoCompra;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\PedidoCompraItemParcela;
use App\Models\RequisicaoCompraAdjudicacaoItem;
use App\Models\RequisicaoCompraItemParcela;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Etapa 3 (Prazo Comercial + Histórico de Promessa + Estado de
 * Atendimento) — read-model ÚNICO pra responder "esta necessidade de
 * material será atendida antes da atividade precisar?", separando
 * explicitamente as 6 dimensões do pedido (Seção 1: Necessidade,
 * Contratação, Promessa, Recebimento, Disponibilidade Física,
 * Compromisso) — NUNCA reduzidas a uma flag booleana ou a uma única cor
 * (Seção 26, decisão explícita: "o futuro motor decide prioridade/ação",
 * esta etapa só preserva as dimensões).
 *
 * **Reutiliza, nunca duplica** (Seção 22/23): a dimensão FÍSICA inteira
 * (Estado/Coberta/DisponivelParaReserva/Parcial/SemEstoque/
 * UnidadeIncompativel, físico/reservado/livre da obra, reservado desta
 * atividade) vem 100% de `App\Support\Estoque\
 * CoberturaNecessidadeAtividadeQuery::porAtividade()`, chamada UMA VEZ
 * por Atividade (nunca por necessidade) — este serviço nunca reimplementa
 * saldo físico/reserva.
 *
 * **Proveniência SEMPRE explícita** (Seção 35): toda quantidade
 * comercial (`quantidade_em_rc`/`quantidade_adjudicada`/`quantidade_pedida`)
 * vem exclusivamente da cadeia FK explícita já aprovada nas Etapas 1/2
 * (`AtividadeNecessidadeMaterial::parcelasRequisicaoCompra()`/
 * `quantidadeAdjudicadaAtiva()`/`quantidadePedidaOficial()`) — NUNCA por
 * Material/Pacote/fornecedor em comum (heurística explicitamente
 * proibida pelo pedido).
 *
 * **Recebimento — limitação de granularidade real, nunca inferida**
 * (Seção 17/18): `RecebimentoPedido` é registrado por `PedidoCompraItem`
 * (o item inteiro), nunca por `PedidoCompraItemParcela` — fresh-read
 * confirmou que essa granularidade simplesmente não existe no domínio
 * hoje. Quando o item que atende esta necessidade tem MAIS de 1 parcela
 * (dividido entre 2+ necessidades), o recebimento do item NÃO é
 * atribuível com certeza a esta necessidade específica — conta como 0
 * aqui, nunca uma estimativa proporcional, e a flag
 * `QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela` é
 * sempre incluída.
 *
 * **Data de necessidade** (Seção 12): `Atividade::inicio_planejado` —
 * a MESMA autoridade já usada por `SincronizarRestricaoCadeiaSuprimento`/
 * `ItemSuprimento::necessidade()` — nunca tendência, nunca baseline.
 * `null` quando a Atividade está `fora_do_cronograma` ou nunca teve
 * `inicio_planejado` importado.
 *
 * **Múltiplos Pedidos — tratamento quantitativo, nunca MIN/MAX** (Seção
 * 18/19): `curvaDePromessa()` classifica CADA fatia de quantidade pedida
 * (por `PedidoCompraItemParcela`, sempre Pedido `Emitido`) contra a data
 * de necessidade, produzindo `qtd_prometida_ate_data_necessidade`/
 * `qtd_prometida_depois_data_necessidade`/`qtd_sem_prazo`/`qtd_nao_pedida`
 * — nunca resume um Pedido cedo + um Pedido tarde numa única data
 * MIN/MAX que perderia quantidade.
 *
 * **Custo — batch-safe, O(1) em queries por Atividade** (achado da
 * integração desta classe no popup do Plano Semanal — Etapa 3, seção 29
 * — a mesma superfície já garantia custo praticamente constante via
 * `PlanoSemanalPostoOperacionalTest::
 * test_m_popup_com_varias_necessidades_nao_gera_n_mais_1`; a versão
 * original desta classe fazia N chamadas por necessidade e quebrava
 * essa garantia): `porAtividade()` faz um número FIXO de queries em
 * lote (parcelas de RC, adjudicações, parcelas de Pedido + eager-load
 * de recebimentos, contagem de parcelas por PedidoCompraItem), nunca
 * mais uma consulta por necessidade — mesmo padrão já usado por
 * `CoberturaNecessidadeAtividadeQuery::porAtividade()`, que este
 * serviço continua reaproveitando sem duplicar.
 */
class EstadoAtendimentoNecessidadeMaterialQuery
{
    /**
     * @return Collection<int, array> uma linha por AtividadeNecessidadeMaterial
     */
    public static function porAtividade(Atividade $atividade): Collection
    {
        $necessidades = AtividadeNecessidadeMaterial::query()
            ->where('atividade_id', $atividade->id)
            ->get();

        if ($necessidades->isEmpty()) {
            return collect();
        }

        $necessidadeIds = $necessidades->pluck('id');

        $coberturaFisicaPorNecessidade = \App\Support\Estoque\CoberturaNecessidadeAtividadeQuery::porAtividade($atividade)
            ->keyBy(fn (array $linha) => $linha['necessidade']->id);

        $dataNecessidade = self::dataDeNecessidade($atividade);

        // ---- Lote: parcelas de RC (Etapa 1) desta atividade, com o RC dono já carregado ----
        $todasParcelasRc = RequisicaoCompraItemParcela::query()
            ->whereIn('atividade_necessidade_material_id', $necessidadeIds)
            ->with('requisicaoCompraItem.requisicaoCompra:id,status')
            ->get(['id', 'atividade_necessidade_material_id', 'requisicao_compra_item_id', 'quantidade']);
        $parcelasRcPorNecessidade = $todasParcelasRc->groupBy('atividade_necessidade_material_id');

        // ---- Lote: adjudicação ativa sobre QUALQUER parcela de RC acima (independente do status do RC — mesmo critério de quantidadeAdjudicadaAtiva() original) ----
        $todosParcelaIdsRc = $todasParcelasRc->pluck('id');
        $adjudicadaPorParcelaRc = $todosParcelaIdsRc->isEmpty() ? collect() : RequisicaoCompraAdjudicacaoItem::query()
            ->whereIn('requisicao_compra_item_parcela_id', $todosParcelaIdsRc)
            ->whereHas('adjudicacao', fn ($q) => $q->where('status', StatusAdjudicacaoRequisicaoCompra::Ativa))
            ->get(['requisicao_compra_item_parcela_id', 'quantidade'])
            ->groupBy('requisicao_compra_item_parcela_id')
            ->map(fn ($grupo) => (float) $grupo->sum('quantidade'));

        // ---- Lote: parcelas de Pedido Emitido (Etapa 1/3), com item+recebimentos+pedido+itens-do-pedido já carregados ----
        $todasParcelasPedido = PedidoCompraItemParcela::query()
            ->whereIn('atividade_necessidade_material_id', $necessidadeIds)
            ->whereHas('pedidoCompraItem.pedidoCompra', fn ($q) => $q->where('status', StatusPedidoCompra::Emitido))
            ->with(['pedidoCompraItem.recebimentos', 'pedidoCompraItem.pedidoCompra.itens.recebimentos'])
            ->get(['id', 'atividade_necessidade_material_id', 'pedido_compra_item_id', 'quantidade']);
        $parcelasPedidoPorNecessidade = $todasParcelasPedido->groupBy('atividade_necessidade_material_id');

        // ---- Lote: total de parcelas por PedidoCompraItem (across QUALQUER necessidade, mesmo critério da versão original) ----
        $pedidoItemIds = $todasParcelasPedido->pluck('pedido_compra_item_id')->unique();
        $totalParcelasPorItem = $pedidoItemIds->isEmpty() ? collect() : PedidoCompraItemParcela::query()
            ->whereIn('pedido_compra_item_id', $pedidoItemIds)
            ->selectRaw('pedido_compra_item_id, count(*) as total')
            ->groupBy('pedido_compra_item_id')
            ->pluck('total', 'pedido_compra_item_id');

        // ---- Lote (Fechamento Adversarial, Seção 19): soma de TODAS as
        // parcelas por item (não só a contagem) — necessário pra saber se
        // uma única parcela realmente cobre o item INTEIRO, nunca só
        // "existe 1 parcela" (uma parcela de 40 num item de 100 nunca pode
        // herdar 100% do recebimento do item). ----
        $somaParcelasPorItem = $pedidoItemIds->isEmpty() ? collect() : PedidoCompraItemParcela::query()
            ->whereIn('pedido_compra_item_id', $pedidoItemIds)
            ->selectRaw('pedido_compra_item_id, SUM(quantidade) as total')
            ->groupBy('pedido_compra_item_id')
            ->pluck('total', 'pedido_compra_item_id')
            ->map(fn ($v) => (float) $v);

        // ---- Lote (Fechamento Adversarial, Seção 6-18): distribuição
        // EXPLÍCITA de recebimento por parcela — quando existe, é SEMPRE
        // a fonte de verdade primária, nunca a heurística de "1 parcela". ----
        $distribuicaoExplicitaPorParcela = \App\Support\Suprimentos\PoliticaDistribuicaoRecebimento::totalAtribuidoPorParcelaEmLote(
            $todasParcelasPedido->pluck('id')->all()
        );

        return $necessidades->map(fn (AtividadeNecessidadeMaterial $n) => self::montarLinha(
            $n,
            $coberturaFisicaPorNecessidade->get($n->id),
            $dataNecessidade,
            $parcelasRcPorNecessidade->get($n->id, collect()),
            $adjudicadaPorParcelaRc,
            $parcelasPedidoPorNecessidade->get($n->id, collect()),
            $totalParcelasPorItem,
            $somaParcelasPorItem,
            $distribuicaoExplicitaPorParcela,
        ))->values();
    }

    /** Conveniência pra 1 necessidade isolada (ex.: painel do Pedido) — chama porAtividade() e filtra. */
    public static function porNecessidade(AtividadeNecessidadeMaterial $necessidade): array
    {
        $atividade = $necessidade->atividade;

        return self::porAtividade($atividade)->firstWhere('necessidade.id', $necessidade->id)
            ?? self::montarLinha($necessidade, null, self::dataDeNecessidade($atividade), collect(), collect(), collect(), collect(), collect(), collect());
    }

    private static function dataDeNecessidade(?Atividade $atividade): ?Carbon
    {
        if (! $atividade || $atividade->fora_do_cronograma || ! $atividade->inicio_planejado) {
            return null;
        }

        return $atividade->inicio_planejado;
    }

    private static function montarLinha(
        AtividadeNecessidadeMaterial $necessidade,
        ?array $linhaFisica,
        ?Carbon $dataNecessidade,
        Collection $parcelasRcDaNecessidade,
        Collection $adjudicadaPorParcelaRc,
        Collection $parcelasPedidoDaNecessidade,
        Collection $totalParcelasPorItem,
        Collection $somaParcelasPorItem,
        Collection $distribuicaoExplicitaPorParcela,
    ): array {
        $necessaria = (float) $necessidade->quantidade_necessaria;

        // "Em RC" — soma das parcelas cujo RC dono já está Emitida/Concluída
        // (mesmo critério de AtividadeNecessidadeMaterial::quantidadeDetalhadaOficialEmRc()).
        $emRc = (float) $parcelasRcDaNecessidade
            ->filter(fn (RequisicaoCompraItemParcela $p) => in_array(
                $p->requisicaoCompraItem?->requisicaoCompra?->status,
                [StatusRequisicaoCompra::Emitida, StatusRequisicaoCompra::Concluida],
                true
            ))
            ->sum('quantidade');

        // Adjudicada — soma via o mapa em lote, sobre TODAS as parcelas de RC
        // desta necessidade (independente do status do RC — mesmo critério
        // de AtividadeNecessidadeMaterial::quantidadeAdjudicadaAtiva()).
        $adjudicada = (float) $parcelasRcDaNecessidade->sum(
            fn (RequisicaoCompraItemParcela $p) => $adjudicadaPorParcelaRc->get($p->id, 0.0)
        );

        // Pedida — soma das parcelas de Pedido já filtradas por Pedido Emitido
        // (mesmo critério de AtividadeNecessidadeMaterial::quantidadePedidaOficial()).
        $pedida = (float) $parcelasPedidoDaNecessidade->sum('quantidade');

        $qualidade = [];
        if (! $dataNecessidade) {
            $qualidade[] = QualidadeInformacaoAtendimento::SemDataDeNecessidade;
        }

        [$recebida, $flagsRecebimento] = self::calcularRecebimento(
            $parcelasPedidoDaNecessidade,
            $totalParcelasPorItem,
            $somaParcelasPorItem,
            $distribuicaoExplicitaPorParcela,
        );
        $qualidade = array_merge($qualidade, $flagsRecebimento);

        $curva = self::curvaDePromessa(
            $parcelasPedidoDaNecessidade,
            $dataNecessidade,
            $pedida,
            $necessaria,
            $totalParcelasPorItem,
            $somaParcelasPorItem,
            $distribuicaoExplicitaPorParcela,
        );
        if ($curva['ambiguo']) {
            $qualidade[] = QualidadeInformacaoAtendimento::PromessaAmbiguaMultiploPedido;
        }

        $folgaDias = null;
        if ($dataNecessidade && $curva['data_unica_relevante']) {
            $folgaDias = self::calcularFolgaDias($dataNecessidade, $curva['data_unica_relevante']);
        }

        $estadoComercial = self::classificarComercial($necessaria, $emRc, $adjudicada, $pedida, $recebida, $curva);

        $reservadoAtividade = (float) ($linhaFisica['reservado_atividade'] ?? $necessidade->quantidadeReservadaAtiva());
        $estadoCompromisso = self::classificarCompromisso($necessaria, $reservadoAtividade);

        $decomposicao = self::calcularDecomposicaoQuantitativa(
            $necessaria,
            $reservadoAtividade,
            $linhaFisica,
            $recebida,
            $pedida,
            $adjudicada,
            $emRc,
            $curva,
        );

        return [
            'necessidade' => $necessidade,
            'quantidade_necessaria' => $necessaria,
            'quantidade_em_rc' => $emRc,
            'quantidade_adjudicada' => $adjudicada,
            'quantidade_pedida' => $pedida,
            'quantidade_recebida' => $recebida,
            'quantidade_fisica_relevante' => $linhaFisica['fisico_obra'] ?? null,
            'quantidade_reservada_especifica' => $reservadoAtividade,
            'quantidade_livre_relevante' => $linhaFisica['livre_obra'] ?? null,
            'data_necessidade' => $dataNecessidade,
            'data_prometida_relevante' => $curva['data_unica_relevante'],
            'folga_dias' => $folgaDias,
            'estado_comercial' => $estadoComercial,
            'estado_fisico' => $linhaFisica['estado'] ?? \App\Enums\EstadoNecessidadeMaterialAtividade::SemNecessidadeCadastrada,
            'estado_compromisso' => $estadoCompromisso,
            'qtd_prometida_ate_data_necessidade' => $curva['qtd_ate'],
            'qtd_prometida_depois_data_necessidade' => $curva['qtd_depois'],
            'qtd_sem_prazo' => $curva['qtd_sem_prazo'],
            'qtd_nao_pedida' => $curva['qtd_nao_pedida'],
            'qualidade_informacao' => array_values(array_unique($qualidade, SORT_REGULAR)),
        ] + $decomposicao;
    }

    /**
     * Motor Definitivo de Risco de Suprimentos V1 — decomposição
     * quantitativa EXCLUSIVA (Seções 3-20 do pedido de implementação):
     * as 10 categorias abaixo NUNCA se sobrepõem e SEMPRE somam
     * exatamente `$necessaria` (garantido por construção — waterfall
     * "camada consome só o que sobrou da camada anterior", nunca uma
     * soma calculada à parte que poderia divergir por arredondamento).
     * Nunca reimplementa nenhuma regra já existente — só reparticiona os
     * MESMOS totais cumulativos (`$emRc`/`$adjudicada`/`$pedida`/
     * `$recebida`/`$linhaFisica`) que `montarLinha()` já calculou.
     *
     * **Fechamento Adversarial Final — correção do Achado 1**: `recebida`
     * (fato COMERCIAL, `RecebimentoPedido`) NUNCA é a mesma coisa que
     * `livre_obra` (fato FÍSICO, ledger `MovimentacaoEstoque`) — provado
     * por fresh-read de `RegistrarRecebimentoPedido`/`RegistrarEntradaEstoque`
     * (Ciclo 19.6/20.1): um recebimento nunca cria `MovimentacaoEstoque`
     * sozinho, pode existir sem nenhum `LocalEstoque`, e "Dar entrada" é
     * sempre uma ação humana SEPARADA e possivelmente tardia. Por isso a
     * versão anterior desta decomposição (que somava `recebida` direto
     * dentro de `decomposicao_disponivel`) podia mostrar "100 disponível"
     * quando o ledger físico da obra dizia 0 — um estoque fictício.
     * Corrigido separando em 2 categorias, mesma taxonomia já usada em
     * `App\Enums\EstadoCoberturaMaterial::RecebidoAguardandoDisponibilizacao`
     * (Ciclo 21.1, precedente direto no próprio codebase): `disponivel`
     * volta a significar SÓ `livre_obra` (ledger-confirmado); o recebido
     * ainda não incorporado vira `decomposicao_recebida_aguardando_disponibilizacao`,
     * nunca somado ao mesmo bucket — nenhum dos dois nunca vira Reserva
     * sem existir de verdade (Seção 7).
     *
     * Ordem das camadas (a mais protegida primeiro, Seção 4):
     * 1) Reservado (`ReservaEstoque` específica desta necessidade).
     * 2) Disponível (estoque livre da obra — SÓ ledger, `livre_obra`).
     * 3) Recebida aguardando disponibilização (recebimento comercial já
     *    ocorrido, ainda sem `MovimentacaoEstoque` correspondente — nunca
     *    continua "dependente de fornecimento futuro", Seção 19, mas
     *    também nunca é tratada como fisicamente livre de verdade).
     * 4) Dependente de fornecimento (`pedidaPendente = pedida - recebida`,
     *    nunca a `pedida` bruta — Seção 19), dividida em no prazo/
     *    atrasado/sem prazo pela MESMA curva de promessa já calculada
     *    (nunca um segundo cálculo de data). Ambiguidade/atraso comercial
     *    hoje/ausência de data de necessidade sempre vira "atrasado"
     *    (nunca otimismo por omissão — Seção 13/31).
     * 5) Adjudicada sem Pedido, depois Em processo (RC sem adjudicação).
     * 6) Sem cobertura comercial (nem RC).
     * Residual: informação insuficiente (rede de segurança — só > 0 se
     * algum dado upstream estiver inconsistente; nunca esperado > 0 em
     * dado consistente).
     */
    private static function calcularDecomposicaoQuantitativa(
        float $necessaria,
        float $reservadoAtividade,
        ?array $linhaFisica,
        float $recebida,
        float $pedida,
        float $adjudicada,
        float $emRc,
        array $curva,
    ): array {
        // Unidade incompatível (Seção 31/Seção 12 do CoberturaNecessidadeAtividadeQuery):
        // nenhuma comparação física é confiável quando a necessidade está
        // cadastrada numa unidade diferente da canônica do Material —
        // NUNCA um zero mentiroso, sempre Informação Insuficiente pra
        // necessidade inteira (nunca uma mistura "física insuficiente,
        // comercial preciso" que soaria mais confiável do que realmente é).
        if ($linhaFisica !== null && ($linhaFisica['unidade_compativel'] ?? true) === false) {
            return [
                'decomposicao_reservado' => 0.0,
                'decomposicao_disponivel' => 0.0,
                'decomposicao_recebida_aguardando_disponibilizacao' => 0.0,
                'decomposicao_dependente_no_prazo' => 0.0,
                'decomposicao_dependente_atrasado' => 0.0,
                'decomposicao_pedida_sem_prazo' => 0.0,
                'decomposicao_adjudicada_sem_pedido' => 0.0,
                'decomposicao_em_processo' => 0.0,
                'decomposicao_sem_cobertura' => 0.0,
                'decomposicao_informacao_insuficiente' => round(max(0.0, $necessaria), 3),
                'estado_gerencial' => EstadoGerencialNecessidade::InformacaoInsuficiente,
                'parcialmente_coberto' => false,
            ];
        }

        $restante = round(max(0.0, $necessaria), 3);

        $reservado = round(min($restante, max(0.0, $reservadoAtividade)), 3);
        $restante = round($restante - $reservado, 3);

        // Camada 2 — SÓ o ledger físico (`livre_obra`), nunca misturado
        // com recebimento comercial ainda não incorporado.
        $livreObra = $linhaFisica['livre_obra'] ?? null;
        $disponivel = $livreObra === null ? 0.0 : round(min($restante, max(0.0, $livreObra)), 3);
        $restante = round($restante - $disponivel, 3);

        // Camada 3 — recebido fisicamente mas ainda sem `MovimentacaoEstoque`
        // correspondente (chegou, ainda não passou por "Dar entrada" no
        // estoque). Nunca continua "dependente de fornecimento futuro"
        // (Seção 19), mas também nunca é contado como `disponivel`
        // ledger-confirmado — categoria própria, nunca uma Reserva sem
        // existir de verdade (Seção 7).
        $recebidaAguardandoDisponibilizacao = round(min($restante, max(0.0, $recebida)), 3);
        $restante = round($restante - $recebidaAguardandoDisponibilizacao, 3);

        $pedidaPendente = round(max(0.0, $pedida - $recebida), 3);
        $pedidaAplicavel = round(min($restante, $pedidaPendente), 3);
        $restante = round($restante - $pedidaAplicavel, 3);

        $dependenteNoPrazo = 0.0;
        $dependenteAtrasado = 0.0;
        $pedidaSemPrazo = 0.0;

        if ($pedidaAplicavel > 0.0005) {
            $semPrazoBruto = (float) ($curva['qtd_sem_prazo'] ?? 0.0);
            $pedidaSemPrazo = $pedida > 0.0005
                ? round(min($pedidaAplicavel, $pedidaAplicavel * ($semPrazoBruto / $pedida)), 3)
                : 0.0;

            $restanteComData = round($pedidaAplicavel - $pedidaSemPrazo, 3);

            // `qtd_ate`/`qtd_depois` já são somados POR PARCELA (cada uma
            // contra sua própria data) dentro de `curvaDePromessa()` —
            // continuam corretos mesmo quando `ambiguo` é true (ambiguo só
            // significa "sem 1 única data_unica_relevante pra folga_dias",
            // nunca "não sei separar antes/depois"). Gate correto é só a
            // ausência de dataNecessidade (aí sim os dois ficam `null`).
            $podeClassificarPorData = $curva['qtd_ate'] !== null && $curva['qtd_depois'] !== null;

            if ($restanteComData > 0.0005 && $podeClassificarPorData && ! $curva['algum_pedido_atrasado']) {
                $baseDatada = round((float) $curva['qtd_ate'] + (float) $curva['qtd_depois'], 3);
                $fatorNoPrazo = $baseDatada > 0.0005 ? ((float) $curva['qtd_ate'] / $baseDatada) : 0.0;
                $dependenteNoPrazo = round($restanteComData * $fatorNoPrazo, 3);
                $dependenteAtrasado = round($restanteComData - $dependenteNoPrazo, 3);
            } elseif ($restanteComData > 0.0005) {
                // Ambíguo (2+ datas distintas), comercialmente atrasado
                // HOJE, ou sem data de necessidade conhecida — nunca finge
                // uma classificação otimista sem certeza; sempre o sinal
                // mais forte (Seção 13/31).
                $dependenteAtrasado = $restanteComData;
            }
        }

        $adjudicadaSemPedido = round(min($restante, max(0.0, $adjudicada - $pedida)), 3);
        $restante = round($restante - $adjudicadaSemPedido, 3);

        $emProcesso = round(min($restante, max(0.0, $emRc - $adjudicada)), 3);
        $restante = round($restante - $emProcesso, 3);

        $semCobertura = round(min($restante, max(0.0, $necessaria - $emRc)), 3);
        $restante = round($restante - $semCobertura, 3);

        $informacaoInsuficiente = round(max(0.0, $restante), 3);

        // Ranking editorial (mesmo espírito de `EstadoCoberturaMaterial::
        // severidade()`, Ciclo 21.1): "recebida aguardando disponibilização"
        // é objetivamente MELHOR que qualquer dependência de fornecimento
        // futuro (o material já chegou fisicamente), mas ainda carrega uma
        // etapa manual pendente ("Dar entrada") — nunca tão bom quanto
        // `disponivel` (já ledger-confirmado, Camada 2), nunca tratada
        // como `Protegida` por omissão.
        $estadoGerencial = match (true) {
            $informacaoInsuficiente > 0.0005 => EstadoGerencialNecessidade::InformacaoInsuficiente,
            $semCobertura > 0.0005 => EstadoGerencialNecessidade::NaoContratada,
            $emProcesso > 0.0005 => EstadoGerencialNecessidade::EmProcesso,
            $adjudicadaSemPedido > 0.0005 => EstadoGerencialNecessidade::PrePedido,
            $pedidaSemPrazo > 0.0005 => EstadoGerencialNecessidade::SemPrazo,
            $dependenteAtrasado > 0.0005 => EstadoGerencialNecessidade::DependenteFornecimentoAtrasado,
            $dependenteNoPrazo > 0.0005 => EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo,
            $recebidaAguardandoDisponibilizacao > 0.0005 => EstadoGerencialNecessidade::RecebidaAguardandoDisponibilizacao,
            $disponivel > 0.0005 => EstadoGerencialNecessidade::DisponivelNaoReservada,
            default => EstadoGerencialNecessidade::Protegida,
        };

        return [
            'decomposicao_reservado' => $reservado,
            'decomposicao_disponivel' => $disponivel,
            'decomposicao_recebida_aguardando_disponibilizacao' => $recebidaAguardandoDisponibilizacao,
            'decomposicao_dependente_no_prazo' => $dependenteNoPrazo,
            'decomposicao_dependente_atrasado' => $dependenteAtrasado,
            'decomposicao_pedida_sem_prazo' => $pedidaSemPrazo,
            'decomposicao_adjudicada_sem_pedido' => $adjudicadaSemPedido,
            'decomposicao_em_processo' => $emProcesso,
            'decomposicao_sem_cobertura' => $semCobertura,
            'decomposicao_informacao_insuficiente' => $informacaoInsuficiente,
            'estado_gerencial' => $estadoGerencial,
            'parcialmente_coberto' => ($reservado + $disponivel + $recebidaAguardandoDisponibilizacao) > 0.0005
                && ($reservado + $disponivel + $recebidaAguardandoDisponibilizacao) < $necessaria - 0.0005,
        ];
    }

    /**
     * Sinal EXPLÍCITO (Seção 16) — nunca `diffInDays()` de 2 argumentos
     * (semântica de sinal ambígua entre versões/uso do Carbon já
     * documentada como risco pelo próprio pedido). Sempre compara com
     * `gte()`/`lt()` primeiro, só então usa `diffInDays()` (1 argumento,
     * sempre positivo) — mesmo padrão já usado em
     * `PedidoCompra::diasAtrasoAtual()`/`diasAtrasoFinal()`.
     */
    private static function calcularFolgaDias(Carbon $dataNecessidade, Carbon $dataPrometida): int
    {
        if ($dataNecessidade->gte($dataPrometida)) {
            return (int) $dataPrometida->diffInDays($dataNecessidade);
        }

        return -1 * (int) $dataNecessidade->diffInDays($dataPrometida);
    }

    /**
     * Atribuição de recebimento a UMA parcela — extraído como método
     * ÚNICO (Fechamento Adversarial Final, correção do Achado 2) pra
     * ser reaproveitado tanto por `calcularRecebimento()` (soma agregada)
     * quanto por `curvaDePromessa()` (subtração POR PARCELA antes de
     * bucketar por data). Antes desta correção, `curvaDePromessa()` usava
     * a quantidade BRUTA de cada parcela pra somar `qtd_ate`/`qtd_depois`,
     * e `calcularDecomposicaoQuantitativa()` só reduzia o AGREGADO
     * `pedidaPendente = pedida - recebida` desse total bruto — quando 2+
     * Pedidos coexistiam e só UM deles tinha sido recebido, o resultado
     * era redistribuído PROPORCIONALMENTE sobre a proporção bruta
     * qtd_ate/qtd_depois, produzindo uma composição temporal
     * numericamente errada (provado: P1=40 cedo 100% recebido + P2=60
     * tarde 0% recebido produzia "24 no prazo / 36 atrasado" em vez do
     * correto "0 no prazo / 60 atrasado" — o recebimento de P1 vazava
     * pra "proteger" uma fração de P2, que nunca foi recebido). Corrigido
     * subtraindo o recebido de CADA parcela da SUA PRÓPRIA quantidade
     * antes de somar em qualquer bucket — nunca mais uma proporção
     * agregada. Retorna `null` quando não é possível saber com certeza
     * quanto desta parcela específica já foi recebido (mesmos 2 critérios
     * de sempre: distribuição explícita, ou heurística de parcela única
     * cobrindo o item inteiro) — o chamador nunca deve tratar `null` como
     * "recebeu tudo" nem como "recebeu 0" silenciosamente confiante;
     * ambos os chamadores tratam `null` como 0 pra fins de subtração
     * (nunca reduz otimisticamente o que ainda é desconhecido — Seção 14).
     */
    private static function recebidoAtribuivelAParcela(
        PedidoCompraItemParcela $parcela,
        Collection $totalParcelasPorItem,
        Collection $somaParcelasPorItem,
        Collection $distribuicaoExplicitaPorParcela,
    ): ?float {
        $item = $parcela->pedidoCompraItem;
        if (! $item) {
            return null;
        }

        // 1) Distribuição explícita sempre vence, quando existe.
        $explicito = (float) $distribuicaoExplicitaPorParcela->get($parcela->id, 0.0);
        if ($explicito > 0.0005) {
            return min($explicito, (float) $parcela->quantidade);
        }

        // 2) Heurística segura: 1 única parcela E ela cobre o item
        // inteiro (nunca só "existe 1 parcela").
        $totalParcelasDoItem = (int) ($totalParcelasPorItem->get($item->id) ?? 0);
        $somaParcelasDoItem = (float) ($somaParcelasPorItem->get($item->id) ?? 0.0);
        $itemTotalmenteCobertoPorEstaParcela = $totalParcelasDoItem === 1
            && abs($somaParcelasDoItem - (float) $item->quantidade_pedida) <= 0.0005;

        if ($itemTotalmenteCobertoPorEstaParcela) {
            return min($item->quantidadeRecebida(), (float) $parcela->quantidade);
        }

        // 3) Não atribuível com certeza — nunca uma estimativa proporcional.
        return null;
    }

    /**
     * Recebimento por necessidade — Fechamento Adversarial Etapa 3
     * (Seções 6-19), sempre via `recebidoAtribuivelAParcela()` — nunca
     * uma segunda regra de atribuição.
     *
     * @return array{0: float, 1: QualidadeInformacaoAtendimento[]}
     */
    private static function calcularRecebimento(
        Collection $parcelasPedidoDaNecessidade,
        Collection $totalParcelasPorItem,
        Collection $somaParcelasPorItem,
        Collection $distribuicaoExplicitaPorParcela,
    ): array {
        if ($parcelasPedidoDaNecessidade->isEmpty()) {
            return [0.0, []];
        }

        $total = 0.0;
        $flags = [];

        foreach ($parcelasPedidoDaNecessidade as $parcela) {
            if (! $parcela->pedidoCompraItem) {
                continue;
            }

            $recebido = self::recebidoAtribuivelAParcela(
                $parcela, $totalParcelasPorItem, $somaParcelasPorItem, $distribuicaoExplicitaPorParcela
            );

            if ($recebido === null) {
                $flags[] = QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela;

                continue;
            }

            $total += $recebido;
        }

        return [round($total, 3), $flags];
    }

    /**
     * Curva quantitativa de promessa — Seção 18/19. Cada FATIA
     * (`PedidoCompraItemParcela`, Pedido `Emitido`) é classificada
     * independentemente contra a data de necessidade — nunca um MIN/MAX
     * agregado que perderia quantidade.
     *
     * **Correção do Achado 2 (proporcionalidade indevida)**: cada parcela
     * contribui pra `qtd_ate`/`qtd_depois`/`qtd_sem_prazo` com sua
     * quantidade PENDENTE (`quantidade - recebidoDestaParcela`), nunca a
     * quantidade bruta — uma parcela já integralmente recebida CONTINUA
     * sendo ignorada por completo aqui (Seção 12: "o recebimento de P1
     * não pode ser abatido genericamente do total de forma que altere a
     * composição temporal de P2"), nunca reduzida do agregado depois.
     */
    private static function curvaDePromessa(
        Collection $parcelasPedidoDaNecessidade,
        ?Carbon $dataNecessidade,
        float $quantidadePedida,
        float $necessaria,
        Collection $totalParcelasPorItem,
        Collection $somaParcelasPorItem,
        Collection $distribuicaoExplicitaPorParcela,
    ): array {
        $qtdAte = 0.0;
        $qtdDepois = 0.0;
        $qtdSemPrazo = 0.0;
        $datasDistintas = [];
        $pedidosAtrasados = false;

        foreach ($parcelasPedidoDaNecessidade as $parcela) {
            $pedido = $parcela->pedidoCompraItem?->pedidoCompra;
            if (! $pedido) {
                continue;
            }

            $recebidoParcela = self::recebidoAtribuivelAParcela(
                $parcela, $totalParcelasPorItem, $somaParcelasPorItem, $distribuicaoExplicitaPorParcela
            ) ?? 0.0;

            $qtd = round(max(0.0, (float) $parcela->quantidade - $recebidoParcela), 3);
            if ($qtd <= 0.0005) {
                // Esta parcela já foi integralmente recebida — não
                // contribui mais pra nenhum bucket de dependência futura
                // (nem sequer conta como "data conhecida" pra ambiguidade/
                // folga, Seção 16 — um compromisso já cumprido não deveria
                // continuar aparecendo como promessa em aberto).
                continue;
            }

            $data = $pedido->data_prevista_entrega;

            if (! $data) {
                $qtdSemPrazo += $qtd;
            } else {
                $datasDistintas[$data->toDateString()] = true;

                if ($dataNecessidade) {
                    if ($data->lte($dataNecessidade)) {
                        $qtdAte += $qtd;
                    } else {
                        $qtdDepois += $qtd;
                    }
                }
            }

            if ($pedido->diasAtrasoAtual() !== null) {
                $pedidosAtrasados = true;
            }
        }

        $qtdNaoPedida = round(max(0, $necessaria - $quantidadePedida), 3);

        $ambiguo = count($datasDistintas) > 1;
        $dataUnica = (! $ambiguo && $qtdSemPrazo <= 0.0005 && count($datasDistintas) === 1)
            ? Carbon::parse(array_key_first($datasDistintas))
            : null;

        return [
            'qtd_ate' => $dataNecessidade ? round($qtdAte, 3) : null,
            'qtd_depois' => $dataNecessidade ? round($qtdDepois, 3) : null,
            'qtd_sem_prazo' => round($qtdSemPrazo, 3),
            'qtd_nao_pedida' => $qtdNaoPedida,
            'data_unica_relevante' => $dataUnica,
            'ambiguo' => $ambiguo,
            'algum_pedido_atrasado' => $pedidosAtrasados,
            'algum_sem_prazo' => $qtdSemPrazo > 0.0005,
        ];
    }

    /**
     * Árvore de decisão ÚNICA (Seção 15) — waterfall do estágio mais
     * completo pro menos completo. `PedidaAtrasada` (fato atual, Pedido
     * já comercialmente atrasado HOJE) sempre vence `PedidaEmRisco`
     * (projeção — a promessa aponta pra depois da necessidade, mas o
     * prazo em si ainda não venceu) — Seção 15: "responde se o processo
     * está compatível", nunca "se a atividade está protegida".
     */
    private static function classificarComercial(float $necessaria, float $emRc, float $adjudicada, float $pedida, float $recebida, array $curva): EstadoComercialNecessidadeMaterial
    {
        if ($necessaria > 0.0005 && $recebida >= $necessaria - 0.0005) {
            return EstadoComercialNecessidadeMaterial::Recebida;
        }

        if ($recebida > 0.0005) {
            return EstadoComercialNecessidadeMaterial::RecebidaParcial;
        }

        if ($pedida > 0.0005) {
            if ($curva['algum_pedido_atrasado']) {
                return EstadoComercialNecessidadeMaterial::PedidaAtrasada;
            }

            if ($curva['algum_sem_prazo']) {
                return EstadoComercialNecessidadeMaterial::PedidaSemPrazo;
            }

            if ($curva['qtd_depois'] !== null && $curva['qtd_depois'] > 0.0005) {
                return EstadoComercialNecessidadeMaterial::PedidaEmRisco;
            }

            if ($curva['qtd_depois'] === null) {
                // Sem data de necessidade conhecida (Atividade fora do
                // cronograma/sem início importado) — não dá pra afirmar
                // "no prazo" nem "em risco" sem inventar uma comparação;
                // fica sinalizado só via `qualidade_informacao`.
                return EstadoComercialNecessidadeMaterial::PedidaNoPrazo;
            }

            return EstadoComercialNecessidadeMaterial::PedidaNoPrazo;
        }

        if ($adjudicada > 0.0005) {
            return EstadoComercialNecessidadeMaterial::Adjudicada;
        }

        if ($emRc > 0.0005) {
            return EstadoComercialNecessidadeMaterial::EmProcesso;
        }

        return EstadoComercialNecessidadeMaterial::NaoContratada;
    }

    /**
     * Seção 24 — só existe compromisso físico REAL com reserva
     * específica (`reservado_atividade`, já a mesma fonte de
     * `CoberturaNecessidadeAtividadeQuery`) — estoque livre NUNCA vira
     * reserva virtual aqui (Seção 23).
     */
    private static function classificarCompromisso(float $necessaria, float $reservadoAtividade): EstadoCompromissoNecessidadeMaterial
    {
        if ($reservadoAtividade <= 0.0005) {
            return EstadoCompromissoNecessidadeMaterial::NaoReservada;
        }

        if ($necessaria > 0.0005 && $reservadoAtividade >= $necessaria - 0.0005) {
            return EstadoCompromissoNecessidadeMaterial::ReservadaIntegralmente;
        }

        return EstadoCompromissoNecessidadeMaterial::ReservadaParcialmente;
    }
}
