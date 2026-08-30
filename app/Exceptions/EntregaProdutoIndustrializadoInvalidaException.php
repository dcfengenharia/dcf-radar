<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.5 — erro de validação de uma entrega de produto
 * fabricado: quantidade excede o saldo pronto no terceiro (produzido -
 * entregue), modalidade EntregaDiretaCampo sem Frente/retirante,
 * Local de destino inativo/inexistente.
 */
class EntregaProdutoIndustrializadoInvalidaException extends Exception
{
}
