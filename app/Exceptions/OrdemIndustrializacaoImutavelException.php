<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.5 — a OrdemIndustrializacao já deixou de ser
 * Rascunho — fornecedor/local terceiro/pacote/produtos previstos ficam
 * congelados; excluir a Ordem também é bloqueado.
 */
class OrdemIndustrializacaoImutavelException extends Exception
{
}
