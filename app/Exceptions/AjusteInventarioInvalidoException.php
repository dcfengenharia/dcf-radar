<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.7 — guards específicos de
 * App\Actions\Estoque\AprovarAjusteInventario: item ainda não contado,
 * divergência zero, item já ajustado, item de serial inesperado sem
 * UnidadeEstoque resolvida, justificativa ausente/curta, saldo físico
 * insuficiente pra um ajuste negativo (revalidado fresco sob lock —
 * Seção 22).
 */
class AjusteInventarioInvalidoException extends Exception
{
}
