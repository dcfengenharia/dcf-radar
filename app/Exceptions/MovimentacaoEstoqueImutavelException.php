<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.1 — MovimentacaoEstoque é fato físico append-only
 * (mesmo padrão de RecebimentoPedido, Ciclo 19). Lançada por
 * App\Observers\MovimentacaoEstoqueObserver quando qualquer código tenta
 * update() ou delete() de instância sobre uma linha já criada.
 */
class MovimentacaoEstoqueImutavelException extends Exception
{
}
