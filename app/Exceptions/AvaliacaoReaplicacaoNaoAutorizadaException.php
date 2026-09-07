<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.5.B.CORREÇÃO (Seção 1) — write-path seguro por
 * construção: `App\Actions\LicoesAprendidas\AvaliarReaplicacaoLicao`
 * SEMPRE revalida, internamente, que o ator explícito recebido
 * (`User $usuario`, nunca `Auth::user()` implícito) possui
 * `gestao.licoes-aprendidas|editar` na obra de destino da reaplicação,
 * mesmo que o chamador já tenha checado a mesma coisa antes só para UX.
 */
class AvaliacaoReaplicacaoNaoAutorizadaException extends Exception
{
}
