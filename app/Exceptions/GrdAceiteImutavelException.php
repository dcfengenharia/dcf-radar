<?php

namespace App\Exceptions;

/**
 * Ciclo 18, Etapa 18.5.9.CORREÇÃO — lançada quando `delete()`/`forceDelete()`
 * é tentado sobre um `GrdAceiteEntrega` (ativo OU já invalidado). Registros
 * de aceite são evidência histórica — invalidar (`App\Actions\Engenharia\
 * InvalidarAceiteEntrega`) é o ÚNICO mecanismo de correção; exclusão física
 * nunca é permitida, nem mesmo depois de invalidado.
 */
class GrdAceiteImutavelException extends \RuntimeException
{
}
