<?php

namespace App\Exceptions;

/**
 * Ciclo 18, Etapa 18.5.9 — tentativa de invalidar um aceite que já está
 * invalidado (ou corrida concorrente de 2 invalidações simultâneas —
 * `InvalidarAceiteEntrega` usa um `update()` condicional atômico
 * `WHERE invalidado_em IS NULL`, então só a primeira tentativa afeta
 * alguma linha; a segunda cai aqui, nunca sobrescreve
 * invalidado_por/motivo já gravados).
 */
class GrdAceiteJaInvalidadoException extends \RuntimeException
{
}
