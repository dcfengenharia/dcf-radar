<?php

namespace App\Enums;

/**
 * Motor Definitivo de Risco de Suprimentos V1 (Seção 12 do pedido) —
 * estado gerencial ÚNICO, derivado da decomposição quantitativa
 * exclusiva (`EstadoAtendimentoNecessidadeMaterialQuery::
 * calcularDecomposicaoQuantitativa()`), nunca uma segunda fonte de
 * verdade: é sempre uma PROJEÇÃO de prioridade sobre os mesmos 9 campos
 * `decomposicao_*` já calculados (o pior estágio com quantidade > 0
 * vence — Seção 15, "responde se a atividade está PROTEGIDA", ao
 * contrário de `EstadoComercialNecessidadeMaterial`, que responde se o
 * PROCESSO comercial está compatível).
 *
 * **Deliberadamente SEM um estado "EmRisco" por threshold de dias**
 * (Seção 13 do pedido: "Não inventar limiar hardcoded sem configuração
 * existente... Pode V1 distinguir apenas: NoPrazo, Atrasado, SemPrazo e
 * deixar EmRisco para regra posterior/configurável") — nenhuma
 * configuração de horizonte/threshold pra isso foi encontrada no
 * domínio (fresh-read confirmado), então esta enum não a inventa.
 * `DependenteFornecimentoAtrasado` cobre tanto "promessa aponta pra
 * depois da necessidade" (`EstadoComercialNecessidadeMaterial::
 * PedidaEmRisco`, projeção) quanto "Pedido comercialmente atrasado hoje"
 * (`PedidaAtrasada`, fato) — do ponto de vista da ATIVIDADE, as duas
 * significam a mesma coisa: essa fatia de material não vai chegar a
 * tempo, nunca um otimismo por omissão.
 */
enum EstadoGerencialNecessidade: string
{
    /** Necessidade inteira coberta por Reserva/estoque já fisicamente disponível. */
    case Protegida = 'protegida';

    /** Fisicamente disponível como estoque livre já confirmado no ledger da obra (`MovimentacaoEstoque`), mas sem Reserva específica — nunca uma garantia, mesmo assim sem risco de chegada. */
    case DisponivelNaoReservada = 'disponivel_nao_reservada';

    /**
     * Recebida comercialmente (`RecebimentoPedido`), mas ainda sem
     * `MovimentacaoEstoque` correspondente ("Dar entrada" no estoque
     * ainda pendente) — Fechamento Adversarial Final (Achado 1):
     * `RecebimentoPedido` NUNCA implica presença confirmada no ledger
     * físico (provado por fresh-read de `RegistrarRecebimentoPedido`/
     * `RegistrarEntradaEstoque`, Ciclo 19.6/20.1 — os dois fatos são
     * sempre independentes, "Dar entrada" é sempre uma ação humana
     * separada e possivelmente tardia). O material já chegou fisicamente
     * e nunca continua "dependente de fornecimento futuro", mas
     * deliberadamente NÃO é tratado como `DisponivelNaoReservada`
     * (ledger-confirmado) nem como `Protegida` — ainda existe uma etapa
     * manual pendente até a incorporação formal ao estoque. Mesma
     * taxonomia já usada em `App\Enums\EstadoCoberturaMaterial::
     * RecebidoAguardandoDisponibilizacao` (Ciclo 21.1), reaproveitada
     * aqui pela mesma semântica.
     */
    case RecebidaAguardandoDisponibilizacao = 'recebida_aguardando_disponibilizacao';

    /** Depende de Pedido(s) Emitido(s) cuja promessa (vigente) cobre a necessidade antes ou no prazo, sem atraso comercial hoje. */
    case DependenteFornecimentoNoPrazo = 'dependente_fornecimento_no_prazo';

    /** Depende de Pedido(s) cuja promessa aponta pra depois da necessidade, OU já está comercialmente atrasado hoje, OU a promessa é ambígua/indeterminável. */
    case DependenteFornecimentoAtrasado = 'dependente_fornecimento_atrasado';

    /** Pedido(s) Emitido(s) sem nenhuma previsão de entrega informada. */
    case SemPrazo = 'sem_prazo';

    /** Adjudicada a um fornecedor, mas ainda sem Pedido/Ordem de Compra emitido. */
    case PrePedido = 'pre_pedido';

    /** Detalhada em Requisição de Compra Emitida/Concluída, mas ainda sem nenhuma adjudicação ativa. */
    case EmProcesso = 'em_processo';

    /** Nenhuma cobertura comercial formal ainda (nem RC). */
    case NaoContratada = 'nao_contratada';

    /** Alguma parcela da necessidade não pôde ser classificada com certeza (unidade incompatível, sem data de necessidade, promessa ambígua) — nunca escondida atrás de um zero silencioso. */
    case InformacaoInsuficiente = 'informacao_insuficiente';

    public function label(): string
    {
        return match ($this) {
            self::Protegida => 'Protegida',
            self::DisponivelNaoReservada => 'Disponível — não reservada',
            self::RecebidaAguardandoDisponibilizacao => 'Recebida — aguardando disponibilização em estoque',
            self::DependenteFornecimentoNoPrazo => 'Dependente de fornecimento — no prazo',
            self::DependenteFornecimentoAtrasado => 'Dependente de fornecimento — atrasado/em risco',
            self::SemPrazo => 'Pedida — sem prazo informado',
            self::PrePedido => 'Adjudicada, sem Pedido',
            self::EmProcesso => 'Em processo de compra',
            self::NaoContratada => 'Não contratada',
            self::InformacaoInsuficiente => 'Informação insuficiente',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Protegida => 'success',
            self::DisponivelNaoReservada => 'success',
            self::RecebidaAguardandoDisponibilizacao => 'info',
            self::DependenteFornecimentoNoPrazo => 'info',
            self::DependenteFornecimentoAtrasado => 'danger',
            self::SemPrazo => 'dark',
            self::PrePedido => 'info',
            self::EmProcesso => 'secondary',
            self::NaoContratada => 'secondary',
            self::InformacaoInsuficiente => 'warning',
        };
    }
}
