<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.5 — erro de validação de um consumo de matéria-prima
 * (genealogia N:N): over-consumo em relação à quantidade da própria
 * RemessaIndustrializacao (Seção 27/28), remessa de direção errada
 * (só Envio pode ser consumida, nunca RetornoSobra), Produto/Remessa
 * de Ordens diferentes.
 */
class ConsumoIndustrializacaoInvalidaException extends Exception
{
}
