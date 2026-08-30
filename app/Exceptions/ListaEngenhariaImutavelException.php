<?php

namespace App\Exceptions;

/**
 * Ciclo 19, Etapa 19.1.HARDENING — lançada quando qualquer mutação
 * (criar/editar/excluir Lista ou Item) é tentada sobre uma
 * `ListaEngenharia` cuja revisão não é mais a vigente do Documento, OU
 * quando `delete()`/`forceDelete()` é tentado sobre a Lista em si
 * (Lista nunca é excluída, vigente ou não — é evidência documental).
 *
 * Mensagem sempre didática — nunca expõe SQL/FK cru pro usuário final.
 */
class ListaEngenhariaImutavelException extends \RuntimeException
{
}
