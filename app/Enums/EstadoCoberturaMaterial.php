<?php

namespace App\Enums;

/**
 * Ciclo 21, Etapa 21.1 — taxonomia gerencial de cobertura material de um
 * par (Pacote, Material). Nunca simplificada pra "saldo físico > 0" —
 * distingue explicitamente reservado/comprado/recebido/físico (Seção 8
 * do pedido). `InformacaoInsuficiente` é um estado de primeira classe,
 * nunca um fallback silencioso pra "coberto" — é o que impede o sistema
 * de transformar "desconhecido" em "pronto" (Seção 9).
 *
 * Classificação 100% derivada de fatos (`CoberturaMaterialAtividadeQuery`),
 * nunca arbitrária — ver o método `classificar()` lá pra a árvore de
 * decisão exata.
 */
enum EstadoCoberturaMaterial: string
{
    case Coberto = 'coberto';
    case ParcialmenteCoberto = 'parcialmente_coberto';
    case RecebidoAguardandoDisponibilizacao = 'recebido_aguardando_disponibilizacao';
    case CompradoAguardandoRecebimento = 'comprado_aguardando_recebimento';
    case AguardandoCompra = 'aguardando_compra';
    case DeficitAposConsumoEmergencial = 'deficit_apos_consumo_emergencial';
    case SemCobertura = 'sem_cobertura';
    case InformacaoInsuficiente = 'informacao_insuficiente';

    public function label(): string
    {
        return match ($this) {
            self::Coberto => 'Coberto',
            self::ParcialmenteCoberto => 'Parcialmente coberto',
            self::RecebidoAguardandoDisponibilizacao => 'Recebido — aguardando disponibilização',
            self::CompradoAguardandoRecebimento => 'Comprado — aguardando recebimento',
            self::AguardandoCompra => 'Aguardando compra',
            self::DeficitAposConsumoEmergencial => 'Déficit após consumo emergencial',
            self::SemCobertura => 'Sem cobertura',
            self::InformacaoInsuficiente => 'Informação insuficiente',
        };
    }

    /**
     * Ranking editorial (decisão desta etapa, documentada e revisável —
     * nunca um fato objetivo) usado só pra decidir qual estado "vence"
     * quando uma Atividade tem VÁRIOS pares (Pacote, Material) com
     * estados diferentes — o estado agregado da Atividade é sempre o de
     * MAIOR severidade entre seus pares. `InformacaoInsuficiente` fica
     * ACIMA de `ParcialmenteCoberto`/`RecebidoAguardandoDisponibilizacao`
     * (nunca dominado por um par "coberto" — "não sei" nunca vira
     * "tudo bem" só porque outro par está OK) mas ABAIXO de
     * `AguardandoCompra`/`CompradoAguardandoRecebimento` (fatos mais
     * claramente acionáveis vencem um "não sei").
     */
    public function severidade(): int
    {
        return match ($this) {
            self::SemCobertura => 100,
            self::DeficitAposConsumoEmergencial => 90,
            self::AguardandoCompra => 70,
            self::CompradoAguardandoRecebimento => 60,
            self::InformacaoInsuficiente => 55,
            self::ParcialmenteCoberto => 50,
            self::RecebidoAguardandoDisponibilizacao => 20,
            self::Coberto => 0,
        };
    }
}
