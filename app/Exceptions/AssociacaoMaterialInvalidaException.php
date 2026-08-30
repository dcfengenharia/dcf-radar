<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — validação de negócio de
 * App\Actions\Estoque\AssociarMaterialAoItemTakeOff: Material de outro
 * tenant, ou Material inativo. Mensagem sempre didática.
 */
class AssociacaoMaterialInvalidaException extends Exception
{
}
