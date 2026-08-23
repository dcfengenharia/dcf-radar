<?php

namespace App\Exceptions;

use App\Models\InconsistenciaAvanco;

/**
 * Ciclo 17, A.9.6 — lançada por TratarInconsistenciaAvanco quando a
 * atualização condicional (`WHERE status = 'aberta'`) não afeta nenhuma
 * linha: outra requisição já tratou esta ocorrência entre a leitura do
 * usuário e o clique em "Confirmar tratamento". Nunca sobrescreve
 * silenciosamente o primeiro autor/justificativa — a UI traduz isso numa
 * mensagem amigável, nunca uma exceção crua.
 */
class InconsistenciaJaTratadaException extends \RuntimeException
{
    public function __construct(public readonly InconsistenciaAvanco $inconsistencia)
    {
        parent::__construct('Esta inconsistência já foi tratada por outro usuário.');
    }
}
