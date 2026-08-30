<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.2 — validação de domínio de DestinacaoPlanejadaMaterial
 * que não é sobre saldo (cross-obra, Material/Frente inativos, quantidade
 * inválida). Mensagem sempre didática, nunca SQL/ID cru.
 */
class DestinacaoPlanejadaInvalidaException extends Exception
{
}
