<?php

namespace App\Enums;

/**
 * Etapa 3 (Seção 15) — "o processo de aquisição/entrega desta necessidade
 * está comercialmente em situação compatível com ela?" — NUNCA responde
 * "a atividade está fisicamente protegida?" (isso é `EstadoNecessidadeMaterialAtividade`,
 * já existente, Melhoria "Posto Operacional" — deliberadamente um enum
 * separado, nunca fundido). Calculado por `App\Support\Suprimentos\
 * EstadoAtendimentoNecessidadeMaterialQuery`, sempre pela árvore de
 * decisão ÚNICA documentada lá — nunca duplicada em outro ponto.
 *
 * Conjunto MENOR que o listado como exemplo no pedido — `EmProcesso` é a
 * única adição real (RC já detalha a necessidade, mas nenhuma adjudicação
 * ainda — um estágio genuinamente distinguível, ver `quantidade_em_rc`).
 * `PedidaEmRisco` (a promessa aponta pra depois da necessidade, mas o
 * prazo ainda não venceu) é distinto de `PedidaAtrasada` (o Pedido já
 * está comercialmente atrasado HOJE, `PedidoCompra::diasAtrasoAtual()`)
 * — o primeiro é uma PROJEÇÃO, o segundo é um FATO atual.
 */
enum EstadoComercialNecessidadeMaterial: string
{
    case NaoContratada = 'nao_contratada';
    case EmProcesso = 'em_processo';
    case Adjudicada = 'adjudicada';
    case PedidaSemPrazo = 'pedida_sem_prazo';
    case PedidaNoPrazo = 'pedida_no_prazo';
    case PedidaEmRisco = 'pedida_em_risco';
    case PedidaAtrasada = 'pedida_atrasada';
    case RecebidaParcial = 'recebida_parcial';
    case Recebida = 'recebida';

    public function label(): string
    {
        return match ($this) {
            self::NaoContratada => 'Não contratada',
            self::EmProcesso => 'Em processo de compra',
            self::Adjudicada => 'Adjudicada, sem Pedido',
            self::PedidaSemPrazo => 'Pedida — sem prazo informado',
            self::PedidaNoPrazo => 'Pedida — no prazo',
            self::PedidaEmRisco => 'Pedida — promessa em risco',
            self::PedidaAtrasada => 'Pedida — atrasada',
            self::RecebidaParcial => 'Recebida parcialmente',
            self::Recebida => 'Recebida',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::NaoContratada => 'secondary',
            self::EmProcesso => 'info',
            self::Adjudicada => 'info',
            self::PedidaSemPrazo => 'dark',
            self::PedidaNoPrazo => 'success',
            self::PedidaEmRisco => 'warning',
            self::PedidaAtrasada => 'danger',
            self::RecebidaParcial => 'warning',
            self::Recebida => 'success',
        };
    }
}
