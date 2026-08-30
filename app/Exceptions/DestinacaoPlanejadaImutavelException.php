<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.2 — Seção 12/43: uma DestinacaoPlanejadaMaterial
 * não pode ser reduzida abaixo do que já está reservado (Ativa), nem
 * excluída enquanto houver qualquer ReservaEstoque (Ativa ou Liberada —
 * liberada continua sendo evidência histórica) vinculada a ela.
 */
class DestinacaoPlanejadaImutavelException extends Exception
{
}
