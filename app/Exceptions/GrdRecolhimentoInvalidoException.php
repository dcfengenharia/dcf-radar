<?php

namespace App\Exceptions;

/**
 * Ciclo 18, Etapa 18.5.1 — lançada por
 * App\Actions\Engenharia\RegistrarRecolhimento quando o recolhimento não
 * pode ser registrado: GRD ainda em Rascunho, quantidade inválida, ou
 * quantidade Recolhido que faria a soma acumulada superar a quantidade
 * entregue (grd_distribuicoes.quantidade).
 */
class GrdRecolhimentoInvalidoException extends \RuntimeException
{
}
