<?php

namespace App\Enums;

/**
 * Etapa 3 (Seção 24) — estado de COMPROMISSO FÍSICO explícito de uma
 * necessidade: só existe compromisso real quando há `ReservaEstoque`
 * específica (Melhoria "Posto Operacional") — nunca "existe estoque
 * livre em algum lugar da obra" (Seção 23: "estoque livre não é
 * garantia"). Deliberadamente separado de `EstadoNecessidadeMaterialAtividade`
 * (que já mistura físico-disponível e físico-reservado numa única
 * taxonomia de 6 estados) — aqui a dimensão de COMPROMISSO é isolada e
 * nunca inferida a partir de estoque livre.
 */
enum EstadoCompromissoNecessidadeMaterial: string
{
    case NaoReservada = 'nao_reservada';
    case ReservadaParcialmente = 'reservada_parcialmente';
    case ReservadaIntegralmente = 'reservada_integralmente';

    public function label(): string
    {
        return match ($this) {
            self::NaoReservada => 'Não reservada',
            self::ReservadaParcialmente => 'Reservada parcialmente',
            self::ReservadaIntegralmente => 'Reservada integralmente',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::NaoReservada => 'secondary',
            self::ReservadaParcialmente => 'warning',
            self::ReservadaIntegralmente => 'success',
        };
    }
}
