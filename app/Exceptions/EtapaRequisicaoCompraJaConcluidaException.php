<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.4.CORREÇÃO, item 12 — uma `RequisicaoCompraEtapa`
 * com `data_realizada` já preenchida é imutável: nenhuma nova tentativa
 * de conclusão pode sobrescrever `data_realizada`/`realizada_por`.
 * Reabertura/correção histórica é feature futura, fora de escopo.
 */
class EtapaRequisicaoCompraJaConcluidaException extends \RuntimeException
{
}
