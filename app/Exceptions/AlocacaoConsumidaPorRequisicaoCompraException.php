<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.4, seções 44-46 — lançada quando uma tentativa de
 * reduzir/remover uma `AlocacaoRequisicaoPacote` conflitaria com
 * quantidade já consumida por `RequisicaoCompraItem` (integridade da
 * cadeia RP → Pacote → RC).
 */
class AlocacaoConsumidaPorRequisicaoCompraException extends \RuntimeException
{
}
