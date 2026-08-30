<?php

namespace App\Enums;

/**
 * Ciclo 19, Etapa 19.4 — status DERIVADO de uma `RequisicaoCompraEtapa`
 * (nunca uma coluna persistida): Concluida (`data_realizada` != null),
 * Atrasada (sem `data_realizada` e `data_prevista` < hoje), Pendente
 * (sem `data_realizada` e `data_prevista` >= hoje). Ver
 * `App\Models\RequisicaoCompraEtapa::status()`.
 */
enum StatusEtapaRequisicaoCompra: string
{
    case Pendente = 'pendente';
    case Atrasada = 'atrasada';
    case Concluida = 'concluida';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Atrasada => 'Atrasada',
            self::Concluida => 'Concluída',
        };
    }
}
