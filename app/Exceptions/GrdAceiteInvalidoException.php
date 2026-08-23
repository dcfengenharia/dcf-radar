<?php

namespace App\Exceptions;

/**
 * Ciclo 18, Etapa 18.5.9 — validação de domínio do aceite de entrega
 * (GRD ainda Rascunho, nome do recebedor vazio, assinatura ausente
 * quando o tipo exige, arquivo de assinatura inválido/grande demais).
 */
class GrdAceiteInvalidoException extends \RuntimeException
{
}
