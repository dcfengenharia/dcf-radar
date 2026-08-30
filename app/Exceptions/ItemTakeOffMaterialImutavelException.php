<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.1 — decisão do usuário (investigação, Seção 24):
 * uma vez que ItemTakeOff.material_id foi associado E utilizado por
 * pelo menos uma MovimentacaoEstoque, a associação nunca pode ser
 * reescrita. Lançada por App\Observers\ItemTakeOffObserver.
 */
class ItemTakeOffMaterialImutavelException extends Exception
{
}
